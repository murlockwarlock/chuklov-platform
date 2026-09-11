<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use App\Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Illuminate\Support\Facades\DB;

final class InstallPlatformSurveyCatalog
{
    public function __construct(
        private readonly PlatformSurveyCatalog $catalog,
        private readonly SurveyDefinitionValidator $validator,
    ) {}

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return $this->catalog->definitions();
    }

    /** @return list<array<string, mixed>> */
    public function handle(Organization $organization): array
    {
        return DB::transaction(function () use ($organization): array {
            $installed = [];

            foreach ($this->catalog() as $definitionData) {
                $this->validator->validate($definitionData['definition'], $definitionData['scoring']);
                $definition = SurveyDefinition::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('definition_key', $definitionData['definition_key'])
                    ->lockForUpdate()
                    ->first();

                if ($definition === null) {
                    $definition = SurveyDefinition::query()->create([
                        'organization_id' => $organization->getKey(),
                        'definition_key' => $definitionData['definition_key'],
                        'title' => $definitionData['title'],
                        'title_en' => $definitionData['title_en'],
                        'description' => $definitionData['description'],
                        'description_en' => $definitionData['description_en'],
                        'is_available' => $definitionData['is_available'],
                    ]);
                }

                $published = SurveyVersion::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('survey_definition_id', $definition->getKey())
                    ->where('status', SurveyVersionStatus::Published->value)
                    ->latest('version')
                    ->first();

                if ($published === null
                    || (! $this->isApprovedPublishedVersion($published)
                        && $this->fingerprint($published, $definitionData) !== $this->fingerprintData($definitionData))) {
                    if ($published !== null) {
                        $published->forceFill([
                            'status' => SurveyVersionStatus::Retired,
                            'retired_at' => now(),
                        ])->save();
                    }

                    $version = SurveyVersion::query()->create([
                        'organization_id' => $organization->getKey(),
                        'survey_definition_id' => $definition->getKey(),
                        'version' => (int) $definition->versions()->max('version') + 1,
                        'status' => SurveyVersionStatus::Published,
                        'title' => $definitionData['title'],
                        'title_en' => $definitionData['title_en'],
                        'description' => $definitionData['description'],
                        'description_en' => $definitionData['description_en'],
                        'definition' => $definitionData['definition'],
                        'scoring' => $definitionData['scoring'],
                        'metric_schema_key' => $definitionData['metric_schema_key'],
                        'source_reference' => null,
                        'source' => $definitionData['source'],
                        'approval_status' => $definitionData['approval_status'],
                        'methodology' => $definitionData['methodology'],
                        'published_at' => now(),
                    ]);
                    $definition->forceFill(['active_version_id' => $version->getKey()])->save();
                    $published = $version;
                } elseif ((int) $definition->active_version_id !== (int) $published->getKey()) {
                    $definition->forceFill(['active_version_id' => $published->getKey()])->save();
                }

                $installed[] = [
                    'definition_key' => $definition->definition_key,
                    'definition_id' => (int) $definition->getKey(),
                    'version' => (int) $published->version,
                ];
            }

            return $installed;
        });
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(SurveyVersion $version, array $data): string
    {
        return $this->fingerprintData([
            ...$data,
            'definition' => $version->definition,
            'scoring' => $version->scoring,
            'metric_schema_key' => $version->metric_schema_key,
            'source' => $version->source,
            'approval_status' => $version->approval_status,
            'methodology' => $version->methodology,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function fingerprintData(array $data): string
    {
        return hash('sha256', json_encode([
            'title' => $data['title'],
            'title_en' => $data['title_en'],
            'description' => $data['description'],
            'description_en' => $data['description_en'],
            'definition' => $data['definition'],
            'scoring' => $data['scoring'],
            'metric_schema_key' => $data['metric_schema_key'],
            'source' => $data['source'],
            'approval_status' => $data['approval_status'],
            'methodology' => $data['methodology'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function isApprovedPublishedVersion(SurveyVersion $version): bool
    {
        return $version->source === 'chuklov_approved' || $version->approval_status === 'approved';
    }
}
