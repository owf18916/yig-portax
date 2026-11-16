<?php

namespace App\Config;

use App\Models\{AppealDecision, AppealExplanationRequest, AppealSubmission, ObjectionDecision, ObjectionSubmission, SkpRecord, SphpRecord, Sp2Record, SpuhRecord, SupremeCourtDecision, SupremeCourtSubmission, TaxCase};

/** Canonical, server-owned Revision contract. */
class RevisionFieldConfig
{
    private const CONTRACTS = [
        1 => [TaxCase::class, null, ['spt_position_type'=>'in:LB,KB,NIHIL','spt_number'=>'nullable|string|max:255','spt_type'=>'nullable|string|max:50','filing_date'=>'nullable|date','received_date'=>'nullable|date','reported_amount'=>'nullable|numeric|min:0','disputed_amount'=>'nullable|numeric|min:0','vat_in_amount'=>'nullable|numeric|min:0','vat_out_amount'=>'nullable|numeric|min:0','description'=>'nullable|string|max:5000']],
        2 => [Sp2Record::class, 'sp2Record', ['sp2_number'=>'nullable|string|max:255','issue_date'=>'nullable|date','receipt_date'=>'nullable|date','auditor_name'=>'nullable|string|max:255','auditor_position'=>'nullable|string|max:255','auditor_phone'=>'nullable|string|max:255','auditor_email'=>'nullable|email|max:255','notes'=>'nullable|string|max:5000']],
        3 => [SphpRecord::class, 'sphpRecord', ['sphp_number'=>'nullable|string|max:255','sphp_issue_date'=>'nullable|date','sphp_receipt_date'=>'nullable|date','royalty_finding'=>'nullable|numeric|min:0','service_finding'=>'nullable|numeric|min:0','other_finding'=>'nullable|numeric|min:0','other_finding_notes'=>'nullable|string|max:5000']],
        4 => [SkpRecord::class, 'skpRecord', ['skp_number'=>'nullable|string|max:255','issue_date'=>'nullable|date','receipt_date'=>'nullable|date','skp_due_date'=>'nullable|date','skp_type'=>'nullable|string|max:255','skp_amount'=>'nullable|numeric|min:0','royalty_correction'=>'nullable|numeric|min:0','service_correction'=>'nullable|numeric|min:0','other_correction'=>'nullable|numeric|min:0','correction_notes'=>'nullable|string|max:5000','create_refund'=>'boolean','refund_amount'=>'nullable|numeric|min:0','continue_to_next_stage'=>'boolean']],
        5 => [ObjectionSubmission::class, 'objectionSubmission', ['objection_number'=>'nullable|string|max:255','submission_date'=>'nullable|date','objection_amount'=>'nullable|numeric|min:0','objection_grounds'=>'nullable|string|max:5000','supporting_evidence'=>'nullable|string|max:5000','notes'=>'nullable|string|max:5000']],
        6 => [SpuhRecord::class, 'spuhRecord', ['spuh_number'=>'nullable|string|max:255','issue_date'=>'nullable|date','receipt_date'=>'nullable|date','reply_number'=>'nullable|string|max:255','reply_date'=>'nullable|date','notes'=>'nullable|string|max:5000']],
        7 => [ObjectionDecision::class, 'objectionDecision', ['decision_number'=>'nullable|string|max:255','decision_date'=>'nullable|date','decision_type'=>'nullable|string|max:255','decision_amount'=>'nullable|numeric|min:0','decision_notes'=>'nullable|string|max:5000','create_refund'=>'boolean','refund_amount'=>'nullable|numeric|min:0','continue_to_next_stage'=>'boolean']],
        8 => [AppealSubmission::class, 'appealSubmission', ['appeal_number'=>'nullable|string|max:255','dispute_number'=>'nullable|string|max:255','submission_date'=>'nullable|date','appeal_amount'=>'nullable|numeric|min:0','appeal_grounds'=>'nullable|string|max:5000','notes'=>'nullable|string|max:5000']],
        9 => [AppealExplanationRequest::class, 'appealExplanationRequest', ['request_number'=>'nullable|string|max:255','request_issue_date'=>'nullable|date','request_receipt_date'=>'nullable|date','explanation_letter_number'=>'nullable|string|max:255','explanation_submission_date'=>'nullable|date','notes'=>'nullable|string|max:5000']],
        10 => [AppealDecision::class, 'appealDecision', ['decision_number'=>'nullable|string|max:255','decision_date'=>'nullable|date','decision_type'=>'nullable|string|max:255','decision_amount'=>'nullable|numeric|min:0','decision_notes'=>'nullable|string|max:5000','create_refund'=>'boolean','refund_amount'=>'nullable|numeric|min:0','continue_to_next_stage'=>'boolean']],
        11 => [SupremeCourtSubmission::class, 'supremeCourtSubmission', ['submission_number'=>'nullable|string|max:255','submission_date'=>'nullable|date','submission_amount'=>'nullable|numeric|min:0','supreme_court_letter_number'=>'nullable|string|max:255','review_amount'=>'nullable|numeric|min:0','notes'=>'nullable|string|max:5000']],
        12 => [SupremeCourtDecision::class, 'supremeCourtDecision', ['decision_number'=>'nullable|string|max:255','decision_date'=>'nullable|date','decision_type'=>'nullable|string|max:255','decision_amount'=>'nullable|numeric|min:0','decision_notes'=>'nullable|string|max:5000','create_refund'=>'boolean','refund_amount'=>'nullable|numeric|min:0']],
    ];

    // Non-null storage and final-business requirements, applied only when a
    // field is selected. Omission still preserves the saved value.
    private const REQUIRED_VALUES = [
        1 => ['spt_position_type', 'reported_amount', 'disputed_amount'],
        3 => ['sphp_number', 'sphp_issue_date'],
        4 => ['skp_number', 'issue_date', 'skp_type', 'skp_amount'],
        5 => ['objection_number', 'submission_date', 'objection_amount'],
        6 => ['spuh_number', 'issue_date', 'receipt_date'],
        7 => ['decision_number', 'decision_date', 'decision_type', 'decision_amount'],
        8 => ['appeal_number', 'submission_date', 'appeal_amount'],
        10 => ['decision_number', 'decision_date', 'decision_type', 'decision_amount'],
        11 => ['submission_number', 'submission_date', 'submission_amount'],
        12 => ['decision_number', 'decision_date', 'decision_type', 'decision_amount'],
    ];

    public static function contract(int $stage): ?array
    {
        if (!isset(self::CONTRACTS[$stage])) return null;
        [$class, $relation, $rules] = self::CONTRACTS[$stage];
        foreach ($rules as $field => &$rule) {
            if ($rule === 'boolean') $rule = 'required|boolean';
            if (in_array($field, self::REQUIRED_VALUES[$stage] ?? [], true)) {
                $rule = 'required|'.str_replace('nullable|', '', $rule);
            }
            if (str_contains($rule, 'numeric')) $rule .= '|regex:/^\d{1,18}(?:\.\d{1,2})?$/D';
        }
        unset($rule);
        if ($stage === 4) $rules['skp_type'] .= '|in:LB,KB,NIHIL';
        if (in_array($stage, [7, 10, 12], true)) $rules['decision_type'] .= '|in:granted,partially_granted,rejected'.($stage === 10 ? ',skp_kb' : '');
        return compact('class', 'relation', 'rules') + ['fields' => array_keys($rules)];
    }

    public static function getAvailableFields(string|int $model): array
    {
        if (is_numeric($model)) return self::contract((int)$model)['fields'] ?? [];
        foreach (self::CONTRACTS as $contract) if (class_basename($contract[0]) === $model) return array_keys($contract[2]);
        return [];
    }

    public static function getFieldLabel(string $modelType, string $fieldName): string { return ucwords(str_replace('_', ' ', $fieldName)); }
    public static function getFieldLabels(string $modelType): array { return collect(self::getAvailableFields($modelType))->mapWithKeys(fn($f)=>[$f=>self::getFieldLabel($modelType,$f)])->all(); }
    public static function getDocumentFields(string $modelType): array { return ['supporting_docs']; }
}
