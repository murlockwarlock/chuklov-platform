<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiRuntimeLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_whole_run_deadline_and_queue_timeout_ordering_are_consistent(): void
    {
        $this->assertSame(
            AiRuntimeLimits::PLATFORM_MAX_FAILOVER_ATTEMPTS * (
                (AiRuntimeLimits::PLATFORM_MAX_PROVIDER_STEPS * AiRuntimeLimits::PLATFORM_MAX_TIMEOUT_SECONDS)
                + (AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS * AiRuntimeLimits::PLATFORM_MAX_TOOL_EXECUTION_SECONDS)
            )
                + AiRuntimeLimits::PLATFORM_EXECUTION_MARGIN_SECONDS,
            AiRuntimeLimits::wholeRunSeconds(),
        );
        $this->assertSame(
            (AiRuntimeLimits::PLATFORM_MAX_PROVIDER_STEPS * AiRuntimeLimits::PLATFORM_MAX_TIMEOUT_SECONDS)
                + (AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS * AiRuntimeLimits::PLATFORM_MAX_TOOL_EXECUTION_SECONDS),
            AiRuntimeLimits::providerAttemptSeconds(
                AiRuntimeLimits::PLATFORM_MAX_PROVIDER_STEPS,
                AiRuntimeLimits::PLATFORM_MAX_TIMEOUT_SECONDS,
                AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS,
            ),
        );
        $this->assertLessThan(
            config('horizon.defaults.supervisor-1.timeout'),
            AiRuntimeLimits::PLATFORM_QUEUE_JOB_TIMEOUT_SECONDS,
        );
        $this->assertLessThan(
            config('queue.connections.redis.retry_after'),
            config('horizon.defaults.supervisor-1.timeout'),
        );
        $this->assertGreaterThanOrEqual(
            AiRuntimeLimits::wholeRunSeconds(),
            AiRuntimeLimits::wholeRunSeconds() + AiRuntimeLimits::PLATFORM_LEASE_GRACE_SECONDS,
        );
        $this->assertSame(
            AiRuntimeLimits::wholeRunSeconds() + AiRuntimeLimits::PLATFORM_LEASE_GRACE_SECONDS,
            AiRuntimeLimits::companionProcessingLeaseSeconds(),
        );
        $this->assertGreaterThan(180, AiRuntimeLimits::companionProcessingLeaseSeconds());
    }

    public function test_worst_case_provider_exposure_accounts_for_accumulated_multi_step_history(): void
    {
        $exposure = AiRuntimeLimits::worstCaseProviderExposure(
            maxInputTokens: 100,
            maxOutputTokens: 50,
            maxToolCalls: AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS,
            maxProviderSteps: AiRuntimeLimits::PLATFORM_MAX_PROVIDER_STEPS,
            maxRagContextTokens: 20,
            toolSchemaTokens: 10,
        );

        $expectedInput = ((100 + 20) * AiRuntimeLimits::PLATFORM_MAX_PROVIDER_STEPS)
            + (50 * (0 + 1 + 2 + 3 + 4 + 5))
            + (AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS * AiRuntimeLimits::PLATFORM_MAX_TOOL_RESULT_TOKENS * AiRuntimeLimits::PLATFORM_MAX_PROVIDER_STEPS)
            + (10 * AiRuntimeLimits::PLATFORM_MAX_PROVIDER_STEPS);

        $this->assertSame($expectedInput, $exposure['input_tokens']);
        $this->assertSame(300, $exposure['output_tokens']);
        $this->assertSame($expectedInput + 300, $exposure['total_tokens']);
        $this->assertGreaterThan(
            100 + (50 * 3),
            $exposure['total_tokens'],
        );
    }

    public function test_platform_daily_spend_ceiling_clamps_legacy_organization_values(): void
    {
        config()->set('ai.platform.max_daily_spend_minor_units', 250);

        $this->assertSame(250, AiRuntimeLimits::effectiveDailySpendLimit(1000));
        $this->assertSame(100, AiRuntimeLimits::effectiveDailySpendLimit(100));
    }
}
