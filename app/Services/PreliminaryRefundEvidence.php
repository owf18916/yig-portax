<?php

namespace App\Services;

use App\Models\Document;
use App\Models\PreliminaryRefundRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PreliminaryRefundEvidence
{
    // File processing occurs before acquiring database locks. Only the owner
    // transaction may activate metadata. A retry never removes the original file.
    public function record(UploadedFile $file, int $caseId, callable $operation): PreliminaryRefundRequest
    {
        validator(['document' => $file], ['document' => 'required|file|extensions:pdf|mimetypes:application/pdf|max:10240'])->validate();
        if ((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) !== 'application/pdf') {
            throw \Illuminate\Validation\ValidationException::withMessages(['document' => 'The document content must be a PDF.']);
        }
        $metadata = [
            'original_filename' => Str::limit(preg_replace('/[\x00-\x1F\x7F";]/', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 240, ''),
            'file_mime_type' => 'application/pdf', 'file_size' => $file->getSize(),
            'hash' => hash_file('sha256', $file->getRealPath()),
        ];
        $path = 'tax_cases/'.$caseId.'/preliminary/'.Str::uuid().'.pdf';
        $retained = false;
        try {
            if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                throw new \RuntimeException('Unable to persist preliminary evidence.');
            }
            [$request, $retained] = $operation($metadata + ['file_path' => $path]);

            return $request;
        } finally {
            if (! $retained && $this->unreferenced($path, $caseId) && ! Storage::disk('local')->delete($path)) {
                // Private unreferenced residue is never active evidence. Surface
                // cleanup failures for operations; never delete another command's file.
                report(new \RuntimeException('Preliminary evidence cleanup failed: '.$path));
            }
        }
    }

    private function unreferenced(string $path, int $caseId): bool
    {
        try {
            // A failed commit acknowledgement must not delete a file whose
            // metadata actually committed. Use the writer, never a read replica.
            return ! Document::withTrashed()->useWritePdo()->where('tax_case_id', $caseId)->where('file_path', $path)->exists();
        } catch (\Throwable $failure) {
            report($failure);

            // On an ambiguous database result, retaining a private file is safer
            // than erasing potentially committed evidence. Operations reconcile it.
            return false;
        }
    }

    public function attach(PreliminaryRefundRequest $request, string $role, array $metadata): Document
    {
        return Document::create($metadata + [
            'tax_case_id' => $request->tax_case_id, 'documentable_type' => PreliminaryRefundRequest::class,
            'documentable_id' => $request->id, 'document_type' => 'preliminary_'.$role,
            'stage_code' => '0', 'uploaded_by' => auth()->id(), 'uploaded_at' => now(), 'status' => 'ACTIVE',
        ]);
    }

    public function matches(?Document $document, PreliminaryRefundRequest $request, string $role, ?array $metadata = null): bool
    {
        return $document && ! $document->trashed() && $document->status === 'ACTIVE'
            && $document->documentable_type === PreliminaryRefundRequest::class
            && (int) $document->documentable_id === $request->id
            && (int) $document->tax_case_id === (int) $request->tax_case_id
            && $document->document_type === 'preliminary_'.$role
            && Storage::disk('local')->exists($document->file_path)
            && (! $metadata || ($document->hash === $metadata['hash'] && $document->original_filename === $metadata['original_filename']));
    }
}
