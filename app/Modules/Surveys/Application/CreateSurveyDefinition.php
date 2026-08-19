<?php

namespace App\Modules\Surveys\Application;

use App\Models\User;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use App\Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateSurveyDefinition
{
    public function __construct(
        private SurveyAuthorization $authorization,
        private SurveyDefinitionValidator $validator,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): SurveyDefinition
    {
        $organization = $this->authorization->manage($actor);
        $this->validator->validate($data['definition'], $data['scoring']);
        $definitionKey = is_string($data['definition_key'] ?? null) && trim($data['definition_key']) !== ''
            ? $data['definition_key']
            : (string) Str::uuid();
        $isOrdinaryCreate = ! array_key_exists('definition_key', $data);
        $comparisonEnabled = is_array($data['scoring']['comparison'] ?? null);
        $metricSchemaKey = array_key_exists('metric_schema_key', $data)
            ? $data['metric_schema_key']
            : ($isOrdinaryCreate ? (string) Str::uuid() : null);
        if ($comparisonEnabled && (! is_string($metricSchemaKey) || trim($metricSchemaKey) === '')) {
            $metricSchemaKey = (string) Str::uuid();
        }

        return DB::transaction(function () use ($actor, $organization, $data, $definitionKey, $metricSchemaKey): SurveyDefinition {
            $definition = SurveyDefinition::query()->create([
                'organization_id' => $organization->getKey(),
                'definition_key' => $definitionKey,
                'title' => $data['title'],
                'title_en' => $data['title_en'] ?? null,
                'description' => $data['description'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'is_available' => $data['is_available'] ?? true,
            ]);
            $version = SurveyVersion::query()->create([
                'organization_id' => $organization->getKey(),
                'survey_definition_id' => $definition->getKey(),
                'version' => 1,
                'status' => SurveyVersionStatus::Draft,
                'title' => $data['title'],
                'title_en' => $data['title_en'] ?? null,
                'description' => $data['description'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'definition' => $data['definition'],
                'scoring' => $data['scoring'],
                'metric_schema_key' => $metricSchemaKey,
                'source_reference' => array_key_exists('source_reference', $data) ? $data['source_reference'] : null,
                'created_by_user_id' => $actor->getKey(),
            ]);
            $this->audit->handle($organization, $actor, 'survey.definition.created', SurveyDefinition::class, (string) $definition->getKey(), [
                'definition_key' => $definition->definition_key,
                'version' => 1,
                'source_present' => $version->source_reference !== null,
            ]);

            return $definition->refresh();
        });
    }
}
