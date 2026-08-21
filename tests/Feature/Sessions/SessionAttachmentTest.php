<?php

namespace Tests\Feature\Sessions;

use App\Filament\Resources\Clients\Resources\Sessions\Pages\ViewMedicalSession;
use App\Models\User;
use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\GetSessionDynamics;
use App\Modules\Sessions\Application\LinkSessionAttachment;
use App\Modules\Sessions\Application\ListSessionAttachments;
use App\Modules\Sessions\Application\UnlinkSessionAttachment;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class SessionAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_client_attachment_can_be_linked_and_unlinked_without_deleting_file(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $session = $this->makeSession($admin, $client, $specialist);
        $attachment = $this->attachment($organization, $admin, $client, AttachmentScanStatus::Cleared);

        app(LinkSessionAttachment::class)->handle($admin, $session, $client, (int) $attachment->getKey());

        $this->assertDatabaseHas('medical_session_attachments', [
            'medical_session_id' => $session->getKey(),
            'medical_attachment_id' => $attachment->getKey(),
        ]);
        $listed = app(ListSessionAttachments::class)->handle($admin, $session, $client);
        self::assertSame('report.pdf', $listed[0]->filename);
        self::assertNotNull($listed[0]->downloadUrl);

        self::assertTrue(app(UnlinkSessionAttachment::class)->handle(
            $admin,
            $session,
            $client,
            (int) $attachment->getKey(),
        ));
        $this->assertDatabaseMissing('medical_session_attachments', ['medical_attachment_id' => $attachment->getKey()]);
        $this->assertDatabaseHas('medical_attachments', ['id' => $attachment->getKey()]);
    }

    public function test_link_rejects_foreign_organization_and_wrong_client_attachments(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $session = $this->makeSession($admin, $client, $specialist);
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization, OrganizationRole::Administrator)->create();
        $foreign = $this->attachment($otherOrganization, $otherAdmin, Client::factory()->forOrganization($otherOrganization)->create());
        $wrongClient = Client::factory()->forOrganization($organization)->create();
        $wrongAttachment = $this->attachment($organization, $admin, $wrongClient);

        try {
            app(LinkSessionAttachment::class)->handle($admin, $session, $client, (int) $foreign->getKey());
            self::fail('Expected foreign-organization attachment to be rejected.');
        } catch (ValidationException) {
            self::assertDatabaseCount('medical_session_attachments', 0);
        }

        try {
            app(LinkSessionAttachment::class)->handle($admin, $session, $client, (int) $wrongAttachment->getKey());
            self::fail('Expected wrong-client attachment to be rejected.');
        } catch (ValidationException) {
            self::assertDatabaseCount('medical_session_attachments', 0);
        }
    }

    public function test_quarantined_link_has_no_download_url(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $session = $this->makeSession($admin, $client, $specialist);
        $attachment = $this->attachment($organization, $admin, $client, AttachmentScanStatus::Quarantined);
        app(LinkSessionAttachment::class)->handle($admin, $session, $client, (int) $attachment->getKey());

        $listed = app(ListSessionAttachments::class)->handle($admin, $session, $client);
        self::assertNull($listed[0]->downloadUrl);
    }

    public function test_dynamics_is_client_scoped_to_current_and_previous_session(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $previous = $this->makeSession($admin, $client, $specialist, Carbon::parse('2026-08-01'), 'Предыдущая боль');
        $current = $this->makeSession($admin, $client, $specialist, Carbon::parse('2026-08-10'), 'Текущая боль');
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $other = $this->makeSession($admin, $otherClient, $specialist, Carbon::parse('2026-08-09'), 'Чужая боль');

        $dynamics = app(GetSessionDynamics::class)->handle($admin, $current, $client);

        self::assertSame('Текущая боль', $dynamics->current->pain);
        self::assertSame('Предыдущая боль', $dynamics->previous?->pain);
        self::assertNotSame((int) $other->getKey(), $dynamics->previous->id);
    }

    public function test_same_organization_wrong_client_session_is_rejected(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $session = $this->makeSession($admin, $client, $specialist);

        $this->expectException(AuthorizationException::class);
        app(GetSessionDynamics::class)->handle($admin, $session, $otherClient);
    }

    public function test_dynamics_retrieval_stays_bounded_with_long_history(): void
    {
        [, $admin, $client, $specialist] = $this->fixture();

        foreach (range(1, 20) as $day) {
            $this->makeSession($admin, $client, $specialist, Carbon::parse("2026-07-{$day} 09:00:00"));
        }

        $current = $this->makeSession($admin, $client, $specialist, Carbon::parse('2026-08-10 09:00:00'));
        DB::flushQueryLog();
        DB::enableQueryLog();

        $dynamics = app(GetSessionDynamics::class)->handle($admin, $current, $client);
        $sessionQueries = collect(DB::getQueryLog())
            ->filter(static fn (array $query): bool => str_contains($query['query'], 'from "medical_sessions"'));

        self::assertCount(3, $sessionQueries);
        self::assertSame('2026-07-20', $dynamics->previous?->occurredAt?->format('Y-m-d'));
        self::assertTrue($sessionQueries->contains(
            static fn (array $query): bool => str_contains($query['query'], '"occurred_at" <')
                && str_contains($query['query'], 'limit 1'),
        ));
    }

    public function test_session_detail_shows_linked_file_metadata(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $session = $this->makeSession($admin, $client, $specialist);
        $attachment = $this->attachment($organization, $admin, $client);
        app(LinkSessionAttachment::class)->handle($admin, $session, $client, (int) $attachment->getKey());

        Livewire::actingAs($admin)
            ->test(ViewMedicalSession::class, ['parentRecord' => $client, 'record' => $session->getKey()])
            ->assertSee('report.pdf')
            ->assertSee('Проверен');
    }

    public function test_link_attachment_select_has_bounded_tenant_scoped_initial_and_search_results(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $session = $this->makeSession($admin, $client, $specialist);

        foreach (range(1, 55) as $index) {
            $this->attachment(
                $organization,
                $admin,
                $client,
                AttachmentScanStatus::Cleared,
                'Archive '.$index.'.pdf',
            );
        }

        $target = $this->attachment(
            $organization,
            $admin,
            $client,
            AttachmentScanStatus::Cleared,
            'A Target.pdf',
        );
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization, OrganizationRole::Administrator)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $foreign = $this->attachment(
            $otherOrganization,
            $otherAdmin,
            $otherClient,
            AttachmentScanStatus::Cleared,
            'A Target.pdf',
        );

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(ViewMedicalSession::class, ['parentRecord' => $client, 'record' => $session->getKey()])
            ->mountAction('linkAttachment');
        $select = $component->instance()->getSchemaComponent('mountedActionSchema0.attachment_id');

        self::assertInstanceOf(Select::class, $select);
        self::assertTrue($select->isPreloaded());
        self::assertSame(50, $select->getOptionsLimit());
        self::assertCount(50, $select->getOptions());
        self::assertArrayHasKey($target->getKey(), $select->getOptions());
        self::assertArrayNotHasKey($foreign->getKey(), $select->getOptions());

        $searchResults = $select->getSearchResults('Target');
        self::assertArrayHasKey($target->getKey(), $searchResults);
        self::assertArrayNotHasKey($foreign->getKey(), $searchResults);

        $component->setActionData(['attachment_id' => $target->getKey()]);
        $select = $component->instance()->getSchemaComponent('mountedActionSchema0.attachment_id');
        self::assertInstanceOf(Select::class, $select);
        self::assertSame((string) $target->getKey(), (string) $select->getState());
        self::assertStringStartsWith('A Target.pdf · ', (string) $select->getOptionLabel());
    }

    /** @return array{Organization, User, Client, Specialist} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $organization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client, $specialist];
    }

    private function makeSession(User $admin, Client $client, Specialist $specialist, ?Carbon $occurredAt = null, ?string $pain = null): MedicalSession
    {
        $result = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: $occurredAt ?? Carbon::now(),
            pain: $pain ?? 'Тестовая боль',
        ));

        return MedicalSession::query()->findOrFail($result->id);
    }

    private function attachment(
        Organization $organization,
        User $admin,
        Client $client,
        AttachmentScanStatus $status = AttachmentScanStatus::Cleared,
        string $filename = 'report.pdf',
    ): MedicalAttachment {
        return MedicalAttachment::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'uploaded_by_user_id' => $admin->getKey(),
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/'.$organization->getKey().'/report.pdf',
            'original_filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256_checksum' => (string) Str::uuid(),
            'scan_status' => $status,
            'scanned_at' => now(),
        ]);
    }
}
