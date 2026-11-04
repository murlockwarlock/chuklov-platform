<?php

namespace App\Modules\Surveys\Application;

use App\Models\User;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class UpdateSurveyPresentation
{
    public function __construct(
        private SurveyAuthorization $authorization,
        private RecordAuditEvent $audit,
    ) {}

    public function snapshot(SurveyDefinition $definition): string
    {
        return hash('sha256', json_encode([
            $definition->getKey(), $definition->organization_id,
            $definition->title, $definition->is_available,
        ], JSON_THROW_ON_ERROR));
    }

    public function handle(User $actor, SurveyDefinition $definition, string $title, bool $available, string $expectedSnapshot): SurveyDefinition
    {
        $organization = $this->authorization->manage($actor);
        $this->authorization->assertDefinition($definition);
        $title = trim($title);
        Validator::make(['title' => $title], ['title' => ['required', 'string', 'max:200']])->validate();

        return DB::transaction(function () use ($actor, $definition, $title, $available, $expectedSnapshot, $organization): SurveyDefinition {
            $locked = SurveyDefinition::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($definition->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if (! hash_equals($this->snapshot($locked), $expectedSnapshot)) {
                throw ValidationException::withMessages(['title' => __('Настройки изменились. Закройте окно и откройте его снова.')]);
            }
            $locked->forceFill(['title' => $title, 'is_available' => $available])->save();
            $this->audit->handle($organization, $actor, 'survey.presentation.updated', SurveyDefinition::class, (string) $locked->getKey(), ['is_available' => $available]);

            return $locked;
        });
    }
}
