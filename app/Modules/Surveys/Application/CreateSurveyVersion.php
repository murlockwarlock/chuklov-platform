<?php

namespace App\Modules\Surveys\Application;

use App\Models\User;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use App\Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Illuminate\Support\Facades\DB;

final readonly class CreateSurveyVersion
{
    public function __construct(
        private SurveyAuthorization $authorization,
        private SurveyDefinitionValidator $validator,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, SurveyDefinition $definition, array $data): SurveyVersion
    {
        $organization = $this->authorization->manage($actor);
        $this->authorization->assertDefinition($definition);
        $this->validator->validate($data['definition'], $data['scoring']);

        return DB::transaction(function () use ($actor, $organization, $definition, $data): SurveyVersion {
            $lockedDefinition = SurveyDefinition::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($definition->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $version = (int) $lockedDefinition->versions()->max('version') + 1;

            return SurveyVersion::query()->create([
                'organization_id' => $organization->getKey(),
                'survey_definition_id' => $lockedDefinition->getKey(),
                'version' => $version,
                'status' => SurveyVersionStatus::Draft,
                'title' => $data['title'],
                'title_en' => $data['title_en'] ?? null,
                'description' => $data['description'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'definition' => $data['definition'],
                'scoring' => $data['scoring'],
                'metric_schema_key' => $data['metric_schema_key'] ?? null,
                'source_reference' => $data['source_reference'] ?? null,
                'created_by_user_id' => $actor->getKey(),
            ]);
        });
    }
}
