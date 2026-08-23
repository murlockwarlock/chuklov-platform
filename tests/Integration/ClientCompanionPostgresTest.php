<?php

namespace Tests\Integration;

use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClientCompanionPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_concurrent_portal_retries_create_one_turn_and_one_message(): void
    {
        $this->requirePostgres('Companion idempotency concurrency requires PostgreSQL.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $idempotencyKey = 'pg-companion-'.Str::uuid();

        $results = Concurrency::driver('process')->run([
            fn (): array => self::acceptPortalMessage($organization->id, $client->id, $idempotencyKey),
            fn (): array => self::acceptPortalMessage($organization->id, $client->id, $idempotencyKey),
        ]);

        self::assertSame($results[0]['turn_id'], $results[1]['turn_id']);
        self::assertSame(1, CompanionTurn::query()
            ->where('organization_id', $organization->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count());
        self::assertSame(1, DB::table('conversation_messages')
            ->where('organization_id', $organization->id)
            ->where('external_id', 'portal:'.$idempotencyKey)
            ->count());
        self::assertSame(1, CompanionTurnMessage::query()
            ->where('organization_id', $organization->id)
            ->where('turn_id', $results[0]['turn_id'])
            ->count());
    }

    public function test_concurrent_media_groups_preserve_one_conversation_sequence(): void
    {
        $this->requirePostgres('Companion ordering concurrency requires PostgreSQL row locks.');

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();

        $results = Concurrency::driver('process')->run([
            fn (): array => self::acceptTelegramMessage($organization->id, $client->id, 101, 'album-a', 'first'),
            fn (): array => self::acceptTelegramMessage($organization->id, $client->id, 102, 'album-b', 'second'),
        ]);

        self::assertCount(2, CompanionTurn::query()
            ->where('organization_id', $organization->id)
            ->where('client_id', $client->id)
            ->get());
        self::assertSame([1, 2], CompanionTurn::query()
            ->where('organization_id', $organization->id)
            ->where('client_id', $client->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all());
        self::assertCount(2, array_unique(array_column($results, 'turn_id')));
    }

    public function test_companion_turn_composite_tenant_foreign_key_rejects_foreign_client(): void
    {
        $this->requirePostgres('Companion tenant constraints require PostgreSQL composite foreign keys.');

        Queue::fake();
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $client,
            channel: 'portal',
            body: 'Проверка границы организации',
            idempotencyKey: 'pg-tenant-boundary-'.Str::uuid(),
            originExternalId: 'portal:tenant-boundary',
            locale: 'ru',
        );
        $turn->forceFill(['client_id' => $otherClient->id]);

        $this->expectException(QueryException::class);
        $turn->save();
    }

    private function requirePostgres(string $message): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped($message);
        }
    }

    /** @return array{turn_id: int, message_id: int} */
    private static function acceptPortalMessage(int $organizationId, int $clientId, string $idempotencyKey): array
    {
        Queue::fake();
        $organization = Organization::query()->findOrFail($organizationId);
        config()->set('tenancy.default_organization_id', $organizationId);
        app(OrganizationContext::class)->set($organization);
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: Client::query()->findOrFail($clientId),
            channel: 'portal',
            body: 'Повторная отправка',
            idempotencyKey: $idempotencyKey,
            originExternalId: 'portal:'.$idempotencyKey,
            locale: 'ru',
        );

        return ['turn_id' => (int) $turn->getKey(), 'message_id' => (int) $turn->inbound_message_id];
    }

    /** @return array{turn_id: int, message_id: int} */
    private static function acceptTelegramMessage(
        int $organizationId,
        int $clientId,
        int $messageId,
        string $mediaGroupId,
        string $body,
    ): array {
        Queue::fake();
        $organization = Organization::query()->findOrFail($organizationId);
        config()->set('tenancy.default_organization_id', $organizationId);
        app(OrganizationContext::class)->set($organization);
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: Client::query()->findOrFail($clientId),
            channel: 'telegram',
            body: $body,
            idempotencyKey: null,
            originExternalId: 'chat:'.$messageId,
            transportChatId: 'chat',
            locale: 'ru',
            mediaGroupId: $mediaGroupId,
            sourceOrdinal: $messageId,
        );

        return ['turn_id' => (int) $turn->getKey(), 'message_id' => (int) $turn->inbound_message_id];
    }
}
