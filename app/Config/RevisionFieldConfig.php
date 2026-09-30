<?php

namespace App\Config;

use App\Models\{AppealDecision, AppealExplanationRequest, AppealSubmission, ObjectionDecision, ObjectionSubmission, SkpRecord, SphpRecord, Sp2Record, SpuhRecord, SupremeCourtDecision, SupremeCourtSubmission, TaxCase};

/** Canonical, server-owned Revision contract. */
class RevisionFieldConfig
{
    private const CONTRACTS = [
        1 => [TaxCase::class, null, ['spt_number'=>'nullable|string|max:255','spt_type'=>'nullable|string|max:255','filing_date'=>'nullable|date','received_date'=>'nullable|date','reported_amount'=>'nullable|numeric|min:0','disputed_amount'=>'nullable|numeric|min:0','vat_in_amount'=>'nullable|numeric|min:0','vat_out_amount'=>'nullable|numeric|min:0','description'=>'nullable|string|max:5000']],
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

    public static function contract(int $stage): ?array
    {
        if (!isset(self::CONTRACTS[$stage])) return null;
        [$class, $relation, $rules] = self::CONTRACTS[$stage];
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
