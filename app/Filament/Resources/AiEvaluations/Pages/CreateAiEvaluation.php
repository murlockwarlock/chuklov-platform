<?php

namespace App\Filament\Resources\AiEvaluations\Pages;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Organizations\Application\OrganizationContext;
use Filament\Resources\Pages\CreateRecord;
use InvalidArgumentException;

class CreateAiEvaluation extends CreateRecord
{
    protected static string $resource = AiEvaluationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = app(OrganizationContext::class)->id();

        if (! empty($data['prompt_id'])) {
            $prompt = AiPrompt::query()
                ->where('organization_id', $data['organization_id'])
                ->whereKey($data['prompt_id'])
                ->first();

            if ($prompt === null || $prompt->capability->value !== (string) ($data['capability'] ?? '')) {
                throw new InvalidArgumentException('Evaluation prompt must belong to the current organization and capability.');
            }
        }

        return $data;
    }
}
