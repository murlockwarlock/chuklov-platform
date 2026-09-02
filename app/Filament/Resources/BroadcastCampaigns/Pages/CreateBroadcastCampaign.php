<?php

namespace App\Filament\Resources\BroadcastCampaigns\Pages;

use App\Filament\Resources\BroadcastCampaigns\BroadcastCampaignResource;
use App\Models\User;
use App\Modules\Broadcasts\Application\CreateBroadcastCampaign as Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateBroadcastCampaign extends CreateRecord
{
    protected static string $resource = BroadcastCampaignResource::class;

    protected static ?string $title = 'Создать рассылку';

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(Action::class)->handle($actor, self::normalizeSegment($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeSegment(array $data): array
    {
        if (array_key_exists('segment_definition', $data)
            && (! is_array($data['segment_definition']) || ! array_is_list($data['segment_definition']))) {
            throw ValidationException::withMessages(['segment_definition' => 'Сегмент имеет неверный формат.']);
        }
        $filters = $data['segment_definition'] ?? [];
        $normalized = [];
        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                throw ValidationException::withMessages(['segment_definition' => 'Условие сегмента имеет неверный формат.']);
            }
            $key = is_string($filter['key'] ?? null) ? $filter['key'] : '';
            $operator = is_string($filter['operator'] ?? null) ? $filter['operator'] : '';
            $controlled = in_array($key, ['tag', 'b2b_specialist_answer', 'language', 'verified_channel', 'booking_status'], true);
            $boolean = $filter['value_bool'] ?? null;
            $booleanValue = match (true) {
                $boolean === '1', $boolean === 1, $boolean === true => true,
                $boolean === '0', $boolean === 0, $boolean === false => false,
                default => null,
            };
            $normalized[] = [
                'key' => $key,
                'operator' => $operator,
                'value' => in_array($key, ['survey_completed', 'no_future_booking', 'referral_relationship'], true)
                    ? $booleanValue
                    : ($operator === 'in'
                        ? ($controlled ? ($filter['value_select_list'] ?? []) : ($filter['value_list'] ?? []))
                        : ($controlled ? ($filter['value_select'] ?? null) : ($filter['value_text'] ?? null))),
            ];
        }
        $data['segment_definition'] = $normalized;

        return $data;
    }
}
