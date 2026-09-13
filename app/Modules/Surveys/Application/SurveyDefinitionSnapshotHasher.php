<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;

final class SurveyDefinitionSnapshotHasher
{
    public function forDefinition(SurveyDefinition $definition, SurveyVersion $version): string
    {
        return hash('sha256', json_encode([
            'definition' => [
                'id' => (int) $definition->getKey(),
                'organization_id' => (int) $definition->getRawOriginal('organization_id'),
                'definition_key' => $definition->getRawOriginal('definition_key'),
                'title' => $definition->getRawOriginal('title'),
                'title_en' => $definition->getRawOriginal('title_en'),
                'description' => $definition->getRawOriginal('description'),
                'description_en' => $definition->getRawOriginal('description_en'),
                'active_version_id' => $definition->getRawOriginal('active_version_id'),
                'is_available' => $definition->getRawOriginal('is_available'),
            ],
            'version' => [
                'id' => (int) $version->getKey(),
                'organization_id' => (int) $version->getRawOriginal('organization_id'),
                'survey_definition_id' => (int) $version->getRawOriginal('survey_definition_id'),
                'version' => (int) $version->getRawOriginal('version'),
                'status' => $version->getRawOriginal('status'),
                'title' => $version->getRawOriginal('title'),
                'title_en' => $version->getRawOriginal('title_en'),
                'description' => $version->getRawOriginal('description'),
                'description_en' => $version->getRawOriginal('description_en'),
                'definition' => $version->getRawOriginal('definition'),
                'scoring' => $version->getRawOriginal('scoring'),
                'metric_schema_key' => $version->getRawOriginal('metric_schema_key'),
                'source_reference' => $version->getRawOriginal('source_reference'),
                'source' => $version->getRawOriginal('source'),
                'approval_status' => $version->getRawOriginal('approval_status'),
                'methodology' => $version->getRawOriginal('methodology'),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
