<?php

namespace App\Services;

use App\Models\KianSubmission;
use App\Models\TaxCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

class KianLifecycle
{
    public function availableActions(KianSubmission $kian, TaxCase $case): array
    {
        return ['record_response' => false, 'close' => false];
    }

    private function retired(int $caseId, int $id): never
    {
        $case = TaxCase::findOrFail($caseId);
        Gate::authorize('recordKianResponse', $case);
        KianSubmission::where('tax_case_id', $caseId)->whereKey($id)->firstOrFail();
        abort(410, 'KIAN response and closure actions are retired. Submission completes the internal KIAN process.');
    }

    public function record(int $caseId, int $id, array $data, ?UploadedFile $file): KianSubmission
    {
        $this->retired($caseId, $id);
    }

    public function close(int $caseId, int $id): KianSubmission
    {
        $this->retired($caseId, $id);
    }
}
