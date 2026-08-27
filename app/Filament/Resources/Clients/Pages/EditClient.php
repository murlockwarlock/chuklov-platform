<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Modules\Broadcasts\Application\SetBroadcastClientClassification;
use App\Modules\Broadcasts\Application\SetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientTag;
use App\Modules\Identity\Application\UpdateClientProfileFromCrm;
use App\Modules\Identity\Domain\Models\Client;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected static ?string $title = 'Редактировать клиента';

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Client, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $role = is_string($data['b2b_role'] ?? null) ? $data['b2b_role'] : null;
        $tags = is_array($data['broadcast_tags'] ?? null) ? array_values($data['broadcast_tags']) : [];
        $answer = is_string($data['b2b_specialist_answer'] ?? null)
            ? B2bSpecialistAnswer::tryFrom($data['b2b_specialist_answer'])
            : null;
        unset($data['b2b_role'], $data['broadcast_tags'], $data['b2b_specialist_answer']);
        $updated = app(UpdateClientProfileFromCrm::class)->handle($actor, $record, $data);
        app(SetBroadcastClientClassification::class)->handle($actor, $updated, $role, $tags);
        if ($answer instanceof B2bSpecialistAnswer) {
            app(SetClientB2bSpecialistAnswer::class)->handle($actor, $updated, $answer, 'crm');
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->record;
        abort_unless($record instanceof Client, 404);
        $profile = BroadcastClientProfile::query()
            ->where('organization_id', $record->organization_id)
            ->where('client_id', $record->getKey())
            ->first();
        $data['b2b_role'] = $profile?->b2b_role;
        $data['b2b_specialist_answer'] = $profile?->getRawOriginal('b2b_specialist_answer');
        $data['broadcast_tags'] = BroadcastClientTag::query()->where('organization_id', $record->organization_id)->where('client_id', $record->getKey())->orderBy('tag')->pluck('tag')->all();

        return $data;
    }
}
