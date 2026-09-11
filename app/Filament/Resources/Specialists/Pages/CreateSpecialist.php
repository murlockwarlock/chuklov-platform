<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Models\User;
use App\Modules\Identity\Application\InitiateTelegramOrganizationLink;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Scheduling\Application\UpdateSpecialistViewerTimezone;
use App\Modules\Specialists\Application\CreateSpecialist as CreateSpecialistAction;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistNotificationSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CreateSpecialist extends CreateRecord
{
    protected static string $resource = SpecialistResource::class;

    protected static ?string $title = 'Добавить специалиста';

    private ?string $telegramLink = null;

    private bool $telegramAlreadyConnected = false;

    private bool $telegramLinkUnavailable = false;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $specialist = app(CreateSpecialistAction::class)->handle(
            actor: $actor,
            displayName: $data['display_name'],
            isActive: (bool) $data['is_active'],
            timezone: $data['timezone'] ?? null,
            staffUserId: isset($data['staff_user_id']) ? (int) $data['staff_user_id'] : null,
            notificationSettings: SpecialistNotificationSettings::from(null, (bool) ($data['notifications_enabled'] ?? true)),
        );

        if (array_key_exists('viewer_timezone', $data)) {
            app(UpdateSpecialistViewerTimezone::class)->handle(
                actor: $actor,
                specialist: $specialist,
                timezone: $data['viewer_timezone'] === null || $data['viewer_timezone'] === '' ? null : (string) $data['viewer_timezone'],
                source: $data['viewer_timezone'] === null || $data['viewer_timezone'] === '' ? 'organization' : 'manual',
            );
        }

        return $specialist->refresh();
    }

    protected function afterCreate(): void
    {
        $this->telegramLink = null;
        $this->telegramAlreadyConnected = false;
        $this->telegramLinkUnavailable = false;

        $actor = auth()->user();
        $specialist = $this->record;

        if (! $actor instanceof User || ! $specialist instanceof Specialist || $specialist->staff_user_id === null) {
            return;
        }

        $identity = OrganizationChannelIdentity::query()
            ->where('organization_id', $specialist->organization_id)
            ->where('user_id', $specialist->staff_user_id)
            ->where('channel', 'telegram')
            ->first();

        if ($identity?->verification_status === ChannelIdentityStatus::Verified) {
            $this->telegramAlreadyConnected = true;

            return;
        }

        try {
            $this->telegramLink = app(InitiateTelegramOrganizationLink::class)->handle(
                actor: $actor,
                userId: (int) $specialist->staff_user_id,
            );
        } catch (AuthorizationException|LogicException) {
            $this->telegramLinkUnavailable = true;
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        if ($this->telegramLink !== null) {
            $link = $this->telegramLink;

            return Notification::make()
                ->success()
                ->title('Специалист создан')
                ->body('Ссылка для привязки Telegram: '.$link)
                ->persistent()
                ->actions([
                    Action::make('openTelegramLink')
                        ->label('Открыть ссылку')
                        ->url($link)
                        ->openUrlInNewTab()
                        ->button(),
                ]);
        }

        if ($this->telegramAlreadyConnected) {
            return Notification::make()
                ->success()
                ->title('Специалист создан')
                ->body('Telegram выбранного сотрудника уже подключён. Для смены аккаунта откройте карточку специалиста и выберите «Перепривязать Telegram».');
        }

        if ($this->telegramLinkUnavailable) {
            return Notification::make()
                ->warning()
                ->title('Специалист создан')
                ->body('Ссылку для привязки Telegram можно создать из карточки специалиста.');
        }

        return parent::getCreatedNotification();
    }
}
