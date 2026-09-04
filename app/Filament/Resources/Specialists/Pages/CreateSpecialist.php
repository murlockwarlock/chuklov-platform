<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Models\User;
use App\Modules\Scheduling\Application\UpdateSpecialistViewerTimezone;
use App\Modules\Specialists\Application\CreateSpecialist as CreateSpecialistAction;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistNotificationSettings;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSpecialist extends CreateRecord
{
    protected static string $resource = SpecialistResource::class;

    protected static ?string $title = 'Добавить специалиста';

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
            notificationSettings: SpecialistNotificationSettings::from(
                telegramId: $data['telegram_id'] ?? null,
                enabled: (bool) ($data['notifications_enabled'] ?? true),
            ),
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
}
