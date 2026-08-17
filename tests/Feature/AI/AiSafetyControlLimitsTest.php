<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\UpdateAiSafetyControl;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class AiSafetyControlLimitsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        config()->set('tenancy.default_organization_id', $this->organization->id);
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_application_write_rejects_organization_limits_above_platform_maxima(): void
    {
        foreach (['max_failover_attempts', 'max_tool_calls_per_run'] as $key) {
            try {
                app(UpdateAiSafetyControl::class)->handle($this->user, [$key => 1000]);
                $this->fail("Expected {$key} to be rejected above the platform maximum.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($key.' must be between', $exception->getMessage());
            }
        }
    }

    public function test_legacy_database_values_are_clamped_at_runtime(): void
    {
        $control = AiOrganizationSafetyControl::create([
            'organization_id' => $this->organization->id,
            'max_tokens_per_run' => 1000,
            'max_runs_per_minute' => 1000,
            'max_tool_calls_per_run' => 1000,
            'default_timeout_seconds' => 1000,
            'max_failover_attempts' => 1000,
        ]);
        $capability = AiCapabilityRegistry::get('client_companion');

        $this->assertSame(
            AiRuntimeLimits::PLATFORM_MAX_TOOL_CALLS,
            AiRuntimeLimits::effectiveMaxToolCalls($capability, $control->max_tool_calls_per_run),
        );
        $this->assertSame(
            AiRuntimeLimits::PLATFORM_MAX_FAILOVER_ATTEMPTS,
            AiRuntimeLimits::effectiveMaxFailoverAttempts($control->max_failover_attempts),
        );
        $this->assertSame(
            AiRuntimeLimits::PLATFORM_MAX_RUNS_PER_MINUTE,
            AiRuntimeLimits::effectiveRunsPerMinute($control->max_runs_per_minute),
        );
        $this->assertSame(
            AiRuntimeLimits::PLATFORM_MAX_TIMEOUT_SECONDS,
            AiRuntimeLimits::effectiveTimeout(1000, 1000, $control->default_timeout_seconds),
        );
        $this->assertLessThanOrEqual(
            AiRuntimeLimits::PLATFORM_MAX_OUTPUT_TOKENS,
            AiRuntimeLimits::effectiveMaxOutputTokens($capability, $control->max_tokens_per_run, $control->max_tokens_per_run),
        );
    }
}
