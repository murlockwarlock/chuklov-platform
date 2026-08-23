<?php

namespace Tests\Feature\Sessions;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\CreateMedicalSession;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\EditMedicalSession;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\ManageClientSessions;
use App\Filament\Resources\Clients\Resources\Sessions\Pages\ViewMedicalSession;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\ListClientSessions;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

final class SessionCockpitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 16, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_history_query_orders_desc_by_occurred_at_and_id_with_preload(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        $first = $this->createSession($admin, $client, $specialist, occurredAt: Carbon::parse('2026-01-10 09:00:00', 'UTC'));
        $second = $this->createSession($admin, $client, $specialist, occurredAt: Carbon::parse('2026-02-12 11:00:00', 'UTC'));
        $sameInstantA = $this->createSession($admin, $client, $specialist, occurredAt: Carbon::parse('2026-02-12 11:00:00', 'UTC'));
        $sameInstantB = $this->createSession($admin, $client, $specialist, occurredAt: Carbon::parse('2026-02-12 11:00:00', 'UTC'));

        $rows = app(ListClientSessions::class)
            ->query($admin, $client)
            ->get();

        self::assertSame([
            (int) $sameInstantB->getKey(),
            (int) $sameInstantA->getKey(),
            (int) $second->getKey(),
            (int) $first->getKey(),
        ], $rows->map(fn (MedicalSession $s): int => (int) $s->getKey())->all());

        self::assertTrue($rows->first()->relationLoaded('specialist'));
        self::assertTrue($rows->first()->relationLoaded('booking'));
    }

    public function test_history_query_projection_excludes_clinical_columns_and_decryption_does_not_run(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        $session = $this->createSession($admin, $client, $specialist, pain: 'Секретная боль отметки');

        $decryptionCalls = $this->decryptCallsInHistory($admin, $client);

        $query = app(ListClientSessions::class)->query($admin, $client);
        $columns = array_map('strval', array_keys((array) $query->getQuery()->columns ?? []));

        self::assertNotContains('pain', $columns);
        self::assertNotContains('tests', $columns);
        self::assertNotContains('observations', $columns);
        self::assertNotContains('root_cause_hypothesis', $columns);
        self::assertNotContains('protocol', $columns);
        self::assertNotContains('result', $columns);
        self::assertNotContains('encryption_key_version', $columns);

        self::assertNotContains('pain', $columns);
        self::assertSame(0, $decryptionCalls);

        $row = $query->first();
        self::assertNotNull($row);
        $rawAttributes = $row->getAttributes();
        self::assertArrayNotHasKey('pain', $rawAttributes);
        self::assertArrayNotHasKey('encryption_key_version', $rawAttributes);
    }

    public function test_history_query_is_organization_and_client_scoped(): void
    {
        [$orgA, $adminA, $clientA, $specialistA] = $this->fixture();
        [$orgB, $adminB] = $this->fixture();

        $otherClient = Client::factory()->forOrganization($orgA)->create();
        app(OrganizationContext::class)->set($orgA);

        $this->createSession($adminA, $clientA, $specialistA);
        $this->createSession($adminA, $otherClient, $specialistA);

        app(OrganizationContext::class)->set($orgB);
        $this->expectException(AuthorizationException::class);
        app(ListClientSessions::class)->query($adminB, $otherClient);
    }

    public function test_history_path_requires_view_clients_permission(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $auditor = User::factory()->create();
        app(OrganizationContext::class)->set($organization);

        self::assertFalse($auditor->hasPermission(OrganizationPermission::ViewClients, $organization));

        $this->expectException(AuthorizationException::class);
        app(ListClientSessions::class)->query($auditor, $client);
    }

    public function test_history_query_paginates_via_sql_with_bounded_sizes(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        for ($i = 0; $i < 30; $i++) {
            $this->createSession(
                $admin,
                $client,
                $specialist,
                occurredAt: Carbon::parse('2026-01-'.$this->padDay($i + 1).' 09:00:00', 'UTC'),
            );
        }

        $paged = app(ListClientSessions::class)
            ->query($admin, $client)
            ->paginate(page: 1, perPage: 25);

        self::assertCount(25, $paged->items());
        self::assertSame(30, $paged->total());
    }

    public function test_get_session_with_expected_client_denies_forged_same_organization_other_client_session(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();

        $session = $this->createSession($admin, $otherClient, $specialist);

        $this->expectException(AuthorizationException::class);
        app(GetSession::class)->handle($admin, $session, $client);
    }

    public function test_update_session_with_expected_client_denies_forged_same_organization_other_client_session(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();

        $session = $this->createSession($admin, $otherClient, $specialist);
        $command = new UpdateSessionCommand(
            pain: 'Злонамеренное изменение',
            tests: null,
            observations: null,
            rootCauseHypothesis: null,
            protocol: null,
            result: null,
        );

        $this->expectException(AuthorizationException::class);
        app(UpdateSession::class)->handle($admin, $session, $command, $client);
    }

    public function test_get_and_update_session_without_expected_client_remains_backward_compatible(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();

        $session = $this->createSession($admin, $client, $specialist, pain: 'открытая боль');

        $retrieved = app(GetSession::class)->handle($admin, $session);
        self::assertSame('открытая боль', $retrieved?->pain);

        $updated = app(UpdateSession::class)->handle($admin, $session, new UpdateSessionCommand(
            pain: 'измененная боль',
            tests: null,
            observations: null,
            rootCauseHypothesis: null,
            protocol: null,
            result: null,
        ));
        self::assertSame('измененная боль', $updated->pain);
    }

    public function test_filament_history_page_only_lists_parent_client_sessions(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $otherClient = Client::factory()->forOrganization($organization)->create();

        $first = $this->createSession($admin, $client, $specialist, occurredAt: Carbon::parse('2026-02-01 10:00:00', 'UTC'));
        $alien = $this->createSession($admin, $otherClient, $specialist, occurredAt: Carbon::parse('2026-01-01 10:00:00', 'UTC'));

        $viewed = $this->get($this->relativeUrl(ClientResource::getUrl('sessions', [
            'record' => $client,
        ], shouldGuessMissingParameters: true)));
        $viewed->assertSuccessful();
        $viewed->assertSee($first->occurred_at->format('d.m.Y H:i'));
        $viewed->assertDontSee($alien->occurred_at->format('d.m.Y H:i'));
    }

    public function test_filament_history_page_uses_projected_preloaded_and_tie_ordered_query(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $older = $this->createSession(
            $admin,
            $client,
            $specialist,
            occurredAt: Carbon::parse('2026-02-12 11:00:00', 'UTC'),
            pain: 'Секретная боль',
        );
        $newer = $this->createSession(
            $admin,
            $client,
            $specialist,
            occurredAt: Carbon::parse('2026-02-12 11:00:00', 'UTC'),
        );

        $encryptor = app(MedicalEncryptorInterface::class);
        $mock = \Mockery::mock(MedicalEncryptorInterface::class);
        $mock->shouldReceive('decryptField')->never();
        app()->instance(MedicalEncryptorInterface::class, $mock);

        try {
            $component = Livewire::actingAs($admin)
                ->test(ManageClientSessions::class, ['record' => $client->getKey()]);
            $records = $component->instance()->getTableRecords();
            $rows = $records->getCollection();

            self::assertSame([$newer->getKey(), $older->getKey()], $rows->pluck('id')->all());
            self::assertCount(2, $rows);

            foreach ($rows as $row) {
                self::assertTrue($row->relationLoaded('specialist'));
                self::assertTrue($row->relationLoaded('booking'));
                self::assertArrayNotHasKey('pain', $row->getAttributes());
                self::assertArrayNotHasKey('tests', $row->getAttributes());
                self::assertArrayNotHasKey('observations', $row->getAttributes());
                self::assertArrayNotHasKey('root_cause_hypothesis', $row->getAttributes());
                self::assertArrayNotHasKey('protocol', $row->getAttributes());
                self::assertArrayNotHasKey('result', $row->getAttributes());
                self::assertArrayNotHasKey('encryption_key_version', $row->getAttributes());
            }
        } finally {
            app()->instance(MedicalEncryptorInterface::class, $encryptor);
        }
    }

    public function test_filament_history_page_paginates_sql_with_a_bounded_page(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        for ($day = 1; $day <= 30; $day++) {
            $this->createSession(
                $admin,
                $client,
                $specialist,
                occurredAt: Carbon::parse('2026-01-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 09:00:00', 'UTC'),
            );
        }

        $component = Livewire::actingAs($admin)
            ->test(ManageClientSessions::class, ['record' => $client->getKey()]);
        $records = $component->instance()->getTableRecords();

        self::assertCount(25, $records->getCollection());
        self::assertSame(30, $records->total());
    }

    public function test_history_navigation_authorization_is_bounded_and_view_only_hides_edit(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $session = $this->createSession($admin, $client, $specialist);
        $renderHistory = function () use ($admin, $client): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                $component = Livewire::actingAs($admin)
                    ->test(ManageClientSessions::class, ['record' => $client->getKey()]);
                $component->instance()->getTableRecords();
                $component->assertSee('Открыть')->assertSee('Редактировать');

                return collect(DB::getQueryLog())
                    ->filter(fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'organization_memberships'))
                    ->count();
            } finally {
                DB::disableQueryLog();
            }
        };

        $smallPageMembershipQueries = $renderHistory();

        for ($index = 0; $index < 24; $index++) {
            $this->createSession($admin, $client, $specialist);
        }

        $largePageMembershipQueries = $renderHistory();

        self::assertGreaterThan(0, $smallPageMembershipQueries);
        self::assertSame($smallPageMembershipQueries, $largePageMembershipQueries);

        Gate::before(static fn (User $user, string $ability): ?bool => in_array($ability, ['create', 'update'], true) ? false : null);

        $viewOnlyHistory = Livewire::actingAs($admin)
            ->test(ManageClientSessions::class, ['record' => $client->getKey()]);
        $viewOnlyHistory->assertSee('Открыть')->assertDontSee('Редактировать');

        $this->actingAs($admin)
            ->get($this->relativeUrl(EditMedicalSession::getUrl([
                'client' => $client,
                'record' => $session,
            ], shouldGuessMissingParameters: true)))
            ->assertForbidden();
    }

    public function test_filament_detail_page_decrypts_the_opened_session_once(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $session = $this->createSession($admin, $client, $specialist, pain: 'Секретная боль');
        $original = app(MedicalEncryptorInterface::class);
        $mock = \Mockery::mock(MedicalEncryptorInterface::class);
        $mock->shouldReceive('decryptField')
            ->times(6)
            ->andReturnUsing(static fn (int $organizationId, ?string $ciphertext): ?string => $ciphertext === null ? null : 'Расшифрованное поле');
        app()->instance(MedicalEncryptorInterface::class, $mock);

        try {
            Livewire::actingAs($admin)
                ->test(ViewMedicalSession::class, ['parentRecord' => $client, 'record' => $session->getKey()])
                ->assertSee('Расшифрованное поле');
        } finally {
            app()->forgetScopedInstances();
            app()->instance(MedicalEncryptorInterface::class, $original);
        }
    }

    public function test_filament_detail_http_page_renders_current_and_previous_session_facts(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $this->createSession(
            $admin,
            $client,
            $specialist,
            occurredAt: Carbon::parse('2026-08-10 10:00:00', 'UTC'),
            pain: 'Предыдущая запись о боли',
        );
        $current = $this->createSession(
            $admin,
            $client,
            $specialist,
            occurredAt: Carbon::parse('2026-08-16 10:00:00', 'UTC'),
            pain: 'Первичная запись о боли',
        );

        $this
            ->actingAs($admin)
            ->get($this->relativeUrl(ViewMedicalSession::getUrl([
                'client' => $client,
                'record' => $current,
            ], shouldGuessMissingParameters: true)))
            ->assertSuccessful()
            ->assertSee('Первичная запись о боли')
            ->assertSee('Предыдущая запись о боли')
            ->assertSee('Файлы сеанса');
    }

    public function test_filament_detail_page_exposes_authorized_nested_edit_navigation(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $session = $this->createSession($admin, $client, $specialist);
        $component = Livewire::actingAs($admin)
            ->test(ViewMedicalSession::class, ['parentRecord' => $client, 'record' => $session->getKey()]);
        $action = collect($component->instance()->getCachedHeaderActions())
            ->first(fn (Action $action): bool => $action->getName() === 'edit');

        self::assertInstanceOf(Action::class, $action);
        self::assertTrue($action->isVisible());
        self::assertSame(MedicalSessionResource::getUrl('edit', [
            'client' => $client,
            'record' => $session,
        ]), $action->getUrl());
        $component->assertSee('Редактировать');
    }

    public function test_filament_detail_page_hides_edit_navigation_when_update_is_denied(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $session = $this->createSession($admin, $client, $specialist);
        Gate::before(static fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);

        $component = Livewire::actingAs($admin)
            ->test(ViewMedicalSession::class, ['parentRecord' => $client, 'record' => $session->getKey()]);
        $action = collect($component->instance()->getCachedHeaderActions())
            ->first(fn (Action $action): bool => $action->getName() === 'edit');

        self::assertInstanceOf(Action::class, $action);
        self::assertFalse($action->isVisible());
        $component->assertDontSee('Редактировать');
    }

    public function test_filament_create_session_over_livewire_creates_for_fixed_parent_client_and_encrypts_clinical_fields(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(CreateMedicalSession::class, ['parentRecord' => $client])
            ->fillForm([
                'occurred_at' => '2026-08-16 10:00',
                'specialist_id' => $specialist->getKey(),
                'pain' => 'Сильная боль в шее',
                'tests' => 'МРТ шейного отдела',
                'observations' => 'Сглаженный лордоз',
                'root_cause_hypothesis' => 'Дисфункция сустава',
                'protocol' => 'Мануальная терапия 3 сеанса',
                'result' => 'Улучшение состояния',
            ]);

        $component
            ->call('create')
            ->assertHasNoErrors();

        $session = MedicalSession::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->sole();

        self::assertSame($specialist->getKey(), $session->specialist_id);
        self::assertNull($session->booking_id);
        self::assertStringNotContainsString('Сильная боль', (string) $session->pain);
        self::assertStringNotContainsString('МРТ шейного отдела', (string) $session->tests);

        $retrieved = app(GetSession::class)->handle($admin, $session, $client);
        self::assertSame('Сильная боль в шее', $retrieved?->pain);
    }

    public function test_filament_create_session_denies_cross_organization_actor_for_parent_client(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        [$otherOrg, $otherAdmin] = $this->fixture();
        $this->resolveFilamentContext($otherAdmin, $otherOrg);

        $createUrl = $this->relativeUrl(CreateMedicalSession::getUrl([
            'client' => $client->getKey(),
        ], shouldGuessMissingParameters: true));

        $response = $this
            ->actingAs($otherAdmin)
            ->get($createUrl);

        $response->assertNotFound();
    }

    public function test_filament_create_session_rejects_invalid_specialist_identifier(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        session()->flush();

        $nonexistentSpecialistId = 999999999;

        $component = Livewire::actingAs($admin)
            ->test(CreateMedicalSession::class, ['parentRecord' => $client])
            ->fillForm([
                'occurred_at' => '2026-08-16 10:00',
                'specialist_id' => $nonexistentSpecialistId,
                'pain' => 'Без специалиста',
            ])
            ->call('create');

        $component->assertHasErrors();
        self::assertSame(0, MedicalSession::query()->where('client_id', $client->getKey())->count());
    }

    public function test_filament_edit_session_loads_decrypted_fields_via_get_session_and_persists_full_snapshot(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $session = $this->createSession($admin, $client, $specialist, pain: 'Первоначальная боль', protocol: 'Первоначальный протокол');

        $component = Livewire::actingAs($admin)
            ->test(EditMedicalSession::class, ['parentRecord' => $client, 'record' => $session->getKey()])
            ->fillForm([
                'pain' => 'Обновлённая боль',
                'tests' => '',
                'observations' => '',
                'root_cause_hypothesis' => '',
                'protocol' => 'Расширенный протокол',
                'result' => '',
            ]);

        $component
            ->call('save')
            ->assertHasNoErrors();

        $updated = app(GetSession::class)->handle($admin, MedicalSession::findOrFail($session->getKey()), $client);

        self::assertSame('Обновлённая боль', $updated?->pain);
        self::assertSame('Расширенный протокол', $updated->protocol);
        self::assertNull($updated->tests);
        self::assertNull($updated->observations);
        self::assertNull($updated->rootCauseHypothesis);
        self::assertNull($updated->result);

        // Structural immutability under edit path.
        $raw = DB::table('medical_sessions')->where('id', $session->getKey())->first();
        self::assertSame((int) $session->organization_id, (int) $raw->organization_id);
        self::assertSame((int) $session->client_id, (int) $raw->client_id);
        self::assertSame((int) $session->specialist_id, (int) $raw->specialist_id);
        $expectedOccurredAt = $session->occurred_at->copy()->utc();
        $actualOccurredAt = Carbon::parse((string) $raw->occurred_at)->utc();
        self::assertTrue($expectedOccurredAt->equalTo($actualOccurredAt));
    }

    public function test_filament_edit_session_rejects_forged_same_organization_other_client_record(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);

        $alien = $this->createSession($admin, $otherClient, $specialist);

        $this
            ->actingAs($admin)
            ->get($this->relativeUrl(EditMedicalSession::getUrl([
                'client' => $client->getKey(),
                'record' => $alien->getKey(),
            ], shouldGuessMissingParameters: true)))
            ->assertNotFound();
    }

    public function test_filament_view_session_rejects_forged_same_organization_other_client_record(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);

        $alien = $this->createSession($admin, $otherClient, $specialist);

        $this
            ->actingAs($admin)
            ->get($this->relativeUrl(ViewMedicalSession::getUrl([
                'client' => $client->getKey(),
                'record' => $alien->getKey(),
            ], shouldGuessMissingParameters: true)))
            ->assertNotFound();
    }

    public function test_specialist_select_options_present_inactive_specialists_after_active(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $active = Specialist::factory()->forOrganization($organization)->create(['display_name' => '000 Активный Специалист']);
        $inactive = Specialist::factory()->inactive()->forOrganization($organization)->create(['display_name' => '999 Архивный Специалист']);
        $foreignOrganization = Organization::factory()->create();
        $foreign = Specialist::factory()->forOrganization($foreignOrganization)->create(['display_name' => 'Чужой Специалист']);
        Specialist::factory()->count(55)->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(CreateMedicalSession::class, ['parentRecord' => $client]);
        $select = $component->instance()->getSchemaComponent('form.specialist_id');

        self::assertInstanceOf(Select::class, $select);
        self::assertTrue($select->isPreloaded());
        $initialOptions = $select->getOptions();
        self::assertCount(50, $initialOptions);
        self::assertSame('000 Активный Специалист (активен)', $initialOptions[$active->getKey()] ?? null);
        self::assertArrayNotHasKey($foreign->getKey(), $initialOptions);
        self::assertSame('000 Активный Специалист (активен)', $select->getSearchResults('Активный')[$active->getKey()] ?? null);
        self::assertSame('999 Архивный Специалист (неактивен)', $select->getSearchResults('Архивный')[$inactive->getKey()] ?? null);
        self::assertArrayNotHasKey($foreign->getKey(), $select->getSearchResults('Чужой'));
        self::assertCount(50, $select->getSearchResults(''));

        $component->fillForm(['specialist_id' => $active->getKey()]);
        $select = $component->instance()->getSchemaComponent('form.specialist_id');
        self::assertInstanceOf(Select::class, $select);
        self::assertSame((string) $active->getKey(), (string) $select->getState());
        self::assertSame('000 Активный Специалист (активен)', $select->getOptionLabel());

        $component->fillForm(['specialist_id' => $inactive->getKey()]);
        $select = $component->instance()->getSchemaComponent('form.specialist_id');
        self::assertInstanceOf(Select::class, $select);
        self::assertSame((string) $inactive->getKey(), (string) $select->getState());
        self::assertSame('999 Архивный Специалист (неактивен)', $select->getOptionLabel());

        $component->fillForm(['specialist_id' => $foreign->getKey()]);
        $select = $component->instance()->getSchemaComponent('form.specialist_id');
        self::assertInstanceOf(Select::class, $select);
        self::assertNull($select->getOptionLabel(false));
    }

    public function test_create_session_specialist_select_uses_filament_dynamic_search_beyond_initial_options(): void
    {
        [$organization, $admin, $client] = $this->fixture();

        for ($index = 1; $index <= 55; $index++) {
            Specialist::factory()->forOrganization($organization)->create([
                'display_name' => 'A Specialist '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $target = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Z Target Specialist',
        ]);
        $otherOrganization = Organization::factory()->create();
        $foreign = Specialist::factory()->forOrganization($otherOrganization)->create([
            'display_name' => 'Z Target Specialist',
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(CreateMedicalSession::class, ['parentRecord' => $client]);
        $select = $component->instance()->getSchemaComponent('form.specialist_id');

        self::assertInstanceOf(Select::class, $select);
        self::assertTrue($select->hasDynamicOptions());
        self::assertTrue($select->hasDynamicSearchResults());

        $initialOptions = $select->getOptionsForJs();
        self::assertCount(50, $initialOptions);
        self::assertFalse(collect($initialOptions)->contains('value', (string) $target->getKey()));

        $searchResults = $component->instance()->callSchemaComponentMethod(
            'form.specialist_id',
            'getSearchResultsForJs',
            ['search' => 'Z Target Specialist'],
        );

        self::assertSame([
            ['label' => 'Z Target Specialist (активен)', 'value' => (string) $target->getKey(), 'isDisabled' => false],
        ], $searchResults);
        self::assertFalse(collect($searchResults)->contains('value', (string) $foreign->getKey()));
        self::assertStringContainsString('hasDynamicSearchResults: true', $component->html());
    }

    public function test_booking_select_narrowed_to_parent_client_and_selected_specialist(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $matching = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => '2026-08-16 10:00:00',
                'ends_at' => '2026-08-16 11:00:00',
                'blocking_ends_at' => '2026-08-16 11:00:00',
            ]);
        $alienClient = Booking::factory()
            ->forOrganization($organization)
            ->forClient($otherClient)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => '2026-08-16 12:00:00',
                'ends_at' => '2026-08-16 13:00:00',
                'blocking_ends_at' => '2026-08-16 13:00:00',
            ]);
        $otherSpecialist = Specialist::factory()->forOrganization($organization)->create();
        $alienSpecialist = Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($otherSpecialist)
            ->forService($service)
            ->create([
                'starts_at' => '2026-08-16 10:00:00',
                'ends_at' => '2026-08-16 11:00:00',
                'blocking_ends_at' => '2026-08-16 11:00:00',
            ]);
        Booking::factory()
            ->count(51)
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->sequence(static function (Sequence $sequence): array {
                $startsAt = Carbon::parse('2026-08-17 10:00:00', 'UTC')->addHours($sequence->index * 2);

                return [
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addHour(),
                    'blocking_ends_at' => $startsAt->copy()->addHour(),
                ];
            })
            ->create();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(CreateMedicalSession::class, ['parentRecord' => $client])
            ->fillForm(['specialist_id' => $specialist->getKey()]);
        $select = $component->instance()->getSchemaComponent('form.booking_id');

        self::assertInstanceOf(Select::class, $select);
        self::assertArrayHasKey($matching->getKey(), $select->getSearchResults((string) $matching->getKey()));
        self::assertArrayNotHasKey($alienClient->getKey(), $select->getSearchResults((string) $alienClient->getKey()));
        self::assertArrayNotHasKey($alienSpecialist->getKey(), $select->getSearchResults((string) $alienSpecialist->getKey()));
        self::assertCount(50, $select->getSearchResults(''));

        $component->fillForm([
            'specialist_id' => $specialist->getKey(),
            'booking_id' => $alienClient->getKey(),
        ]);
        $select = $component->instance()->getSchemaComponent('form.booking_id');
        self::assertInstanceOf(Select::class, $select);
        self::assertNull($select->getOptionLabel(false));
    }

    /**
     * @return array{Organization, User, Client, Specialist}
     */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client, $specialist];
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function createSession(
        User $actor,
        Client $client,
        Specialist $specialist,
        ?Carbon $occurredAt = null,
        ?string $pain = null,
        ?string $protocol = null,
    ): MedicalSession {
        $result = app(CreateSession::class)->handle($actor, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: $occurredAt ?? Carbon::now('UTC'),
            pain: $pain ?? 'Тестовая боль',
            protocol: $protocol,
        ));

        return MedicalSession::query()
            ->where('organization_id', $result->organizationId)
            ->where('id', $result->id)
            ->firstOrFail();
    }

    private function relativeUrl(?string $url): string
    {
        $url ??= '/';
        if (str_starts_with($url, 'http')) {
            return parse_url($url, PHP_URL_PATH) ?? $url;
        }

        return $url;
    }

    private function padDay(int $day): string
    {
        return str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    private function decryptCallsInHistory(User $actor, Client $client): int
    {
        $mock = \Mockery::mock(MedicalEncryptorInterface::class);
        $mock->shouldReceive('decryptField')
            ->andReturnUsing(function (int $orgId, ?string $cipher, int $version): ?string {
                self::fail('MedicalEncryptorInterface::decryptField must not be invoked from the history read path, got cipher for org '.$orgId.' version '.$version);
            });

        $original = app(MedicalEncryptorInterface::class);
        app()->instance(MedicalEncryptorInterface::class, $mock);

        try {
            app(ListClientSessions::class)->query($actor, $client)->get();

            return 0;
        } finally {
            app()->instance(MedicalEncryptorInterface::class, $original);
        }
    }
}
