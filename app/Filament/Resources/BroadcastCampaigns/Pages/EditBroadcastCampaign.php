<?php

namespace App\Filament\Resources\BroadcastCampaigns\Pages;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use App\Models\User;
use App\Modules\Broadcasts\Application\UpdateBroadcastCampaign as Action;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditBroadcastCampaign extends EditRecord
{
    protected static string $resource = BroadcastCampaignResource::class;

    protected static ?string $title = 'Редактировать рассылку';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof BroadcastCampaign, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        if (! array_key_exists('media', $data)) {
            $data['media'] = $record->media;
        }

        $updated = app(Action::class)->handle($actor, $record, CreateBroadcastCampaign::normalizeSegment($data));
        $this->record = $updated;

        return $updated;
    }

    protected function getRedirectUrl(): string
    {
        return BroadcastCampaignResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Рассылка сохранена';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (array_key_exists('segment_definition', $data)
            && (! is_array($data['segment_definition']) || ! array_is_list($data['segment_definition']))) {
            throw ValidationException::withMessages(['segment_definition' => 'Сегмент имеет неверный формат.']);
        }
        $filters = $data['segment_definition'] ?? [];
        $mapped = [];
        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                throw ValidationException::withMessages(['segment_definition' => 'Условие сегмента имеет неверный формат.']);
            }
            $value = $filter['value'] ?? null;
            $filter['value_bool'] = is_bool($value) ? ($value ? '1' : '0') : null;
            $controlled = in_array($filter['key'] ?? null, ['tag', 'b2b_specialist_answer', 'language', 'verified_channel', 'booking_status'], true);
            $filter['value_select_list'] = $controlled && is_array($value) ? $value : [];
            $filter['value_list'] = ! $controlled && is_array($value) ? $value : [];
            $filter['value_select'] = $controlled && is_scalar($value) && ! is_bool($value) ? (string) $value : null;
            $filter['value_text'] = ! $controlled && is_scalar($value) && ! is_bool($value) ? (string) $value : null;
            $mapped[] = $filter;
        }
        $data['segment_definition'] = $mapped;

        $media = is_array($data['media'] ?? null) ? $data['media'] : [];
        $firstMedia = is_array($media['items'] ?? null) ? ($media['items'][0] ?? []) : $media;
        $data['media_alt'] = is_array($firstMedia) && is_string($firstMedia['alt'] ?? null)
            ? $firstMedia['alt']
            : null;

        if (($data['message_mode'] ?? null) === 'compose' && ! filled($data['message_body'] ?? null)
            && $this->record instanceof BroadcastCampaign) {
            $data['message_body'] = (string) $this->record->russianTemplateVersion()->value('body');
        }

        return $data;
    }
}
