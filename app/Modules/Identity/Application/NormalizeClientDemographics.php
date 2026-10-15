<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class NormalizeClientDemographics
{
    public const FIELDS = ['first_name', 'last_name', 'middle_name', 'gender', 'birth_date', 'country', 'city'];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function handle(array $attributes, ?Client $client = null): array
    {
        $normalized = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];
            if ($value !== null && ! is_string($value)) {
                throw ValidationException::withMessages([$field => 'Проверьте значение поля.']);
            }
            $value = $value === null ? null : trim($value);
            $value = $value === '' ? null : $value;
            if ($value !== null && mb_strlen($value) > 100) {
                throw ValidationException::withMessages([$field => 'Не более 100 символов.']);
            }
            $normalized[$field] = $value;
        }

        if (isset($normalized['gender']) && ! in_array($normalized['gender'], ['MALE', 'FEMALE'], true)) {
            throw ValidationException::withMessages(['gender' => 'Выберите пол.']);
        }
        if (isset($normalized['birth_date'])) {
            $date = $normalized['birth_date'];
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
                || $date > CarbonImmutable::today('UTC')->toDateString()) {
                throw ValidationException::withMessages(['birth_date' => 'Укажите корректную дату рождения.']);
            }
        }

        $nameFields = ['first_name', 'last_name', 'middle_name'];
        if (array_intersect($nameFields, array_keys($normalized)) !== []) {
            $firstName = array_key_exists('first_name', $normalized) ? $normalized['first_name'] : $client?->first_name;
            $lastName = array_key_exists('last_name', $normalized) ? $normalized['last_name'] : $client?->last_name;
            $middleName = array_key_exists('middle_name', $normalized) ? $normalized['middle_name'] : $client?->middle_name;
            if ($firstName !== null || $lastName !== null || $middleName !== null) {
                if ($firstName === null || $lastName === null) {
                    throw ValidationException::withMessages(['first_name' => 'Укажите имя и фамилию.']);
                }
                $fullName = implode(' ', array_filter([$lastName, $firstName, $middleName], static fn (?string $part): bool => $part !== null));
                if (mb_strlen($fullName) > 160) {
                    throw ValidationException::withMessages(['first_name' => 'ФИО должно содержать не более 160 символов.']);
                }
                $normalized['full_name'] = $fullName;
                $normalized['name_confirmed_at'] = ($client === null ? null : $client->name_confirmed_at) ?? CarbonImmutable::now('UTC');
            } else {
                $normalized['name_confirmed_at'] = null;
            }
        } elseif (array_key_exists('full_name', $attributes) && $attributes['full_name'] !== $client?->full_name) {
            $normalized = [...$normalized, 'first_name' => null, 'last_name' => null, 'middle_name' => null, 'name_confirmed_at' => null];
        }

        return $normalized;
    }
}
