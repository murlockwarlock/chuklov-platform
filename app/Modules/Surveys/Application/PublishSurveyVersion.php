<?php

namespace App\Modules\Surveys\Application;

use App\Models\User;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use App\Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Illuminate\Support\Facades\DB;

final readonly class PublishSurveyVersion
{
    public function __construct(
        private SurveyAuthorization $authorization,
        private RecordAuditEvent $audit,
        private SurveyDefinitionValidator $validator,
    ) {}

    public function handle(User $actor, SurveyVersion $version): SurveyVersion
    {
        $organization = $this->authorization->manage($actor);

        return DB::transaction(function () use ($actor, $organization, $version): SurveyVersion {
            $definition = SurveyDefinition::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($version->survey_definition_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = SurveyVersion::query()
                ->where('organization_id', $organization->getKey())
                ->where('survey_definition_id', $definition->getKey())
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($locked->status === SurveyVersionStatus::Draft, 422, 'Опубликовать можно только черновик.');
            $this->validator->validate($locked->definition, $locked->scoring);
            SurveyVersion::query()->where('organization_id', $organization->getKey())->where('survey_definition_id', $definition->getKey())->where('status', SurveyVersionStatus::Published)->update([
                'status' => SurveyVersionStatus::Retired,
                'retired_at' => now(),
            ]);
            $locked->forceFill(['status' => SurveyVersionStatus::Published, 'published_at' => now()])->save();
            $definition->forceFill(['active_version_id' => $locked->getKey()])->save();
            $this->audit->handle($organization, $actor, 'survey.version.published', SurveyVersion::class, (string) $locked->getKey(), [
                'definition_key' => $definition->definition_key,
                'version' => $locked->version,
            ]);

            return $locked->refresh();
        });
    }
}
