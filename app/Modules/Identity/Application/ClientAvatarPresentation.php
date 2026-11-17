<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Identity\Domain\Models\Client;

final readonly class ClientAvatarPresentation
{
    public function __construct(private GetTemporaryAttachmentUrl $temporaryUrl) {}

    /** @return array{url: ?string, initials: string} */
    public function forCrm(User $actor, Client $client): array
    {
        $attachment = $client->avatarProfile?->attachment;

        return [
            'url' => $attachment === null ? null : $this->temporaryUrl->handlePreview($actor, $attachment, 15),
            'initials' => $this->initials($client),
        ];
    }

    public function initials(Client $client): string
    {
        $parts = preg_split('/\s+/u', trim((string) $client->full_name)) ?: [];
        $initials = collect(array_slice($parts, 0, 2))
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials === '' ? '?' : $initials;
    }

    public function fallbackDataUri(Client $client): string
    {
        $initials = htmlspecialchars($this->initials($client), ENT_QUOTES | ENT_XML1);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="#f1e2cd"/><text x="48" y="57" fill="#9c6831" font-family="Arial,sans-serif" font-size="28" font-weight="700" text-anchor="middle">'.$initials.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
