<?php

namespace App\Modules\Feedback\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;

final class GetPortalFeedback
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GetFeedbackConfiguration $configuration,
        private readonly ReviewDestinationIconResolver $icons,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Client $client, string $locale): array
    {
        abort_unless((int) $client->organization_id === $this->context->id(), 404);
        $config = $this->configuration->handle();
        $locale = $locale === 'en' ? 'en' : 'ru';
        $links = array_values(array_filter([
            $config['reviewLinks'][$locale],
            $config['reviewLinks'][$locale === 'ru' ? 'en' : 'ru'],
        ], static fn (?string $link): bool => $link !== null));
        $destinations = array_values(array_map(
            fn (array $destination): array => [
                'label' => $destination['label'],
                'url' => $destination['url'],
                'icon' => $this->icons->resolve($destination['url']),
            ],
            array_filter($config['reviewDestinations'], static fn (array $destination): bool => $destination['isActive']),
        ));
        if ($config['reviewDestinations'] === []) {
            $destinations = array_map(
                fn (string $link): array => [
                    'label' => 'Оставить отзыв',
                    'url' => $link,
                    'icon' => $this->icons->resolve($link),
                ],
                $links,
            );
        }

        return [
            'enabled' => $config['enabled'],
            'positiveThreshold' => $config['positiveThreshold'],
            'lowScoreFeedbackRequired' => $config['lowScoreFeedbackRequired'],
            'reviewLinks' => $links,
            'reviewDestinations' => $destinations,
            'submitUrl' => route('portal.feedback.store'),
        ];
    }
}
