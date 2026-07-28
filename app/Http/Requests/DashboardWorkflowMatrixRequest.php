<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DashboardWorkflowMatrixRequest extends FormRequest
{
    private const HISTORICAL_FLOOR = '2013-03';
    private const BOOLEAN_VALUES = [
        true => true,
        false => false,
        1 => true,
        0 => false,
        '1' => true,
        '0' => false,
        'true' => true,
        'false' => false,
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'tax_category' => ['required', Rule::in(['CIT', 'VAT'])],
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'from_period' => ['nullable', 'date_format:Y-m'],
            'to_period' => ['nullable', 'date_format:Y-m'],
            'entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'status' => ['nullable', 'string'],
            'include_completed' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50])],
            'sort' => ['nullable', Rule::in(['period_desc'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->hasIncludeCompletedInput()) {
            $value = $this->input('include_completed');

            if (array_key_exists($value, self::BOOLEAN_VALUES)) {
                $normalized['include_completed'] = self::BOOLEAN_VALUES[$value];
            }
        }

        $this->merge($normalized);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $category = $this->input('tax_category');

            if ($this->hasIncludeCompletedInput() && $this->input('include_completed') === null) {
                $validator->errors()->add('include_completed', 'The include completed field must be true or false.');
            }

            if ($category === 'CIT' && ($this->filled('from_period') || $this->filled('to_period'))) {
                $validator->errors()->add('from_period', 'Period range filters are only available for VAT.');
            }

            if ($category === 'VAT' && $this->filled('fiscal_year_id')) {
                $validator->errors()->add('fiscal_year_id', 'Fiscal year filter is only available for CIT.');
            }

            if ($category === 'VAT' && ($this->filled('from_period') xor $this->filled('to_period'))) {
                $validator->errors()->add('from_period', 'From period and to period are both required for VAT ranges.');
            }

            foreach (['from_period', 'to_period'] as $field) {
                if ($this->filled($field) && $this->input($field) < self::HISTORICAL_FLOOR) {
                    $validator->errors()->add($field, 'Periods before March 2013 are not supported.');
                }
            }

            if ($category === 'VAT' && $this->filled('from_period') && $this->filled('to_period')) {
                if ($this->input('from_period') > $this->input('to_period')) {
                    $validator->errors()->add('from_period', 'The from period must be before or equal to the to period.');
                }

                if ($this->monthsBetween($this->input('from_period'), $this->input('to_period')) > 24) {
                    $validator->errors()->add('to_period', 'VAT ranges may not exceed 24 months.');
                }
            }
        });
    }

    public function filters(): array
    {
        return [
            'tax_category' => $this->input('tax_category'),
            'fiscal_year_id' => $this->integer('fiscal_year_id') ?: null,
            'from_period' => $this->input('from_period'),
            'to_period' => $this->input('to_period'),
            'entity_id' => $this->integer('entity_id') ?: null,
            'status' => $this->input('status'),
            'include_completed' => $this->boolean('include_completed', true),
            'page' => $this->integer('page') ?: 1,
            'per_page' => $this->integer('per_page') ?: 20,
            'sort' => $this->input('sort', 'period_desc'),
        ];
    }

    private function monthsBetween(string $from, string $to): int
    {
        [$fromYear, $fromMonth] = array_map('intval', explode('-', $from));
        [$toYear, $toMonth] = array_map('intval', explode('-', $to));

        return (($toYear - $fromYear) * 12) + ($toMonth - $fromMonth) + 1;
    }

    private function hasIncludeCompletedInput(): bool
    {
        return $this->query->has('include_completed') || $this->request->has('include_completed');
    }
}
