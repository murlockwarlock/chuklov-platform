<?php

namespace App\Modules\Channels\Application;

use App\Modules\Channels\Domain\Contracts\NotificationChannel;

final class NotificationChannelRegistry
{
    /**
     * @param  list<NotificationChannel>  $channels
     */
    public function __construct(private readonly array $channels) {}

    public function get(string $name): ?NotificationChannel
    {
        foreach ($this->channels as $channel) {
            if ($channel->name() === $name) {
                return $channel;
            }
        }

        return null;
    }
}
