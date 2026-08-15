<?php

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $organizationId) {
                if (DB::table('organization_currency_configurations')
                    ->where('organization_id', $organizationId)
                    ->exists()) {
                    continue;
                }

                if (DB::table('organization_allowed_currencies')
                    ->where('organization_id', $organizationId)
                    ->exists()) {
                    throw new RuntimeException(sprintf(
                        'Finance currency bootstrap found partial configuration for organization %s.',
                        $organizationId,
                    ));
                }

                $currencies = [];

                foreach (DB::table('services')
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('price_currency')
                    ->distinct()
                    ->pluck('price_currency') as $value) {
                    $currency = CurrencyCode::tryFrom(strtoupper(trim((string) $value)));

                    if ($currency === null) {
                        throw new RuntimeException(sprintf(
                            'Finance currency bootstrap found unsupported service currency "%s" for organization %s.',
                            $value,
                            $organizationId,
                        ));
                    }

                    if (! in_array($currency, $currencies, true)) {
                        $currencies[] = $currency;
                    }
                }

                if ($currencies === []) {
                    continue;
                }

                if (count($currencies) > 1) {
                    throw new RuntimeException(sprintf(
                        'Finance currency bootstrap requires an owner-selected base currency for organization %s because priced services use multiple currencies.',
                        $organizationId,
                    ));
                }

                $currency = $currencies[0]->value;
                $timestamp = now();
                DB::table('organization_currency_configurations')->insert([
                    'organization_id' => $organizationId,
                    'base_currency' => $currency,
                    'display_currency' => $currency,
                    'force_single_currency' => true,
                    'rounding_mode' => 'half_up',
                    'version' => 1,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                DB::table('organization_allowed_currencies')->insert([
                    'organization_id' => $organizationId,
                    'currency' => $currency,
                    'created_at' => $timestamp,
                ]);
            }
        });
    }

    public function down(): void {}
};
