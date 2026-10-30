<?php

namespace App\Services;

use App\Models\TaxCase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SptFilingContract
{
    public const METHODS = ['Restitusi', 'Kompensasi', 'Pengembalian Pendahuluan'];

    /** Omitted fields preserve storage; explicit null clears only the method. */
    public function validate(array $values, ?TaxCase $case = null): void
    {
        Validator::make($values, [
            'spt_position_type' => 'sometimes|required|in:LB,KB,NIHIL',
            'spt_type' => 'sometimes|nullable|string|max:50',
        ])->validate();

        $method = array_key_exists('spt_type', $values) ? $values['spt_type'] : $case?->spt_type;
        $position = $values['spt_position_type'] ?? $case?->spt_position_type;
        $type = $case?->case_type ?? ($values['case_type'] ?? null);
        $methodChanged = array_key_exists('spt_type', $values) && (!$case || $method !== $case->spt_type);
        $positionChanged = array_key_exists('spt_position_type', $values) && (!$case || $position !== $case->spt_position_type);

        // Unchanged historical values (including contradictory preliminary records)
        // remain readable and savable. Never infer or rewrite their meaning.
        if ($methodChanged && $method !== null && !in_array($method, self::METHODS, true)) {
            // Compatibility for existing generic create clients; no UI offers these.
            if ($case || !in_array($method, ['SPT', 'Kurang Bayar'], true)) {
                throw ValidationException::withMessages(['spt_type' => 'Pilih jenis pengajuan lebih bayar yang tersedia. Nilai historis hanya dapat dipertahankan.']);
            }
        }
        if (($methodChanged || $positionChanged) && in_array($method, self::METHODS, true)) {
            if ($position !== 'LB' || ($method === 'Pengembalian Pendahuluan' && $type !== 'VAT')) {
                throw ValidationException::withMessages(['spt_type' => 'Jenis pengajuan hanya untuk LB. Pengembalian Pendahuluan hanya untuk VAT dengan posisi LB.']);
            }
        }
    }
}
