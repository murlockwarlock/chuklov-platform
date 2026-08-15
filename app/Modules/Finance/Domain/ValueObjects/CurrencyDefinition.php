<?php

namespace App\Modules\Finance\Domain\ValueObjects;

use App\Modules\Finance\Domain\Enums\CurrencyCode;

final readonly class CurrencyDefinition
{
    /** @param int<0, max> $scale */
    public function __construct(
        public CurrencyCode $code,
        public int $scale,
        public string $name,
    ) {}
}
