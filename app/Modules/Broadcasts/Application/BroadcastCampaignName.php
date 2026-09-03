<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Models\BroadcastCampaign;

final class BroadcastCampaignName
{
    public function copyName(BroadcastCampaign $source, int $organizationId): string
    {
        $baseName = $this->baseName($source->name);

        for ($number = 1; $number <= 1000; $number++) {
            $suffix = $number === 1 ? ' — повтор' : ' — повтор '.$number;
            $candidate = $this->fit($baseName, $suffix);

            if (! BroadcastCampaign::query()
                ->where('organization_id', $organizationId)
                ->where('name', $candidate)
                ->exists()) {
                return $candidate;
            }
        }

        return $this->fit($baseName, ' — повтор 1001');
    }

    public function displayName(?string $name): string
    {
        $value = trim((string) $name);

        if ($value === '') {
            return '';
        }

        $copyCount = (int) preg_match_all('/\s+—\s+копия/iu', $value);

        if ($copyCount === 0 || ! preg_match('/^(.*?)((?:\s+—\s+копия)+)$/iu', $value, $matches)) {
            return $value;
        }

        $baseName = trim($matches[1]);

        return $baseName.' — повтор'.($copyCount > 1 ? ' '.$copyCount : '');
    }

    private function baseName(string $name): string
    {
        $value = trim($name);
        $baseName = preg_replace('/(?:\s+—\s+копия|\s+—\s+повтор(?:\s+\d+)?)+$/iu', '', $value);

        return trim(is_string($baseName) ? $baseName : $value);
    }

    private function fit(string $baseName, string $suffix): string
    {
        return mb_substr($baseName, 0, max(1, 160 - mb_strlen($suffix))).$suffix;
    }
}
