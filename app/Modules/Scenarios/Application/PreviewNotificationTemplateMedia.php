<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateMediaStorageInterface;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Illuminate\Support\Facades\URL;

final readonly class PreviewNotificationTemplateMedia
{
    public function __construct(private ScenarioAuthorization $authorization, private NotificationTemplateMedia $media, private NotificationTemplateMediaStorageInterface $storage) {}

    /** @return list<NotificationMedia> */
    public function handle(User $actor, NotificationTemplateVersion $version): array
    {
        $organization = $this->authorization->authorizeView($actor);
        $this->authorization->assertOwned($version);
        if ($version->media === null) {
            return [];
        }
        $result = [];
        foreach ($this->media->items($version->media) as $index => $item) {
            if ($this->storage->isManagedPath((int) $organization->getKey(), $item['source'])) {
                $url = URL::temporarySignedRoute('admin.notification-templates.media', now()->addMinutes(15), [
                    'versionId' => $version->getKey(), 'mediaIndex' => $index,
                ]);
            } else {
                $messages = $this->media->messages((int) $organization->getKey(), ['items' => [$item]]);
                $url = $messages[0]->url;
            }
            $result[] = new NotificationMedia(type: $item['type'], url: $url, fileName: $item['name']);
        }

        return $result;
    }
}
