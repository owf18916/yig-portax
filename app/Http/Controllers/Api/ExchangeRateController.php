<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Currency;
use Illuminate\Http\Request;

class ExchangeRateController extends ApiController
{
    /**
     * Get all active currencies with their exchange rates
     */
    public function index(Request $request)
    {
        try {
            $currencies = Currency::where('is_active', true)
                ->orderBy('code')
                ->get()
                ->map(function ($currency) {
                    return [
                        'id' => $currency->id,
                        'code' => $currency->code,
                        'name' => $currency->name,
                        'symbol' => $currency->symbol,
                        'exchange_rate' => $currency->exchange_rate,
                        'last_updated_at' => $currency->last_updated_at,
                        'decimal_places' => $currency->decimal_places,
                    ];
                });

            return $this->success(
                $currencies,
                'Exchange rates retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to retrieve exchange rates',
                500
            );
        }
    }

    /**
     * Update exchange rates for multiple currencies
     * This replaces existing rates (no history maintained)
     */
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'rates' => 'required|array|min:1',
                'rates.*.currency_id' => 'required|exists:currencies,id',
                'rates.*.exchange_rate' => 'required|numeric|min:0.01',
            ]);

            $updated = [];

            foreach ($validated['rates'] as $rateData) {
                $currency = Currency::findOrFail($rateData['currency_id']);
                
                $oldRate = $currency->exchange_rate;
                $currency->update([
                    'exchange_rate' => $rateData['exchange_rate'],
                    'last_updated_at' => now(),
                ]);

                $updated[] = [
                    'id' => $currency->id,
                    'code' => $currency->code,
                    'name' => $currency->name,
                    'exchange_rate' => $currency->exchange_rate,
                    'previous_rate' => $oldRate,
                    'last_updated_at' => $currency->last_updated_at,
                ];
            }

            return $this->success(
                $updated,
                'Exchange rates updated successfully'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error(
                'Failed to update exchange rates',
                500
            );
        }
    }

    /**
     * Get single currency exchange rate
     */
    public function show(Currency $currency)
    {
        try {
            return $this->success(
                [
                    'id' => $currency->id,
                    'code' => $currency->code,
                    'name' => $currency->name,
                    'symbol' => $currency->symbol,
                    'exchange_rate' => $currency->exchange_rate,
                    'last_updated_at' => $currency->last_updated_at,
                    'decimal_places' => $currency->decimal_places,
                ],
                'Exchange rate retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error(
                'Failed to retrieve exchange rate',
                500
            );
        }
    }
}
