<?php

namespace App\Modules\Analytics\Application\Data;

final readonly class SourceBucket
{
    public function __construct(
        public string $label,
        public int $count,
    ) {}
}
