<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scenarios\Application\NotificationTemplateMedia;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;

final readonly class BuildReferralInviteMessage
{
    public function __construct(
        private BuildClientReferralLink $referralLinks,
        private NotificationTemplateRenderer $renderer,
        private NotificationTemplateMedia $media,
    ) {}

    public function handle(Client $client, string $recipientExternalId): NotificationMessage
    {
        $locale = str_starts_with(strtolower((string) $client->language), 'en') ? 'en' : 'ru';
        $template = $this->template($client, $locale);
        $referralLink = $this->referralLinks->handle($client);
        if ($template === null) {
            return new NotificationMessage(
                recipientExternalId: $recipientExternalId,
                body: $referralLink,
                subject: null,
                locale: $locale,
                idempotencyKey: 'referral-invite:'.$client->getKey().':'.bin2hex(random_bytes(12)),
                mode: NotificationMessageMode::Text,
            );
        }

        $rendered = $this->renderer->render(
            $template,
            [
                'client' => [
                    'full_name' => (string) $client->full_name,
                    'language' => $locale,
                ],
                'referral_link' => $referralLink,
            ],
            $locale,
        );

        return new NotificationMessage(
            recipientExternalId: $recipientExternalId,
            body: $rendered->body,
            subject: $rendered->subject,
            locale: $locale,
            idempotencyKey: 'referral-invite:'.$client->getKey().':'.bin2hex(random_bytes(12)),
            mode: $rendered->mode,
            showCaptionAboveMedia: $rendered->showCaptionAboveMedia,
            mediaItems: $this->media->messages($client->organization_id, $rendered->media),
        );
    }

    private function template(Client $client, string $locale): ?NotificationTemplateVersion
    {
        $base = NotificationTemplateVersion::query()
            ->where('organization_id', $client->organization_id)
            ->where('status', NotificationTemplateStatus::Published->value)
            ->whereHas('template', function ($query) use ($client): void {
                $query
                    ->where('organization_id', $client->organization_id)
                    ->where('template_key', 'referral-invite')
                    ->where('purpose', 'marketing')
                    ->where('is_active', true);
            })
            ->with('template')
            ->latest('id');

        return (clone $base)
            ->whereHas('template', fn ($query) => $query->where('locale', $locale))
            ->first()
            ?? $base->whereHas('template', fn ($query) => $query->where('locale', 'ru'))->first();
    }
}
