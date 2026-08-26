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

        return app(Action::class)->handle($actor, $record, CreateBroadcastCampaign::normalizeSegment($data));
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
            $filter['value_list'] = is_array($value) ? $value : [];
            $filter['value_text'] = is_scalar($value) && ! is_bool($value) ? (string) $value : null;
            $mapped[] = $filter;
        }
        $data['segment_definition'] = $mapped;

        return $data;
    }
}
