<?php

namespace App\Modules\Finance\Domain\Services;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\CurrencyDefinition;
use InvalidArgumentException;

final class CurrencyCatalog
{
    /** @var array<string, array{scale: int, name: string}> */
    private const DEFINITIONS = [
        'AED' => ['scale' => 2, 'name' => 'Дирхам ОАЭ'],
        'CNY' => ['scale' => 2, 'name' => 'Китайский юань'],
        'EUR' => ['scale' => 2, 'name' => 'Евро'],
        'GBP' => ['scale' => 2, 'name' => 'Фунт стерлингов'],
        'JPY' => ['scale' => 0, 'name' => 'Японская иена'],
        'KZT' => ['scale' => 2, 'name' => 'Казахстанский тенге'],
        'RUB' => ['scale' => 2, 'name' => 'Российский рубль'],
        'THB' => ['scale' => 2, 'name' => 'Тайский бат'],
        'TRY' => ['scale' => 2, 'name' => 'Турецкая лира'],
        'USD' => ['scale' => 2, 'name' => 'Доллар США'],
    ];

    public function definition(mixed $currency): CurrencyDefinition
    {
        $code = $currency instanceof CurrencyCode
            ? $currency
            : (is_string($currency) ? CurrencyCode::tryFrom(strtoupper(trim($currency))) : null);

        if ($code === null) {
            throw new InvalidArgumentException('The currency is not supported.');
        }

        $definition = self::DEFINITIONS[$code->value];

        return new CurrencyDefinition($code, $definition['scale'], $definition['name']);
    }

    public function code(mixed $currency): CurrencyCode
    {
        return $this->definition($currency)->code;
    }

    /** @return int<0, max> */
    public function scale(CurrencyCode|string $currency): int
    {
        return $this->definition($currency)->scale;
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return array_map(
            static fn (array $definition): string => $definition['name'],
            self::DEFINITIONS,
        );
    }

    /** @return list<CurrencyCode> */
    public function codes(): array
    {
        return array_map(
            static fn (string $code): CurrencyCode => CurrencyCode::from($code),
            array_keys(self::DEFINITIONS),
        );
    }
}
