<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateModelConfiguration;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiModelReleasePermissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private AiProviderConfiguration $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $this->provider = AiProviderConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'is_enabled' => true,
        ]);

        config()->set('tenancy.default_organization_id', $this->organization->id);
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_manage_provider_permission_can_create_preview_configuration_but_not_activate_release(): void
    {
        $authorizer = $this->authorizerAllowing(OrganizationPermission::ManageAiProviders);
        $createConfiguration = new CreateModelConfiguration($authorizer, app(RecordAuditEvent::class));
        $model = $createConfiguration->handle($this->user, $this->provider, [
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o Mini',
            'capabilities' => [AiCapability::ClientCompanion->value],
        ]);

        $this->assertFalse($model->is_enabled);
        $this->assertSame('preview', $model->lifecycle_status->value);
        $this->assertSame(0, AiModelRelease::query()->where('model_config_id', $model->id)->count());

        $this->expectException(AuthorizationException::class);
        (new CreateAndActivateModelRelease($authorizer, app(RecordAuditEvent::class)))
            ->handle($this->user, $model, []);
    }

    public function test_activate_permission_cannot_mutate_provider_configuration_without_manage_permission(): void
    {
        $pricing = new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60);
        $model = AiModelConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_config_id' => $this->provider->id,
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o Mini',
            'is_enabled' => false,
            'lifecycle_status' => 'preview',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
        ]);
        $authorizer = $this->authorizerAllowing(OrganizationPermission::ActivateAiReleases);
        $action = new CreateAndActivateModelRelease($authorizer, app(RecordAuditEvent::class));

        $release = $action->handle($this->user, $model, []);

        $this->assertSame('active', $release->status);
        $this->assertSame(1, $release->release_number);

        $this->expectException(AuthorizationException::class);
        $action->handle($this->user, $model->fresh(), ['model_name' => 'gpt-4o']);
    }

    private function authorizerAllowing(OrganizationPermission $allowedPermission): OrganizationAuthorizer
    {
        $authorizer = \Mockery::mock(OrganizationAuthorizer::class);
        $authorizer->shouldReceive('authorize')
            ->andReturnUsing(function (User $actor, Organization $organization, OrganizationPermission $permission) use ($allowedPermission) {
                if ($permission !== $allowedPermission) {
                    throw new AuthorizationException('The user is not authorized for this organization action.');
                }

                return $actor->membershipFor($organization);
            });

        return $authorizer;
    }
}
