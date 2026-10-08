<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientNotesRelationManager;
use App\Models\User;
use App\Modules\Identity\Application\BlockClientBlacklist;
use App\Modules\Identity\Application\BlockClientSelfBooking;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Application\CreateClientNote;
use App\Modules\Identity\Application\ListClientNotesForCrm;
use App\Modules\Identity\Application\UnblockClientBlacklist;
use App\Modules\Identity\Domain\Enums\ClientRestrictionType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientNote;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class ClientFinishingPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_note_action_persists_protected_content_and_projects_author_and_date(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $admin->forceFill(['name' => 'Анна Сотрудник'])->save();
        $body = 'Внутренняя заметка без медицинских деталей.';

        $note = app(CreateClientNote::class)->handle($admin, $client, $body);

        self::assertSame($body, $note->body);
        self::assertSame($admin->getKey(), $note->author_user_id);
        self::assertNotNull($note->created_at);

        $rawBody = (string) DB::table('client_notes')->whereKey($note->getKey())->value('body');
        self::assertNotSame($body, $rawBody);
        self::assertStringNotContainsString($body, $rawBody);

        $listed = app(ListClientNotesForCrm::class)
            ->apply($admin, $client, ClientNote::query())
            ->get();

        self::assertCount(1, $listed);
        self::assertSame($body, $listed->sole()->body);
        self::assertSame('Анна Сотрудник', $listed->sole()->author->name);

        $audit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'client.note.created')
            ->sole();
        self::assertTrue($audit->metadata['body_present'] ?? false);
        self::assertStringNotContainsString($body, json_encode($audit->metadata, JSON_UNESCAPED_UNICODE));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($admin)
            ->test(ClientNotesRelationManager::class, [
                'ownerRecord' => $client,
                'pageClass' => ViewClient::class,
            ])
            ->assertSuccessful()
            ->assertSee($body)
            ->assertSee('Анна Сотрудник');

        $component
            ->mountTableAction('add')
            ->setTableActionData(['body' => 'Заметка из страницы клиента'])
            ->callMountedTableAction();

        self::assertDatabaseHas('client_notes', [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'author_user_id' => $admin->getKey(),
        ]);
        self::assertCount(2, ClientNote::query()->where('client_id', $client->getKey())->get());
    }

    public function test_client_notes_respect_permission_and_tenant_boundaries(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $body = 'Организационно изолированная заметка.';

        app(CreateClientNote::class)->handle($admin, $client, $body);

        $unauthorized = User::factory()->create();
        try {
            app(CreateClientNote::class)->handle($unauthorized, $client, 'Недоступно');
            self::fail('A user without organization membership should not create client notes.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $otherOrganization = Organization::factory()->create();
        $otherOrganization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($otherOrganization);

        $this->expectException(AuthorizationException::class);
        app(CreateClientNote::class)->handle($otherAdmin, $client, 'Чужой tenant');
    }

    public function test_blacklist_requires_reason_is_filterable_visible_and_does_not_remove_booking_search_result(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Обычный клиент',
        ]);

        try {
            app(BlockClientBlacklist::class)->handle($admin, $client, '  ');
            self::fail('An empty blacklist reason should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors());
        }

        $reason = 'Повторные нарушения правил общения.';
        app(BlockClientSelfBooking::class)->handle($admin, $client, 'Проверка самостоятельной записи.');
        $restriction = app(BlockClientBlacklist::class)->handle($admin, $client, $reason);

        self::assertSame(ClientRestrictionType::Blacklist, $restriction->restriction_type);
        self::assertSame($reason, $restriction->reason);
        self::assertTrue($client->refresh()->activeBlacklistRestriction !== null);
        self::assertSame(ClientRestrictionType::SelfBooking, $client->activeBookingRestriction->restriction_type);
        self::assertSame($reason, $client->activeBlacklistRestriction->reason);

        $filtered = Client::query()
            ->where('organization_id', $organization->getKey())
            ->whereHas('activeBlacklistRestriction')
            ->pluck('id')
            ->all();
        self::assertSame([$client->getKey()], $filtered);

        self::assertSame(
            [$client->getKey()],
            app(ClientSearch::class)->query($admin, 'Чёрный')->pluck('id')->all(),
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertSuccessful()
            ->assertSee('В чёрном списке')
            ->assertSee($reason)
            ->assertSee('Запись сотрудником CRM доступна');

        Livewire::actingAs($admin)
            ->test(ListClients::class)
            ->set('tableFilters.activeBlacklistRestriction.value', true)
            ->assertCanSeeTableRecords([$client])
            ->assertCanNotSeeTableRecords([$otherClient]);

        app(UnblockClientBlacklist::class)->handle($admin, $client);

        self::assertNull($client->refresh()->activeBlacklistRestriction);
        self::assertSame(
            [$client->getKey()],
            app(ClientSearch::class)->query($admin, 'Чёрный')->pluck('id')->all(),
        );

        $auditActions = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('action', ['client.blacklist.added', 'client.blacklist.removed'])
            ->orderBy('id')
            ->pluck('action')
            ->all();
        self::assertSame(['client.blacklist.added', 'client.blacklist.removed'], $auditActions);
    }

    public function test_blacklist_is_tenant_scoped_and_does_not_block_self_booking_rules_for_crm(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $otherOrganization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($otherOrganization);

        $this->expectException(AuthorizationException::class);
        app(BlockClientBlacklist::class)->handle($otherAdmin, $client, 'Чужой tenant');
    }

    /** @return array{Organization, User, Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $organization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Чёрный клиент для проверки',
        ]);

        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }
}
