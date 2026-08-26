<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientAcquisitionRegistration;
use App\Modules\Identity\Domain\Models\ClientTelegramAuthenticationRequest;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RegisterClientAcquisition
{
    public function handle(
        Organization $organization,
        Client $client,
        ?string $sessionId = null,
        ?int $telegramAuthenticationRequestId = null,
    ): ClientAcquisitionRegistration {
        $sessionHash = $sessionId === null ? null : hash('sha256', trim($sessionId));

        if ($sessionHash === null && $telegramAuthenticationRequestId === null) {
            throw new RuntimeException('A client acquisition proof requires an authentication binding.');
        }

        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new RuntimeException('The client acquisition organization is invalid.');
        }

        if ($telegramAuthenticationRequestId !== null) {
            $request = ClientTelegramAuthenticationRequest::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($telegramAuthenticationRequestId)
                ->firstOrFail();

            if ($request->client_id !== null && (int) $request->client_id !== (int) $client->getKey()) {
                throw new RuntimeException('The client acquisition request is already bound to another client.');
            }
        }

        try {
            DB::table('client_acquisition_registrations')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'session_hash' => $sessionHash,
                'telegram_authentication_request_id' => $telegramAuthenticationRequestId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
        }

        $registration = ClientAcquisitionRegistration::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->firstOrFail();

        if ($sessionHash !== null && $registration->session_hash !== $sessionHash) {
            throw new RuntimeException('The client acquisition session is already bound to another registration.');
        }

        if ($telegramAuthenticationRequestId !== null
            && (int) $registration->telegram_authentication_request_id !== $telegramAuthenticationRequestId) {
            throw new RuntimeException('The Telegram acquisition request is already bound to another registration.');
        }

        return $registration;
    }
}
