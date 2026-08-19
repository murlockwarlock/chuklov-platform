<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ServicePriceCurrencyPair implements ValidationRule
{
    public function __construct(private readonly mixed $price) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $hasPrice = is_string($this->price) && trim($this->price) !== '';
        $hasCurrency = is_string($value) && trim($value) !== '';

        if ($hasPrice !== $hasCurrency) {
            $fail('Укажите цену и валюту вместе.');
        }
    }
}
