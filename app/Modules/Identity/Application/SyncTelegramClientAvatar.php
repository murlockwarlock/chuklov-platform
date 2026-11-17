<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
use Throwable;

final readonly class SyncTelegramClientAvatar
{
    public function __construct(private SetClientAvatar $setAvatar) {}

    public function handle(Nutgram $bot, Client $client, string $externalId): void
    {
        $profile = $client->avatarProfile()->first();
        if ($profile !== null
            && ($profile->source !== 'telegram'
                || ($profile->updated_at !== null && $profile->updated_at->greaterThan(now()->subDays(30))))) {
            return;
        }

        $path = null;
        try {
            $photos = $bot->getUserProfilePhotos((int) $externalId, null, 1);
            $photoSet = $photos?->photos[0] ?? [];
            $photo = collect($photoSet)
                ->filter(fn (mixed $item): bool => $item instanceof PhotoSize)
                ->sortByDesc(fn (PhotoSize $item): int => $item->width * $item->height)
                ->first();
            if (! $photo instanceof PhotoSize) {
                return;
            }

            $path = tempnam(sys_get_temp_dir(), 'chuklov-avatar-');
            if (! is_string($path) || $photo->download($path) !== true) {
                return;
            }

            $file = new UploadedFile($path, 'telegram-avatar.jpg', 'image/jpeg', null, true);
            $this->setAvatar->handle(
                actor: $client,
                client: $client,
                file: $file,
                uploadKey: (string) Str::uuid(),
                source: 'telegram',
            );
        } catch (Throwable $exception) {
            Log::warning('identity.telegram_avatar_sync_failed', [
                'organization_id' => $client->organization_id,
                'client_id' => $client->getKey(),
                'safe_error_code' => 'telegram_avatar_provider_unavailable',
                'exception_class' => $exception::class,
            ]);
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }
}
