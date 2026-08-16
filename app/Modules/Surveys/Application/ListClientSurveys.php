<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;

final readonly class ListClientSurveys
{
    public function __construct(private SurveyAuthorization $authorization) {}

    /** @return array<string, mixed> */
    public function handle(Client $client): array
    {
        $this->authorization->assertClient($client);
        $definitions = SurveyDefinition::query()
            ->where('organization_id', $client->organization_id)
            ->where('is_available', true)
            ->whereNotNull('active_version_id')
            ->with('activeVersion:id,organization_id,version,status,title,title_en,description,description_en')
            ->orderBy('title')
            ->get(['id', 'organization_id', 'active_version_id', 'title', 'title_en', 'description', 'description_en']);
        $attempts = SurveyAttempt::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->with(['surveyVersion:id,version,title,title_en', 'report:id,survey_attempt_id'])
            ->latest('started_at')
            ->limit(50)
            ->get();

        return [
            'definitions' => $definitions->map(fn (SurveyDefinition $definition): array => [
                'id' => $definition->getKey(),
                'title' => $this->localized($definition->activeVersion?->title, $definition->activeVersion?->title_en, $client->language),
                'description' => $this->localized($definition->activeVersion?->description, $definition->activeVersion?->description_en, $client->language),
                'version' => $definition->activeVersion?->version,
            ])->all(),
            'attempts' => $attempts->map(fn (SurveyAttempt $attempt): array => [
                'id' => $attempt->getKey(),
                'title' => $this->localized($attempt->surveyVersion->title, $attempt->surveyVersion->title_en, $client->language),
                'version' => $attempt->surveyVersion->version,
                'status' => $attempt->status->value,
                'startedAt' => $attempt->started_at->toIso8601String(),
                'completedAt' => $attempt->completed_at?->toIso8601String(),
                'reportId' => $attempt->report?->getKey(),
            ])->all(),
        ];
    }

    private function localized(?string $ru, ?string $en, ?string $locale): ?string
    {
        return str_starts_with(strtolower((string) $locale), 'en') ? ($en ?: $ru) : ($ru ?: $en);
    }
}
