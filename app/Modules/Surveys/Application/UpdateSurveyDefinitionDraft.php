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

final readonly class UpdateSurveyDefinitionDraft
{
    public function __construct(
        private SurveyAuthorization $authorization,
        private SurveyDefinitionValidator $validator,
        private RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, SurveyDefinition $definition, array $data): SurveyDefinition
    {
        $organization = $this->authorization->manage($actor);
        $this->authorization->assertDefinition($definition);
        $this->validator->validate($data['definition'], $data['scoring']);

        return DB::transaction(function () use ($actor, $organization, $definition, $data): SurveyDefinition {
            $locked = SurveyDefinition::query()->where('organization_id', $organization->getKey())->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();
            $draft = SurveyVersion::query()->where('organization_id', $organization->getKey())->where('survey_definition_id', $locked->getKey())->where('status', SurveyVersionStatus::Draft)->latest('version')->lockForUpdate()->first();
            $previousVersion = $draft ?? $locked->versions()->latest('version')->first();
            if ($draft === null) {
                $version = (int) SurveyVersion::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('survey_definition_id', $locked->getKey())
                    ->max('version') + 1;
                $draft = new SurveyVersion([
                    'organization_id' => $organization->getKey(),
                    'survey_definition_id' => $locked->getKey(),
                    'version' => $version,
                    'status' => SurveyVersionStatus::Draft,
                    'created_by_user_id' => $actor->getKey(),
                ]);
            }
            $comparisonEnabled = is_array($data['scoring']['comparison'] ?? null);
            $metricSchemaKey = ($data['start_new_metric_scale'] ?? false) === true
                ? (string) Str::uuid()
                : (is_string($data['metric_schema_key'] ?? null) && trim($data['metric_schema_key']) !== ''
                    ? $data['metric_schema_key']
                    : ($previousVersion->metric_schema_key ?? ($comparisonEnabled ? (string) Str::uuid() : null)));
            $sourceReference = array_key_exists('source_reference', $data)
                ? $data['source_reference']
                : $previousVersion?->source_reference;
            $draft->forceFill([
                'title' => $data['title'],
                'title_en' => $data['title_en'] ?? null,
                'description' => $data['description'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'definition' => $data['definition'],
                'scoring' => $data['scoring'],
                'metric_schema_key' => $metricSchemaKey,
                'source_reference' => $sourceReference,
            ])->save();
            $locked->forceFill([
                'title' => $data['title'],
                'title_en' => $data['title_en'] ?? null,
                'description' => $data['description'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'is_available' => $data['is_available'] ?? true,
            ])->save();
            $this->audit->handle($organization, $actor, 'survey.definition.updated', SurveyDefinition::class, (string) $locked->getKey(), [
                'definition_key' => $locked->definition_key,
                'version' => $draft->version,
            ]);

            return $locked->refresh();
        });
    }
}
