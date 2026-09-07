<?php

namespace App\Modules\ClientCompanion\Application\Notifications;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

final class NotifyCompanionEscalation
{
    public function __construct(private readonly NotificationChannelRegistry $channels) {}

    public function handle(int $organizationId, int $escalationId): void
    {
        $escalation = CompanionEscalation::query()
            ->where('organization_id', $organizationId)
            ->whereKey($escalationId)
            ->with('client')
            ->first();
        if ($escalation === null || $escalation->client === null) {
            return;
        }

        $organization = Organization::query()->whereKey($organizationId)->first();
        if ($organization === null) {
            return;
        }

        $client = $escalation->client;
        $clientName = trim((string) $client->full_name) ?: 'Клиент #'.$client->getKey();
        $notification = Notification::make()
            ->title('Клиент '.$clientName.' запросил специалиста')
            ->body('Открыто новое обращение в AI-компаньоне.')
            ->actions([
                Action::make('openCompanion')
                    ->label('Открыть диалог')
                    ->url(ClientResource::getUrl('companion', ['record' => $client]))
                    ->button()
                    ->markAsRead(),
            ]);

        $users = User::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('is_active', true))
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission(
                OrganizationPermission::ManageCompanionHandoff,
                $organization,
            ));
        foreach ($users as $user) {
            $user->notifyNow($notification->toDatabase());
        }

        $channel = $this->channels->get('telegram');
        if ($channel === null) {
            return;
        }

        $specialists = Specialist::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('notifications_enabled', true)
            ->with(['staffUser', 'telegramNotificationIdentity'])
            ->get();
        foreach ($specialists as $specialist) {
            $identity = $specialist->telegramNotificationIdentity;
            if ($specialist->staffUser === null
                || $identity === null
                || $identity->verification_status !== ChannelIdentityStatus::Verified) {
                continue;
            }

            $result = $channel->send(new NotificationMessage(
                recipientExternalId: (string) $identity->external_id,
                body: 'Клиент '.$clientName.' запросил специалиста. Откройте диалог в CRM.',
                subject: null,
                locale: 'ru',
                idempotencyKey: 'companion-handoff:'.$organizationId.':'.$escalation->getKey(),
            ));
            if ($result->outcome !== NotificationDeliveryOutcome::Delivered) {
                Log::warning('companion_specialist_telegram_notification_not_delivered', [
                    'organization_id' => $organizationId,
                    'escalation_id' => $escalation->getKey(),
                    'outcome' => $result->outcome->value,
                    'error_code' => $result->errorCode,
                ]);
            }
        }
    }
}
