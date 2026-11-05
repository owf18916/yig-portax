<?php

namespace App\Services;

use App\Models\Document;
use App\Models\KianSubmission;
use App\Models\TaxCase;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KianLifecycle
{
    private function check(bool $condition, string $message): void
    {
        if (! $condition) throw ValidationException::withMessages(['kian' => $message]);
    }

    private function money(mixed $value): string
    {
        $this->check((is_string($value) || is_int($value)) && preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', (string) $value), 'Use a nonnegative exact decimal with at most two decimal places.');
        return (string) BigDecimal::of($value)->toScale(2);
    }

    private function ordinaryCase(TaxCase $case): bool
    {
        // Preliminary middleware owns an outer administrative transaction. KIAN
        // evidence must never commit inside that unrelated lifecycle boundary.
        return $case->spt_type !== 'Pengembalian Pendahuluan'
            && ! $case->refundProcesses()->withTrashed()->where(fn ($q) => $q->where('stage_id', 0)->orWhere('stage_source', 'PRELIMINARY'))->exists();
    }

    private function freshSubmission(KianSubmission $kian): bool
    {
        if ($kian->status !== 'submitted' || $kian->submitted_at === null || $kian->submitted_by === null) return false;
        foreach (KianSubmission::FINAL_FIELDS as $field) if ($kian->$field !== null) return false;
        return true;
    }

    public function availableActions(KianSubmission $kian, TaxCase $case): array
    {
        $allowed = Gate::allows('recordKianResponse', $case) && ! $case->is_completed && $case->status?->code !== 'CLOSED'
            && in_array((int) $kian->stage_id, [4, 7, 10, 12], true) && $this->ordinaryCase($case);
        return [
            'record_response' => $allowed && $this->freshSubmission($kian),
            'close' => $allowed && $kian->status === 'responded' && $kian->closed_at === null && $kian->closed_by === null && $this->complete($kian),
        ];
    }

    private function locked(int $caseId, int $id, callable $operation): KianSubmission
    {
        return DB::transaction(function () use ($caseId, $id, $operation) {
            $case = TaxCase::whereKey($caseId)->lockForUpdate()->firstOrFail();
            Gate::authorize('recordKianResponse', $case);
            $this->check($this->ordinaryCase($case), 'Preliminary administrative cases cannot record KIAN final evidence; use the preliminary lifecycle.');
            $kian = KianSubmission::where('tax_case_id', $case->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->check(in_array((int) $kian->stage_id, [4, 7, 10, 12], true), 'Historical KIAN stage is unresolved; read-only.');
            $this->check(! $case->is_completed && $case->status?->code !== 'CLOSED', 'The tax case is closed.');
            return $operation($kian);
        });
    }

    public function complete(KianSubmission $kian): bool
    {
        $document = $kian->responseDocument;
        return in_array($kian->response_status, ['ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED'], true)
            && filled($kian->response_reference) && $kian->response_issued_date && $kian->response_received_date
            && $kian->responded_by && $kian->responded_at && $document && ! $document->trashed()
            && $document->isKianResponseEvidence() && $document->status === 'ACTIVE'
            && (int) $document->documentable_id === $kian->id
            && (int) $document->tax_case_id === (int) $kian->tax_case_id
            && (string) $document->stage_code === (string) $kian->stage_id
            && $document->file_mime_type === 'application/pdf' && filled($document->hash)
            && Storage::disk('local')->exists($document->file_path)
            && ($kian->approved_amount === null || (BigDecimal::of($kian->approved_amount)->isGreaterThanOrEqualTo(0)
                && BigDecimal::of($kian->approved_amount)->isLessThanOrEqualTo($kian->kian_amount)));
    }

    public function record(int $caseId, int $id, array $data, ?UploadedFile $file): KianSubmission
    {
        $case = TaxCase::findOrFail($caseId);
        Gate::authorize('recordKianResponse', $case);
        $this->check($this->ordinaryCase($case), 'Preliminary administrative cases cannot record KIAN final evidence; use the preliminary lifecycle.');
        // Reject foreign children before processing or storing any file.
        KianSubmission::where('tax_case_id', $caseId)->whereKey($id)->firstOrFail();
        validator($data + ['document' => $file], [
            'response_status' => 'required|in:ACCEPTED,PARTIALLY_ACCEPTED,REJECTED',
            'response_reference' => 'required|string|max:255',
            'response_issued_date' => 'required|date_format:Y-m-d',
            'response_received_date' => 'required|date_format:Y-m-d',
            'response_notes' => 'nullable|string|max:5000',
            'document' => 'required|file|extensions:pdf|mimetypes:application/pdf|max:10240',
        ])->validate();
        $this->check((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) === 'application/pdf', 'The document content must be PDF.');
        $amount = isset($data['approved_amount']) ? $this->money($data['approved_amount']) : null;
        $metadata = [
            'original_filename' => Str::limit(preg_replace('/[\x00-\x1F\x7F";]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 240, ''),
            'file_size' => $file->getSize(), 'file_mime_type' => 'application/pdf',
            'hash' => hash_file('sha256', $file->getRealPath()),
        ];
        // Established private evidence pattern: stage unique file before DB locks;
        // activate its metadata atomically, compensate failures/replays afterwards.
        $path = 'tax_cases/'.$caseId.'/kian/'.$id.'/'.Str::uuid().'.pdf';
        try {
            if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                throw new \RuntimeException('Unable to persist KIAN evidence.');
            }
            $result = $this->locked($caseId, $id, function ($kian) use ($data, $amount, $metadata, $path) {
                $this->check($amount === null || BigDecimal::of($amount)->isLessThanOrEqualTo($kian->kian_amount), 'Recognized amount exceeds the submitted KIAN amount.');
                if (in_array($kian->status, ['responded', 'closed'], true)) {
                    $this->check($this->complete($kian), 'Recorded final evidence is incomplete; read-only.');
                    $same = $kian->approved_amount === $amount && $kian->response_notes === ($data['response_notes'] ?? null);
                    foreach (['response_status', 'response_reference'] as $field) $same = $same && $kian->$field === $data[$field];
                    foreach (['response_issued_date', 'response_received_date'] as $field) $same = $same && $kian->$field->toDateString() === $data[$field];
                    $same = $same && $kian->responseDocument->hash === $metadata['hash']
                        && $kian->responseDocument->original_filename === $metadata['original_filename']
                        && $kian->responseDocument->file_size === $metadata['file_size'];
                    $this->check($same, 'Conflicting final response retry; evidence is immutable.');
                    return $kian;
                }
                $this->check($this->freshSubmission($kian), 'Only a submitted KIAN without existing final evidence can receive a final response.');
                $document = Document::create($metadata + [
                    'file_path' => $path, 'tax_case_id' => $kian->tax_case_id,
                    'documentable_type' => KianSubmission::class, 'documentable_id' => $kian->id,
                    'stage_code' => (string) $kian->stage_id, 'document_type' => 'kian_final_response',
                    'uploaded_by' => auth()->id(), 'uploaded_at' => now(), 'status' => 'ACTIVE',
                ]);
                $kian->forceFill([
                    'status' => 'responded', 'response_status' => $data['response_status'], 'response_reference' => $data['response_reference'],
                    'response_issued_date' => $data['response_issued_date'], 'response_received_date' => $data['response_received_date'],
                    'approved_amount' => $amount, 'response_notes' => $data['response_notes'] ?? null,
                    'response_document_id' => $document->id, 'responded_by' => auth()->id(), 'responded_at' => now(),
                ])->save();
                return $kian;
            });
            return $result;
        } finally {
            // Check writer even when commit acknowledgement failed. Never erase a
            // potentially committed file if the DB cannot establish its ownership.
            try {
                $referenced = Document::withTrashed()->useWritePdo()->where('tax_case_id', $caseId)
                    ->where('documentable_type', KianSubmission::class)->where('documentable_id', $id)->where('file_path', $path)->exists();
                if (! $referenced && ! Storage::disk('local')->delete($path)) report(new \RuntimeException('KIAN private evidence cleanup failed.'));
            } catch (\Throwable $failure) {
                report($failure);
            }
        }
    }

    public function close(int $caseId, int $id): KianSubmission
    {
        return $this->locked($caseId, $id, function ($kian) {
            $this->check(in_array($kian->status, ['responded', 'closed'], true) && $this->complete($kian), 'Complete final response evidence is required before closure.');
            if ($kian->status === 'closed') {
                $this->check($kian->closed_by !== null && $kian->closed_at !== null, 'Historical closure is incomplete; read-only.');
                return $kian;
            }
            $this->check($kian->closed_by === null && $kian->closed_at === null, 'Conflicting closure evidence.');
            $kian->forceFill(['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now()])->save();
            return $kian;
        });
    }
}
