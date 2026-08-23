<?php

namespace Tests\Feature\Unit\AI;

use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiPricingTier;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingPricingPolicy;
use InvalidArgumentException;
use Tests\TestCase;

final class AiExactPricingTest extends TestCase
{
    public function test_sub_cent_fractional_and_cache_rates_are_calculated_exactly(): void
    {
        self::assertSame(3_625, AiMoney::rateUnitsFromPerTokenDecimal('0.000000003625'));
        self::assertSame('0.075000', AiMoney::decimalFromRateUnits(AiMoney::rateUnitsFromDecimal('0.075')));

        $pricing = AiPricingSnapshot::fromArray([
            'currency' => 'USD',
            'input_price_per_million' => '0.075',
            'output_price_per_million' => '2.50',
            'cache_read_input_price_per_million' => '0.000001',
            'cache_write_input_price_per_million' => '0.000002',
            'reasoning_price_per_million' => '0.125',
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => [],
            'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
            'catalog_source' => 'https://provider.example/pricing',
            'catalog_pricing_as_of' => '2026-08-22',
        ]);

        self::assertTrue($pricing->isComplete());
        self::assertSame('0.075000', $pricing->inputPricePerMillion());
        self::assertSame('2.500000', $pricing->outputPricePerMillion());
        self::assertSame(8, $pricing->calculateCostMinorUnits(1_000_000, 0));
        self::assertSame(25, $pricing->calculateCostMinorUnits(0, 100_000));
        self::assertSame(1, $pricing->calculateCostMinorUnits(0, 0, 1_000_000));
        self::assertSame(1, $pricing->calculateCostMinorUnits(0, 0, 0, 1_000_000));
    }

    public function test_unknown_billable_meter_fails_closed_instead_of_becoming_zero(): void
    {
        $pricing = AiPricingSnapshot::fromArray([
            'currency' => 'USD',
            'input_price_per_million' => '1.00',
            'output_price_per_million' => '2.00',
            'cache_read_input_price_per_million' => null,
            'cache_write_input_price_per_million' => null,
            'reasoning_price_per_million' => null,
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => ['image_tokens'],
            'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
        ]);

        self::assertFalse($pricing->isComplete());
        $this->expectException(InvalidArgumentException::class);
        $pricing->calculateCostMinorUnits(1, 0);
    }

    public function test_v2_compatibility_minor_units_round_up_without_changing_exact_rates(): void
    {
        $pricing = AiPricingSnapshot::fromArray([
            'currency' => 'USD',
            'pricing_schema_version' => 2,
            'rate_scale' => AiMoney::RATE_SCALE,
            'input_price_per_million' => '0.075',
            'output_price_per_million' => '0.028',
            'cache_read_input_price_per_million' => '0.0028',
            'cache_write_input_price_per_million' => '0',
            'reasoning_price_per_million' => '2.00',
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => [],
            'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
        ]);

        $serialized = $pricing->toArray();

        self::assertSame(75_000, $serialized['input_rate_per_million_units']);
        self::assertSame(8, $serialized['input_cost_per_million_minor_units']);
        self::assertSame(3, $serialized['output_cost_per_million_minor_units']);
        self::assertSame(1, $serialized['cache_read_input_cost_per_million_minor_units']);
        self::assertSame(0, $serialized['cache_write_input_cost_per_million_minor_units']);
        self::assertSame('0.075000', $pricing->inputPricePerMillion());
        self::assertSame('0.028000', $pricing->outputPricePerMillion());
    }

    public function test_canonical_v2_rate_wins_over_a_forged_compatibility_minor_value(): void
    {
        $pricing = AiPricingSnapshot::fromArray([
            'currency' => 'USD',
            'pricing_schema_version' => 2,
            'rate_scale' => AiMoney::RATE_SCALE,
            'input_rate_per_million_units' => 75_000,
            'output_rate_per_million_units' => 28_000,
            'input_cost_per_million_minor_units' => 0,
            'output_cost_per_million_minor_units' => 0,
            'cache_read_input_rate_per_million_units' => null,
            'cache_write_input_rate_per_million_units' => null,
            'reasoning_rate_per_million_units' => null,
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => [],
            'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
        ]);

        self::assertSame(8, $pricing->toArray()['input_cost_per_million_minor_units']);
        self::assertSame(3, $pricing->toArray()['output_cost_per_million_minor_units']);
    }

    public function test_unknown_pricing_schema_and_rate_scale_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AiPricingSnapshot::fromArray([
            'pricing_schema_version' => 3,
            'rate_scale' => AiMoney::RATE_SCALE,
            'input_price_per_million' => '1.00',
            'output_price_per_million' => '2.00',
        ]);
    }

    public function test_explicit_v1_snapshot_remains_legacy_minor_units(): void
    {
        $pricing = AiPricingSnapshot::fromArray([
            'pricing_schema_version' => 1,
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => 250,
            'output_cost_per_million_minor_units' => 1000,
            'cache_read_input_cost_per_million_minor_units' => 25,
            'cache_write_input_cost_per_million_minor_units' => 50,
            'reasoning_cost_per_million_minor_units' => 0,
            'fixed_request_cost_applicable' => false,
            'fixed_request_cost_minor_units' => 0,
            'unsupported_meters' => [],
            'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
        ]);

        self::assertSame('2.500000', $pricing->inputPricePerMillion());
        self::assertSame(250, $pricing->inputCostPerMillionMinorUnits);
        self::assertSame(2, $pricing->toArray()['pricing_schema_version']);
    }

    public function test_tier_boundary_is_exact_and_gaps_do_not_activate(): void
    {
        $pricing = AiPricingSnapshot::fromArray([
            'currency' => 'USD',
            'input_price_per_million' => '2.00',
            'output_price_per_million' => '4.00',
            'cache_read_input_price_per_million' => null,
            'cache_write_input_price_per_million' => null,
            'reasoning_price_per_million' => null,
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => [],
            'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
            'pricing_tiers' => [
                [
                    'minimum_input_tokens' => 0,
                    'maximum_input_tokens' => 199_999,
                    'input_price_per_million' => '2.00',
                    'output_price_per_million' => '4.00',
                ],
                [
                    'minimum_input_tokens' => 200_000,
                    'maximum_input_tokens' => null,
                    'input_price_per_million' => '4.00',
                    'output_price_per_million' => '8.00',
                ],
            ],
        ]);

        self::assertTrue($pricing->isComplete());
        self::assertSame(40, $pricing->calculateCostMinorUnits(199_999, 0));
        self::assertSame(80, $pricing->calculateCostMinorUnits(200_000, 0));

        $invalid = new AiPricingSnapshot(
            inputRatePerMillionUnits: AiMoney::rateUnitsFromDecimal('2.00'),
            outputRatePerMillionUnits: AiMoney::rateUnitsFromDecimal('4.00'),
            cacheReadRatePerMillionUnits: null,
            cacheWriteRatePerMillionUnits: null,
            reasoningRatePerMillionUnits: null,
            pricingSource: AiPricingSnapshot::SOURCE_CATALOG,
            pricingTiers: [
                new AiPricingTier(0, 100, AiMoney::rateUnitsFromDecimal('2.00'), AiMoney::rateUnitsFromDecimal('4.00')),
                new AiPricingTier(102, null, AiMoney::rateUnitsFromDecimal('4.00'), AiMoney::rateUnitsFromDecimal('8.00')),
            ],
        );

        self::assertFalse($invalid->isComplete());
    }

    public function test_openai_long_context_catalog_tier_uses_the_documented_boundary(): void
    {
        $definition = AiModelCatalog::find('openai', 'gpt-5.6-terra');

        self::assertNotNull($definition);
        self::assertNotNull($definition->pricing);
        self::assertSame(55, $definition->pricing->calculateCostMinorUnits(272_000, 0));
        self::assertSame(109, $definition->pricing->calculateCostMinorUnits(272_001, 0));
    }

    public function test_legacy_snapshot_is_readable_and_new_schema_keeps_old_minor_fields(): void
    {
        $legacy = AiPricingSnapshot::fromArray([
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => 250,
            'output_cost_per_million_minor_units' => 1000,
            'cache_read_input_cost_per_million_minor_units' => 25,
            'cache_write_input_cost_per_million_minor_units' => 50,
            'reasoning_cost_per_million_minor_units' => 0,
            'fixed_request_cost_applicable' => false,
            'fixed_request_cost_minor_units' => 0,
            'unsupported_meters' => [],
            'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
        ]);

        self::assertSame('2.500000', $legacy->inputPricePerMillion());
        $serialized = $legacy->toArray();
        self::assertSame(250, $serialized['input_cost_per_million_minor_units']);
        self::assertSame(2, $serialized['pricing_schema_version']);
        self::assertSame(AiMoney::RATE_SCALE, $serialized['rate_scale']);
    }

    public function test_embedding_pricing_preserves_exact_rate_and_conservatively_rounds_budget_cost(): void
    {
        $configuration = new EmbeddingConfiguration(
            provider: 'openai',
            model: 'text-embedding-3-small',
            dimensions: 1536,
            version: 'v1',
            timeoutSeconds: 30,
            catalogSource: 'https://developers.openai.com/api/docs/models/text-embedding-3-small',
            verifiedAsOf: '2026-08-22',
        );
        $pricing = new EmbeddingPricingPolicy(
            provider: 'openai',
            model: 'text-embedding-3-small',
            configurationVersion: 'v1',
            currency: 'USD',
            inputCostPerMillionMinorUnits: 0,
            inputRatePerMillionUnits: AiMoney::rateUnitsFromDecimal('0.003625'),
            catalogSource: 'https://developers.openai.com/api/docs/models/text-embedding-3-small',
            pricingAsOf: '2026-08-22',
        );

        $pricing->assertCompatible($configuration);
        self::assertSame(1, $pricing->estimateCostForQuery(str_repeat('x', 1_000_000)));
        self::assertSame('0.003625', $pricing->toArray()['input_rate_per_million_units'] === null
            ? null
            : AiMoney::decimalFromRateUnits($pricing->inputRatePerMillionUnits()));
        self::assertSame('2026-08-22', $pricing->toArray()['pricing_as_of']);

        $unknown = new EmbeddingPricingPolicy(
            provider: 'openai',
            model: 'text-embedding-3-small',
            configurationVersion: 'v1',
            currency: 'USD',
            inputCostPerMillionMinorUnits: null,
        );
        $this->expectException(InvalidArgumentException::class);
        $unknown->assertCompatible($configuration);
    }
}
