<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientSurveysRelationManager;
use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\ListClientAttachments;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Finance\Application\GetClientBalanceSummary;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\ValueObjects\ClientPhoneSearchKey;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ClientWorkspaceUxATest extends TestCase
{
    use RefreshDatabase;

    public function test_client_search_matches_name_email_id_and_phone_variants(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Иван Петров',
            'email' => 'ivan.petrov@example.test',
            'phone' => '+7 (999) 123-45-67',
        ]);
        self::assertSame('79991234567', $client->phone_search_key, 'stored phone key');
        self::assertSame('79991234567', ClientPhoneSearchKey::from('8 999 123 45 67')?->value, 'input phone key');

        $search = app(ClientSearch::class);

        self::assertSame([$client->id], $search->query($admin, 'Петров')->pluck('id')->all(), 'name');
        self::assertSame([$client->id], $search->query($admin, 'PETROV@EXAMPLE')->pluck('id')->all(), 'email');
        self::assertSame([$client->id], $search->query($admin, '#'.$client->id)->pluck('id')->all(), 'id');
        self::assertSame([$client->id], $search->query($admin, (string) $client->id)->pluck('id')->all(), 'bare id');
        self::assertSame([$client->id], $search->query($admin, '+7 999 123 45 67')->pluck('id')->all(), '+7-phone');
        self::assertSame([$client->id], $search->query($admin, '8 999 123 45 67')->pluck('id')->all(), '8-phone');
        self::assertSame([$client->id], $search->query($admin, '7-999-123-45-67')->pluck('id')->all(), '7-phone');
    }

    public function test_client_search_preserves_non_russian_country_digits(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create([
            'phone' => '+1 (202) 555-0100',
        ]);

        self::assertSame(
            [$client->id],
            app(ClientSearch::class)->query($admin, '1 202 555 0100')->pluck('id')->all(),
        );
    }

    public function test_explicit_plus_eight_international_phone_is_not_rewritten_to_seven(): void
    {
        self::assertSame('84912345678', ClientPhoneSearchKey::from('+84 912 345 678')?->value);
        self::assertSame('84912345678', ClientPhoneSearchKey::from('(+84) 912 345 678')?->value);
        self::assertSame('79991234567', ClientPhoneSearchKey::from('8 999 123 45 67')?->value);
    }

    public function test_generic_search_requires_three_character_terms_and_at_most_five_terms(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Ivan Petrov',
            'email' => 'ivan.petrov@example.test',
        ]);

        $search = app(ClientSearch::class);

        self::assertCount(0, $search->query($admin, 'Iv')->get());
        self::assertCount(0, $search->query($admin, 'Ivan Petrov Extra Words Beyond Limit')->get());
        self::assertCount(1, $search->query($admin, 'Petrov')->get());
        self::assertCount(1, $search->query($admin, 'PETROV@EXAMPLE')->get());
        self::assertCount(1, $search->query($admin, 'Ivan Petrov')->get());
    }

    public function test_client_search_is_tenant_scoped_and_bounded(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $otherOrganization = Organization::factory()->create();
        Client::factory()->forOrganization($otherOrganization)->create(['full_name' => 'Shared Name']);

        for ($index = 0; $index < 55; $index++) {
            Client::factory()->forOrganization($organization)->create(['full_name' => 'Shared Name '.$index]);
        }

        $results = app(ClientSearch::class)->query($admin, 'Shared Name')->get();

        self::assertCount(ClientSearch::MAX_RESULTS, $results);
        self::assertTrue($results->every(fn (Client $client): bool => $client->organization_id === $organization->id));
    }

    public function test_malformed_or_short_phone_input_does_not_fall_back_to_broad_client_search(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Known Client',
            'email' => 'known@example.test',
        ]);

        $search = app(ClientSearch::class);

        self::assertCount(0, $search->query($admin, '+7 12')->get());
        self::assertCount(0, $search->query($admin, '---')->get());
    }

    public function test_global_search_uses_the_bounded_safe_client_projection(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Global Search Client',
            'email' => 'global@example.test',
        ]);
        $this->actingAs($admin);

        $results = ClientResource::getGlobalSearchResults('global@example');

        self::assertInstanceOf(Collection::class, $results);
        self::assertCount(1, $results);
        self::assertSame('Global Search Client', $results->first()->title);
        self::assertStringNotContainsString('medical', strtolower(serialize($results->first())));
        self::assertStringContainsString((string) $client->id, $results->first()->url);
    }

    public function test_client_surveys_workspace_tab_mounts_through_filament_relation(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)->test(ClientSurveysRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ]);

        $component->assertSuccessful();
        self::assertCount(0, $component->instance()->getTableRecords());
    }

    public function test_view_client_page_renders_successfully(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)->test(ViewClient::class, [
            'record' => (string) $client->getKey(),
        ]);

        $component->assertSuccessful();
    }

    public function test_cross_organization_attachment_upload_id_fails_closed_before_storage(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        Storage::fake('private');
        $this->expectException(ModelNotFoundException::class);

        app(UploadMedicalAttachment::class)->handle(
            $admin,
            new AttachmentUploadCommand(
                file: UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n"),
                clientId: (int) $otherClient->getKey(),
                attachmentType: AttachmentType::MedicalReport,
            ),
        );

        self::assertSame($organization->id, app(OrganizationContext::class)->id());
    }

    public function test_client_attachment_read_is_scoped_and_excludes_private_storage_identity(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $otherClient = Client::factory()->forOrganization($organization)->create();

        foreach ([$client, $client, $otherClient] as $attachmentClient) {
            $attachment = new MedicalAttachment;
            $attachment->forceFill([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $organization->id,
                'client_id' => $attachmentClient->id,
                'uploaded_by_user_id' => $admin->id,
                'attachment_type' => AttachmentType::MedicalReport,
                'disk' => 'private',
                'storage_path' => 'medical/private/'.Str::uuid().'.pdf',
                'original_filename' => 'report.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 128,
                'sha256_checksum' => hash('sha256', 'report'),
                'scan_status' => AttachmentScanStatus::Cleared,
                'scanned_at' => now(),
            ]);
            $attachment->save();
        }

        $page = app(ListClientAttachments::class)->query($admin, $client)->paginate(1);

        self::assertSame(2, $page->total());
        self::assertCount(1, $page->items());
        self::assertArrayNotHasKey('storage_path', $page->items()[0]->getAttributes());
    }

    public function test_client_attachment_read_denies_cross_organization_client(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();

        $this->expectException(AuthorizationException::class);
        app(ListClientAttachments::class)->query($admin, $otherClient)->get();
    }

    public function test_client_balance_summary_is_bounded_and_organization_scoped(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $unrelatedClient = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();

        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'amount_minor' => 10000,
            'currency' => 'USD',
            'base_amount_minor' => 10000,
            'base_currency' => 'USD',
            'display_amount_minor' => 10000,
            'display_currency' => 'USD',
            'payment_amount_minor' => 10000,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => 10000,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => 10000],
            'conversion_snapshots' => [],
            'creation_key' => 'ux-a-balance-'.$client->id,
        ]);
        $obligation->save();

        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $organization->id,
            'obligation_id' => $obligation->id,
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => 2000,
            'currency' => 'USD',
            'payment_amount_minor' => 2000,
            'payment_currency' => 'USD',
            'base_amount_minor' => 2000,
            'base_currency' => 'USD',
            'display_amount_minor' => 2000,
            'display_currency' => 'USD',
            'settlement_amount_minor' => 2000,
            'settlement_currency' => 'USD',
            'payment_method' => 'cash',
            'occurred_at' => now(),
            'actor_user_id' => $admin->id,
            'idempotency_key' => 'ux-a-balance-payment-'.$client->id,
        ]);
        $entry->save();

        for ($index = 0; $index < 25; $index++) {
            $this->createOutstandingBalanceFixture(
                organization: $organization,
                client: $unrelatedClient,
                specialist: $specialist,
                service: $service,
                index: $index,
                amountMinor: 1000,
                paidMinor: 100,
            );
        }

        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        $otherService = Service::factory()->forOrganization($otherOrganization)->create();
        $this->createOutstandingBalanceFixture(
            organization: $otherOrganization,
            client: $otherClient,
            specialist: $otherSpecialist,
            service: $otherService,
            index: 100,
            amountMinor: 10000,
            paidMinor: 100,
        );

        self::assertSame([
            ['currency' => 'USD', 'outstandingMinor' => '8000'],
        ], app(GetClientBalanceSummary::class)->handle($admin, $client));
    }

    public function test_phone_search_backfill_processes_resumable_batches(): void
    {
        [$organization] = $this->organizationWithAdmin();
        $first = Client::factory()->forOrganization($organization)->create(['phone' => '+7 999 000 00 01']);
        $second = Client::factory()->forOrganization($organization)->create(['phone' => '+7 999 000 00 02']);
        DB::table('clients')->update(['phone_search_key' => null]);

        self::assertSame(Command::SUCCESS, Artisan::call('clients:backfill-phone-search-keys', ['--limit' => 1]));
        self::assertSame('79990000001', $first->fresh()->phone_search_key);
        self::assertNull($second->fresh()->phone_search_key);

        self::assertSame(Command::SUCCESS, Artisan::call('clients:backfill-phone-search-keys', [
            '--limit' => 1,
            '--after-id' => $first->id,
        ]));
        self::assertSame('79990000002', $second->fresh()->phone_search_key);
    }

    /** @return array{Organization, User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function createOutstandingBalanceFixture(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        int $index,
        int $amountMinor,
        int $paidMinor,
    ): void {
        $startsAt = now()->addDays(20 + $index)->setTime(10, 0);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
                'blocking_ends_at' => $startsAt->copy()->addHour(),
            ]);

        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'base_amount_minor' => $amountMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => $amountMinor,
            'display_currency' => 'USD',
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => $amountMinor,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => $amountMinor],
            'conversion_snapshots' => [],
            'creation_key' => 'ux-a-balance-'.$organization->id.'-'.$client->id.'-'.$index,
        ]);
        $obligation->save();

        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $organization->id,
            'obligation_id' => $obligation->id,
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => $paidMinor,
            'currency' => 'USD',
            'payment_amount_minor' => $paidMinor,
            'payment_currency' => 'USD',
            'base_amount_minor' => $paidMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => $paidMinor,
            'display_currency' => 'USD',
            'settlement_amount_minor' => $paidMinor,
            'settlement_currency' => 'USD',
            'payment_method' => 'cash',
            'occurred_at' => now(),
            'actor_user_id' => null,
            'idempotency_key' => 'ux-a-balance-payment-'.$organization->id.'-'.$client->id.'-'.$index,
        ]);
        $entry->save();
    }
}
