<?php

namespace Tests\Integration;

use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientChannelLinkToken;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Domain\Models\ClientEmailAuthChallenge;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MilestoneTwoDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_telegram_identity_is_unique_inside_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $firstClient = Client::factory()->forOrganization($organization)->create();
        $secondClient = Client::factory()->forOrganization($organization)->create();

        ClientChannelIdentity::factory()->forClient($firstClient)->create([
            'channel' => 'telegram',
            'external_id' => 'duplicate-telegram-id',
        ]);

        $duplicate = ClientChannelIdentity::factory()->forClient($secondClient)->make([
            'channel' => 'telegram',
            'external_id' => 'duplicate-telegram-id',
        ]);

        $this->expectException(QueryException::class);

        $duplicate->save();
    }

    public function test_onboarding_and_conversation_records_cannot_cross_organization_clients(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();

        $onboarding = new ClientOnboarding;
        $onboarding->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $otherClient->id,
            'flow_version' => 'm2-v1',
            'current_stage' => 'contacts',
            'data' => [],
        ]);

        $this->expectException(QueryException::class);

        $onboarding->save();
    }

    public function test_email_challenge_and_link_token_uniqueness_is_database_enforced(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        ClientEmailAuthChallenge::factory()->forOrganization($organization)->create([
            'email' => 'unique@example.test',
        ]);
        $duplicateChallenge = ClientEmailAuthChallenge::factory()->forOrganization($organization)->make([
            'email' => 'unique@example.test',
        ]);

        try {
            DB::transaction(function () use ($duplicateChallenge): void {
                $duplicateChallenge->save();
            });
            self::fail('Email challenges must be unique per organization and email.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        ClientChannelLinkToken::factory()->forClient($client)->create([
            'token_hash' => hash('sha256', 'duplicate-token'),
        ]);
        $duplicateToken = ClientChannelLinkToken::factory()->forClient($client)->make([
            'token_hash' => hash('sha256', 'duplicate-token'),
        ]);

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($duplicateToken): void {
            $duplicateToken->save();
        });
    }

    public function test_one_unconsumed_link_flow_is_enforced_per_organization_client_channel_and_flow(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $active = ClientChannelLinkToken::factory()->forClient($client)->create();
        $duplicate = ClientChannelLinkToken::factory()->forClient($client)->make();

        try {
            DB::transaction(function () use ($duplicate): void {
                $duplicate->save();
            });
            self::fail('Only one unconsumed link flow may exist for the scoped channel and flow.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $active->forceFill(['consumed_at' => Carbon::now()])->save();
        ClientChannelLinkToken::factory()->forClient($client)->create();
    }

    public function test_one_published_legal_document_is_enforced_per_organization_type_and_locale(): void
    {
        $organization = Organization::factory()->create();
        LegalDocument::factory()->forOrganization($organization)->published()->create([
            'version' => '2026-08-12-v1',
        ]);
        $duplicate = LegalDocument::factory()->forOrganization($organization)->published()->make([
            'version' => '2026-08-12-v2',
        ]);

        try {
            DB::transaction(function () use ($duplicate): void {
                $duplicate->save();
            });
            self::fail('Only one legal document may be current for the scoped type and locale.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_channel_link_tokens_and_consents_preserve_composite_organization_foreign_keys(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $token = ClientChannelLinkToken::factory()->forClient($client)->make();
        $token->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $otherClient->id,
        ]);

        try {
            DB::transaction(function () use ($token): void {
                $token->save();
            });
            self::fail('A link token cannot point across organizations.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $document = LegalDocument::factory()->forOrganization($organization)->create();
        $consent = new ClientConsent;
        $consent->forceFill([
            'organization_id' => $otherOrganization->id,
            'client_id' => $otherClient->id,
            'legal_document_id' => $document->id,
            'subject' => 'privacy',
            'version' => '2026-08-12',
            'is_required' => true,
            'granted' => true,
            'evidence' => 'portal',
            'recorded_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($consent): void {
            $consent->save();
        });
    }

    public function test_m2_real_instants_use_postgresql_timezone_aware_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->whereIn('table_name', [
                'client_email_auth_challenges',
                'client_channel_link_tokens',
                'legal_documents',
                'audit_events',
                'client_consents',
                'client_channel_identities',
                'client_onboardings',
                'conversations',
                'conversation_messages',
            ])
            ->whereIn('column_name', [
                'expires_at',
                'consumed_at',
                'published_at',
                'effective_at',
                'occurred_at',
                'recorded_at',
                'verified_at',
                'completed_at',
                'started_at',
                'last_message_at',
                'created_at',
                'updated_at',
            ])
            ->get(['table_name', 'column_name', 'data_type'])
            ->mapWithKeys(static fn (object $column): array => [
                $column->table_name.'.'.$column->column_name => $column->data_type,
            ]);

        self::assertSame('timestamp with time zone', $columns['client_email_auth_challenges.expires_at']);
        self::assertSame('timestamp with time zone', $columns['client_channel_link_tokens.consumed_at']);
        self::assertSame('timestamp with time zone', $columns['legal_documents.published_at']);
        self::assertSame('timestamp with time zone', $columns['audit_events.occurred_at']);
        self::assertSame('timestamp with time zone', $columns['client_consents.recorded_at']);
        self::assertSame('timestamp with time zone', $columns['client_channel_identities.verified_at']);
        self::assertSame('timestamp with time zone', $columns['client_onboardings.completed_at']);
        self::assertSame('timestamp with time zone', $columns['conversations.started_at']);
        self::assertSame('timestamp with time zone', $columns['conversation_messages.occurred_at']);
    }
}
