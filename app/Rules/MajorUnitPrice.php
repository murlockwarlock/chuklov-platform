<?php

namespace App\Rules;

use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class MajorUnitPrice implements ValidationRule
{
    public function __construct(private readonly ?string $currency) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) && ! is_int($value)) {
            $fail('Введите цену числом без округления.');

            return;
        }

        if ($this->currency === null || trim($this->currency) === '') {
            $fail('Выберите валюту для цены.');

            return;
        }

        $amount = is_string($value) ? trim($value) : $value;

        try {
            $money = Money::fromDecimal($amount, $this->currency);
        } catch (InvalidArgumentException) {
            $zeroScale = false;

            try {
                $zeroScale = app(CurrencyCatalog::class)->scale($this->currency) === 0;
            } catch (InvalidArgumentException) {
            }

            $fail($zeroScale && is_string($amount) && str_contains($amount, '.')
                ? 'Для выбранной валюты укажите сумму без дробной части.'
                : 'Укажите цену с точностью, доступной для выбранной валюты.');

            return;
        }

        if ($money->isNegative()) {
            $fail('Цена не может быть отрицательной.');
        }
    }
}
