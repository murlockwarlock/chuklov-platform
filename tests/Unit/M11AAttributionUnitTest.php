<?php

namespace Tests\Unit;

use App\Modules\Attribution\Domain\ValueObjects\AttributionNormalizer;
use App\Modules\Feedback\Domain\Enums\NpsBand;
use Tests\TestCase;

final class M11AAttributionUnitTest extends TestCase
{
    public function test_referral_evidence_has_precedence_and_does_not_persist_arbitrary_query_data(): void
    {
        $data = (new AttributionNormalizer)->handle([
            'source' => ' Partner ',
            'referral_code' => str_repeat('R', 43),
            'utm_source' => 'Instagram',
            'unexpected' => 'must not be persisted',
        ]);

        self::assertNotNull($data);
        self::assertSame('referral', $data->sourceType);
        self::assertSame('partner', $data->source);
        self::assertSame(str_repeat('R', 43), $data->referralCode);
        self::assertArrayNotHasKey('unexpected', $data->toArray());
    }

    public function test_invalid_referral_falls_back_to_utm_attribution(): void
    {
        $data = (new AttributionNormalizer)->handle([
            'referral_code' => 'not-valid',
            'utm_source' => ' Search ',
            'utm_campaign' => str_repeat('x', 200),
        ]);

        self::assertNotNull($data);
        self::assertSame('utm', $data->sourceType);
        self::assertSame('search', $data->utmSource);
        self::assertSame(160, mb_strlen((string) $data->utmCampaign));
    }

    public function test_nps_threshold_is_configuration_driven(): void
    {
        self::assertSame(NpsBand::Positive, NpsBand::fromScore(8, 8));
        self::assertSame(NpsBand::Internal, NpsBand::fromScore(7, 8));
        self::assertSame(NpsBand::Positive, NpsBand::fromScore(7, 7));
    }
}
