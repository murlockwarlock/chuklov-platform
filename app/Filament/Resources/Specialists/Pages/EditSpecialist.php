<?php

namespace App\Filament\Resources\Specialists\Pages;

use App\Filament\Resources\Specialists\SpecialistResource;
use App\Filament\Support\ScheduleImpactPreview;
use App\Models\User;
use App\Modules\Scheduling\Application\UpdateSpecialistViewerTimezone;
use App\Modules\Specialists\Application\UpdateSpecialist;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistNotificationSettings;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditSpecialist extends EditRecord
{
    protected static string $resource = SpecialistResource::class;

    protected static ?string $title = 'Редактировать специалиста';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Specialist, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $acknowledgeImpact = (bool) ($data['acknowledge_impact'] ?? false);
        $impactDigest = isset($data['impact_digest']) ? (string) $data['impact_digest'] : null;

        try {
            $updated = app(UpdateSpecialist::class)->handle(
                actor: $actor,
                specialist: $record,
                displayName: $data['display_name'],
                isActive: (bool) $data['is_active'],
                timezone: $data['timezone'] ?? null,
                staffUserId: isset($data['staff_user_id']) ? (int) $data['staff_user_id'] : null,
                acknowledgeImpact: $acknowledgeImpact,
                acknowledgedImpactDigest: $impactDigest,
                notificationSettings: SpecialistNotificationSettings::from(
                    telegramId: $data['telegram_id'] ?? null,
                    enabled: (bool) ($data['notifications_enabled'] ?? true),
                ),
            );
            if (array_key_exists('viewer_timezone', $data)) {
                app(UpdateSpecialistViewerTimezone::class)->handle(
                    actor: $actor,
                    specialist: $updated,
                    timezone: $data['viewer_timezone'] === null || $data['viewer_timezone'] === '' ? null : (string) $data['viewer_timezone'],
                    source: $data['viewer_timezone'] === null || $data['viewer_timezone'] === '' ? 'organization' : 'manual',
                );
            }

            return $updated->refresh();
        } catch (ValidationException $exception) {
            $this->form->fill(ScheduleImpactPreview::mergeValidationPreview($data, $exception));

            throw $exception;
        }
    }
}
