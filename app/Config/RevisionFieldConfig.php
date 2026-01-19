<?php

namespace App\Config;

/**
 * Field configuration for revisions across different models
 * Centralized place to manage field labels and rules
 */
class RevisionFieldConfig
{
    /**
     * Get field labels for a specific model type
     */
    public static function getFieldLabels(string $modelType): array
    {
        $labelMaps = [
            'TaxCase' => [
                'period_id' => 'Tax Period',
                'currency_id' => 'Currency',
                'disputed_amount' => 'Disputed Amount',
                'supporting_docs' => 'Supporting Documents',
            ],
            'SKP' => [
                'skp_number' => 'SKP Number',
                'issue_date' => 'Issue Date',
                'receipt_date' => 'Receipt Date',
                'skp_type' => 'SKP Type',
                'skp_amount' => 'SKP Amount',
                'royalty_correction' => 'Royalty Correction',
                'service_correction' => 'Service Correction',
                'other_correction' => 'Other Correction',
                'correction_notes' => 'Correction Notes',
                'user_routing_choice' => 'Routing Choice (Refund/Objection)',
            ],
            'SPHP' => [
                'sphp_number' => 'SPHP Number',
                'period_date' => 'Period Date',
                'amount' => 'Amount',
                'documents' => 'Documents',
            ],
            'ObjectionSubmission' => [
                'objection_reason' => 'Objection Reason',
                'amount_objected' => 'Amount Objected',
                'supporting_docs' => 'Supporting Documents',
            ],
        ];

        return $labelMaps[$modelType] ?? [];
    }

    /**
     * Get field label for a specific model and field
     */
    public static function getFieldLabel(string $modelType, string $fieldName): string
    {
        $labels = self::getFieldLabels($modelType);
        return $labels[$fieldName] ?? str_replace('_', ' ', ucfirst($fieldName));
    }

    /**
     * Get available fields for revision per model type
     */
    public static function getAvailableFields(string $modelType): array
    {
        $availableFields = [
            'TaxCase' => [
                'period_id',
                'currency_id',
                'disputed_amount',
                'supporting_docs',
            ],
            'SKP' => [
                'skp_number',
                'issue_date',
                'receipt_date',
                'skp_type',
                'skp_amount',
                'royalty_correction',
                'service_correction',
                'other_correction',
                'correction_notes',
                'user_routing_choice',
            ],
            'SPHP' => [
                'sphp_number',
                'period_date',
                'amount',
                'documents',
            ],
            'ObjectionSubmission' => [
                'objection_reason',
                'amount_objected',
                'supporting_docs',
            ],
        ];

        return $availableFields[$modelType] ?? [];
    }

    /**
     * Get document field names for a model type
     */
    public static function getDocumentFields(string $modelType): array
    {
        $docFields = [
            'TaxCase' => ['supporting_docs'],
            'SKP' => ['attachments'],
            'SPHP' => ['documents'],
            'ObjectionSubmission' => ['supporting_docs'],
        ];

        return $docFields[$modelType] ?? [];
    }
}
