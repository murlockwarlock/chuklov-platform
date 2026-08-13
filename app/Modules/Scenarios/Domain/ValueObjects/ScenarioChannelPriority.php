<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ScenarioChannelPriority
{
    /** @param list<string> $channels */
    public function __construct(public array $channels) {}

    /** @param array<int, string> $channels */
    public static function from(array $channels): self
    {
        $normalized = [];

        foreach ($channels as $channel) {
            $channel = strtolower(trim((string) $channel));

            if ($channel === '' || mb_strlen($channel) > 32 || preg_match('/^[a-z0-9._-]+$/', $channel) !== 1 || in_array($channel, $normalized, true)) {
                throw new InvalidArgumentException('The scenario channel priority is invalid.');
            }

            $normalized[] = $channel;
        }

        if ($normalized === [] || count($normalized) > 8) {
            throw new InvalidArgumentException('The scenario channel priority must contain one to eight channels.');
        }

        return new self($normalized);
    }
}
