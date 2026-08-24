<?php

namespace App\Modules\Feedback\Application;

use App\Models\User;
use App\Modules\Feedback\Domain\Models\FeedbackConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveFeedbackConfiguration
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        bool $enabled,
        int $positiveThreshold,
        bool $lowScoreFeedbackRequired,
        ?string $reviewUrlRu,
        ?string $reviewUrlEn,
    ): FeedbackConfiguration {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);

        if ($positiveThreshold < 1 || $positiveThreshold > 10) {
            throw ValidationException::withMessages(['positive_threshold' => 'Порог должен быть от 1 до 10.']);
        }

        $reviewUrlRu = $this->url($reviewUrlRu, 'review_url_ru');
        $reviewUrlEn = $this->url($reviewUrlEn, 'review_url_en');

        return DB::transaction(function () use ($organization, $actor, $enabled, $positiveThreshold, $lowScoreFeedbackRequired, $reviewUrlRu, $reviewUrlEn): FeedbackConfiguration {
            $configuration = FeedbackConfiguration::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();

            if (! $configuration instanceof FeedbackConfiguration) {
                $configuration = new FeedbackConfiguration;
                $configuration->forceFill(['organization_id' => $organization->getKey()]);
            }

            $configuration->forceFill([
                'enabled' => $enabled,
                'positive_threshold' => $positiveThreshold,
                'low_score_feedback_required' => $lowScoreFeedbackRequired,
                'review_url_ru' => $reviewUrlRu,
                'review_url_en' => $reviewUrlEn,
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'feedback.configuration.updated',
                targetType: FeedbackConfiguration::class,
                targetId: (string) $configuration->getKey(),
                metadata: [
                    'enabled' => $enabled,
                    'positive_threshold' => $positiveThreshold,
                    'low_score_feedback_required' => $lowScoreFeedbackRequired,
                    'review_url_ru_set' => $reviewUrlRu !== null,
                    'review_url_en_set' => $reviewUrlEn !== null,
                ],
            );

            return $configuration->refresh();
        });
    }

    private function url(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 2048) {
            throw ValidationException::withMessages([$field => 'Ссылка слишком длинная.']);
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw ValidationException::withMessages([$field => 'Укажите обычную HTTPS-ссылку.']);
        }

        return $value;
    }
}
