<?php

namespace App\Services\TaxWorkflowMatrix;

class StageDefinitions
{
    public const STAGES = [
        1 => ['key' => 'spt', 'label' => 'SPT Filing', 'route' => 'SptFilingForm', 'relation' => null],
        2 => ['key' => 'sp2', 'label' => 'SP2 Audit Notice', 'route' => 'Sp2FilingForm', 'relation' => 'sp2Record'],
        3 => ['key' => 'sphp', 'label' => 'SPHP Audit Findings', 'route' => 'SphpFilingForm', 'relation' => 'sphpRecord'],
        4 => ['key' => 'skp', 'label' => 'SKP Assessment', 'route' => 'SkpFilingForm', 'relation' => 'skpRecord'],
        5 => ['key' => 'objection_submission', 'label' => 'Objection Submission', 'route' => 'ObjectionSubmissionForm', 'relation' => 'objectionSubmission'],
        6 => ['key' => 'spuh', 'label' => 'SPUH Record', 'route' => 'SpuhRecordForm', 'relation' => 'spuhRecord'],
        7 => ['key' => 'objection_decision', 'label' => 'Objection Decision', 'route' => 'ObjectionDecisionForm', 'relation' => 'objectionDecision'],
        8 => ['key' => 'appeal_submission', 'label' => 'Appeal Submission', 'route' => 'AppealSubmissionForm', 'relation' => 'appealSubmission'],
        9 => ['key' => 'appeal_explanation', 'label' => 'Appeal Explanation Request', 'route' => 'AppealExplanationRequestForm', 'relation' => 'appealExplanationRequest'],
        10 => ['key' => 'appeal_decision', 'label' => 'Appeal Decision', 'route' => 'AppealDecisionForm', 'relation' => 'appealDecision'],
        11 => ['key' => 'supreme_court_submission', 'label' => 'Supreme Court Submission', 'route' => 'SupremeCourtSubmissionForm', 'relation' => 'supremeCourtSubmission'],
        12 => ['key' => 'supreme_court_decision', 'label' => 'Supreme Court Decision', 'route' => 'SupremeCourtDecisionForm', 'relation' => 'supremeCourtDecision'],
    ];

    public static function columns(): array
    {
        return collect(self::STAGES)
            ->map(fn (array $stage, int $id) => ['key' => $stage['key'], 'label' => $stage['label'], 'stage_id' => $id])
            ->values()
            ->all();
    }

    public static function byKey(): array
    {
        return collect(self::STAGES)->keyBy('key')->all();
    }
}
