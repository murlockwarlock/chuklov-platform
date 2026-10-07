<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\EstablishManualReferralRelationship;
use App\Modules\Referrals\Application\ReplaceReferralRelationship;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Security\Domain\Models\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\AuthenticatesMfaConfiguredAdmin;
use Tests\TestCase;

final class ReferralRelationshipReplacementTest extends TestCase
{
    use AuthenticatesMfaConfiguredAdmin;
    use RefreshDatabase;

    public function test_authorized_admin_can_replace_automatic_relationship_and_audit_old_and_new_referrers(): void
    {
        [$organization, $admin, $oldReferrer, $newReferrer, $referred] = $this->fixture();
        $current = $this->relationship($organization, $oldReferrer, $referred, 'automatic_referral_link');

        $replacement = app(ReplaceReferralRelationship::class)->handle(
            actor: $admin,
            referrerClientId: $newReferrer->getKey(),
            referredClientId: $referred->getKey(),
            reason: 'Клиент подтвердил другой источник.',
        );

        self::assertSame($newReferrer->getKey(), $replacement->referrer_client_id);
        self::assertSame($newReferrer->getKey(), $referred->fresh()->referralRelationship?->referrer_client_id);
        self::assertNotNull($current->fresh()->superseded_at);
        self::assertSame($replacement->getKey(), $current->fresh()->superseded_by_relationship_id);
        self::assertDatabaseHas('audit_events', [
            'action' => 'referral.relationship.replaced',
            'target_id' => (string) $replacement->getKey(),
        ]);
        self::assertSame([
            'old_referrer_client_id' => $oldReferrer->getKey(),
            'new_referrer_client_id' => $newReferrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'source' => 'crm_admin',
            'reason_present' => true,
        ], AuditEvent::query()->where('action', 'referral.relationship.replaced')->sole()->metadata);
    }

    public function test_authorized_admin_can_replace_manual_relationship(): void
    {
        [$organization, $admin, $oldReferrer, $newReferrer, $referred] = $this->fixture();
        app(EstablishManualReferralRelationship::class)->handle($admin, $oldReferrer->getKey(), $referred->getKey());

        $replacement = app(ReplaceReferralRelationship::class)->handle($admin, $newReferrer->getKey(), $referred->getKey());

        self::assertSame($newReferrer->getKey(), $replacement->referrer_client_id);
        self::assertSame(2, ReferralRelationship::query()->where('referred_client_id', $referred->getKey())->count());
    }

    public function test_no_existing_relationship_uses_normal_manual_assignment(): void
    {
        [, $admin, , $newReferrer, $referred] = $this->fixture();

        app(EstablishManualReferralRelationship::class)->handle(
            actor: $admin,
            referrerClientId: $newReferrer->getKey(),
            referredClientId: $referred->getKey(),
        );

        $relationship = ReferralRelationship::query()->sole();

        self::assertSame($newReferrer->getKey(), $relationship->referrer_client_id);
        self::assertSame('manual_crm', $relationship->establishment_method->value);
        self::assertNull($relationship->superseded_at);
    }

    public function test_client_action_shows_current_referrer_and_replacement_warning_before_submit(): void
    {
        [$organization, $admin, $oldReferrer, $newReferrer, $referred] = $this->fixture();
        $this->relationship($organization, $oldReferrer, $referred, 'automatic_referral_link');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $referred->getKey()])
            ->assertSuccessful()
            ->assertActionExists('assignPartner')
            ->mountAction('assignPartner');

        $modalHtml = $component->getMountedActionModalHtml();
        $actionData = $component->instance()->getMountedAction()->getRawData();

        self::assertStringContainsString('Текущий пригласивший', $modalHtml);
        self::assertStringContainsString('Старый пригласивший', (string) $actionData['current_referrer']);
        self::assertStringContainsString('персональная ссылка', (string) $actionData['current_referrer']);
        self::assertStringContainsString('Изменение заменит текущую реферальную связь', (string) $actionData['replacement_warning']);

        $component
            ->fillForm([
                'referrer_client_id' => $newReferrer->getKey(),
                'replacement_reason' => 'Клиент подтвердил новый источник.',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Пригласивший заменён');

        self::assertSame($newReferrer->getKey(), $referred->fresh()->referralRelationship?->referrer_client_id);
    }

    public function test_staff_cannot_replace_existing_relationship(): void
    {
        [$organization, , $oldReferrer, $newReferrer, $referred] = $this->fixture();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->relationship($organization, $oldReferrer, $referred);

        $this->expectException(AuthorizationException::class);
        app(ReplaceReferralRelationship::class)->handle($staff, $newReferrer->getKey(), $referred->getKey());
    }

    public function test_replacement_rejects_self_and_cross_organization_clients(): void
    {
        [$organization, $admin, $oldReferrer, $newReferrer, $referred] = $this->fixture();
        $this->relationship($organization, $oldReferrer, $referred);

        try {
            app(ReplaceReferralRelationship::class)->handle($admin, $referred->getKey(), $referred->getKey());
            self::fail('A client must not refer itself.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();

        $this->expectException(ModelNotFoundException::class);
        app(ReplaceReferralRelationship::class)->handle($admin, $foreignClient->getKey(), $referred->getKey());
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $oldReferrer = Client::factory()->forOrganization($organization)->create(['full_name' => 'Старый пригласивший']);
        $newReferrer = Client::factory()->forOrganization($organization)->create(['full_name' => 'Новый пригласивший']);
        $referred = Client::factory()->forOrganization($organization)->create(['full_name' => 'Клиент']);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        $this->authenticateConfiguredAdmin($admin, $organization);

        return [$organization, $admin, $oldReferrer, $newReferrer, $referred];
    }

    private function relationship(
        Organization $organization,
        Client $referrer,
        Client $referred,
        string $method = 'manual_crm',
    ): ReferralRelationship {
        $relationship = new ReferralRelationship;
        $relationship->forceFill([
            'organization_id' => $organization->getKey(),
            'referrer_client_id' => $referrer->getKey(),
            'referred_client_id' => $referred->getKey(),
            'establishment_method' => $method,
            'registered_at' => now(),
        ]);
        $relationship->save();

        return $relationship;
    }
}
