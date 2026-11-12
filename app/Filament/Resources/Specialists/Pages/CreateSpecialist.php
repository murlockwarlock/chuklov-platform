<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\LocalizedCreateRecord;
use App\Filament\Support\ScheduleImpactPreview;
use App\Filament\Support\SpecialistFormValidation;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class CreateSpecialist extends LocalizedCreateRecord
{
    protected static string $resource = SpecialistResource::class;

    protected static ?string $title = 'Добавить специалиста';

    private ?string $telegramLink = null;

    private bool $telegramAlreadyConnected = false;

    private bool $telegramLinkUnavailable = false;

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Изменения сохранены');
    }

    protected function onValidationError(ValidationException $exception): void
    {
        SpecialistFormValidation::notify($exception);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $specialist = app(CreateSpecialistAction::class)->handle(
                actor: $actor,
                displayName: $data['display_name'],
                isActive: (bool) $data['is_active'],
                timezone: $data['timezone'] ?? null,
                staffUserId: isset($data['staff_user_id']) ? (int) $data['staff_user_id'] : null,
                notificationSettings: SpecialistNotificationSettings::from(null, (bool) ($data['notifications_enabled'] ?? true)),
                profileAttributes: $data,
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
        } catch (ValidationException $exception) {
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview($data, $exception));
            SpecialistFormValidation::notify($exception);

            throw SpecialistFormValidation::forForm($exception);
        } catch (InvalidArgumentException $exception) {
            $validation = SpecialistFormValidation::fromInvalidArgument($exception, $data);
            $this->form->fill($data);
            SpecialistFormValidation::notify($validation);

            throw SpecialistFormValidation::forForm($validation);
        }
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
                ->title($this->getCreatedNotificationTitle())
                ->body(__('Ссылка для привязки Telegram: :link', ['link' => $link]))
                ->persistent()
                ->actions([
                    Action::make('openTelegramLink')
                        ->label(__('Открыть ссылку'))
                        ->url($link)
                        ->openUrlInNewTab()
                        ->button(),
                ]);
        }

        if ($this->telegramAlreadyConnected) {
            return Notification::make()
                ->success()
                ->title($this->getCreatedNotificationTitle())
                ->body(__('Telegram выбранного сотрудника уже подключён. Для смены аккаунта откройте редактирование специалиста и выберите «Перепривязать Telegram».'));
        }

        if ($this->telegramLinkUnavailable) {
            return Notification::make()
                ->warning()
                ->title($this->getCreatedNotificationTitle())
                ->body(__('Ссылку для привязки Telegram можно создать в редактировании специалиста.'));
        }

        return parent::getCreatedNotification();
    }
}
