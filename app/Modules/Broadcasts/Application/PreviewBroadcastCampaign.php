<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;
use App\Modules\Channels\Application\TelegramMessagePreview;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class PreviewBroadcastCampaign
{
    public function __construct(
        private BroadcastAuthorization $authorization,
        private BroadcastSegmentQuery $segments,
        private BroadcastEligibilityPolicy $eligibility,
        private RecordAuditEvent $audit,
        private BroadcastCampaignMedia $media,
        private NotificationTemplateRenderer $renderer,
        private TelegramMessagePreview $telegramPreview,
    ) {}

    /** @return array{mode: string, captionPosition: string, bodyHtml: string, mediaUrl: string|null, hasText: bool, hasImage: bool, actionButton: array{text: string, url: string}|null} */
    public function message(User $actor, BroadcastCampaign $campaign): array
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }

        $mode = NotificationMessageMode::tryFrom((string) $campaign->delivery_mode);
        if (! $mode instanceof NotificationMessageMode) {
            throw ValidationException::withMessages(['campaign' => 'Формат сообщения рассылки недоступен.']);
        }

        $body = $mode->includesText() ? $this->previewBody($campaign, $organization->getKey()) : '';
        $media = is_array($campaign->media) ? $campaign->media : [];
        $image = is_string($media['image'] ?? null) ? trim($media['image']) : null;
        $mediaUrl = $image === null || $image === ''
            ? null
            : ($this->media->isManagedPath($organization->getKey(), $image) ? $this->media->url($image) : $image);

        try {
            return $this->telegramPreview->handle(new NotificationMessage(
                recipientExternalId: 'preview',
                body: $body,
                subject: null,
                locale: 'ru',
                idempotencyKey: 'preview:'.$campaign->getKey(),
                mediaUrl: $mediaUrl,
                mode: $mode,
                showCaptionAboveMedia: $campaign->caption_position === 'above',
            ));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['message_body' => $exception->getMessage()]);
        }
    }

    private function previewBody(BroadcastCampaign $campaign, int $organizationId): string
    {
        $russianTemplate = $campaign->russianTemplateVersion()
            ->where('organization_id', $organizationId)
            ->first();
        $englishTemplate = $campaign->englishTemplateVersion()
            ->where('organization_id', $organizationId)
            ->first();
        $template = $russianTemplate ?? $englishTemplate;
        $body = $campaign->message_body ?: $template?->body;
        $body = is_string($body) ? trim($body) : '';

        if ($body === '') {
            return '';
        }

        try {
            $variables = ScenarioTemplateVariableCatalog::used($body);
            $previewTemplate = new NotificationTemplateVersion;
            $previewTemplate->forceFill([
                'body' => $body,
                'variables' => $variables,
            ]);
            $locale = $englishTemplate !== null && $template === $englishTemplate ? 'en' : 'ru';

            return $this->renderer->render(
                $previewTemplate,
                ['client' => ['full_name' => 'Aikhana', 'language' => $locale]],
                $locale,
            )->body;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['message_body' => 'Текст сообщения имеет неверный формат.']);
        }
    }

    /** @return array{matched: int, eligible: int, suppressed: int, reasons: array<string, int>, summary: string} */
    public function handle(User $actor, BroadcastCampaign $campaign): array
    {
        $organization = $this->authorization->manage($actor);
        if ((int) $campaign->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The campaign is outside the current organization.');
        }
        if ($campaign->state !== BroadcastCampaignState::Draft) {
            throw ValidationException::withMessages(['campaign' => 'Предпросмотр доступен только для черновика.']);
        }

        $matched = 0;
        $eligible = 0;
        $reasons = [];
        $query = $this->segments->buildForAudience(
            organizationId: $organization->getKey(),
            audienceType: $campaign->audience_type,
            selectedClientIds: $campaign->selected_client_ids,
            filters: $campaign->segment_definition,
        );
        $query->chunkById(200, function ($clients) use (&$matched, &$eligible, &$reasons, $campaign, $organization): void {
            foreach ($clients as $client) {
                $matched++;
                $result = $this->eligibility->evaluate($client, $organization->getKey(), $campaign->channel_priority);
                if ($result['eligible']) {
                    $eligible++;
                } else {
                    $reason = $result['reason'] ?? 'ineligible';
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
        }, 'clients.id', 'id');
        $this->audit->handle($organization, $actor, 'broadcast.campaign.previewed', BroadcastCampaign::class, (string) $campaign->getKey(), ['matched_count' => $matched, 'eligible_count' => $eligible, 'suppressed_count' => $matched - $eligible]);

        return ['matched' => $matched, 'eligible' => $eligible, 'suppressed' => $matched - $eligible, 'reasons' => $reasons, 'summary' => $campaign->segment_summary];
    }
}
