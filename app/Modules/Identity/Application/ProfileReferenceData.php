<?php

namespace App\Modules\Identity\Application;

use Illuminate\Validation\ValidationException;

final class ProfileReferenceData
{
    /** @var array<string, array{names: array{ru: string, en: string}, callingCode: string, nationalPrefix: string, leading: string, pattern: string}>|null */
    private ?array $countries = null;

    public function countryCode(string $country): ?string
    {
        $country = mb_strtolower(trim($country));
        foreach ($this->countries() as $code => $data) {
            if ($country === mb_strtolower($code) || in_array($country, array_map(mb_strtolower(...), $data['names']), true)) {
                return $code;
            }
        }

        return null;
    }

    public function phone(string $value, ?string $country): string
    {
        $value = trim($value);
        if (preg_match('/^[+\d\s().\-]+$/D', $value) !== 1) {
            throw $this->invalidPhone();
        }
        $digits = preg_replace('/\D/', '', $value);
        if (! is_string($digits) || strlen($digits) > 15 || strlen($digits) < 5) {
            throw $this->invalidPhone();
        }
        $countries = $this->countries();
        $selected = $this->countryCode($country ?? '');
        if (! str_starts_with($value, '+')) {
            if ($selected === null) {
                throw $this->invalidPhone();
            }
            $metadata = $countries[$selected];
            $national = $digits;
            if (! $this->matches($national, $metadata) && $metadata['nationalPrefix'] !== ''
                && str_starts_with($national, $metadata['nationalPrefix'])) {
                $national = substr($national, strlen($metadata['nationalPrefix']));
            }
            if ($this->matches($national, $metadata)) {
                return '+'.$metadata['callingCode'].$national;
            }
        }
        foreach ($countries as $metadata) {
            if (str_starts_with($digits, $metadata['callingCode'])
                && $this->matches(substr($digits, strlen($metadata['callingCode'])), $metadata)) {
                return '+'.$digits;
            }
        }

        throw $this->invalidPhone();
    }

    /** @return list<string> */
    public function cities(string $country, string $query, string $locale): array
    {
        $code = $this->countryCode($country);
        if ($code === null || mb_strlen($query) > 100) {
            return [];
        }
        $path = resource_path('data/profile/cities/'.$code.'.json');
        if (! is_file($path)) {
            return [];
        }
        $rows = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $query = mb_strtolower(trim($query));
        $result = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['ru'] ?? null)) {
                continue;
            }
            $names = [$row['name'], $row['ru'], ...is_array($row['aliases'] ?? null) ? $row['aliases'] : []];
            if ($query !== '' && ! array_any($names, static fn (mixed $name): bool => is_string($name) && str_contains(mb_strtolower($name), $query))) {
                continue;
            }
            $name = $locale === 'ru' ? $row['ru'] : $row['name'];
            if (mb_strlen($name) <= 100 && ! in_array($name, $result, true)) {
                $result[] = $name;
            }
            if (count($result) >= 25) {
                break;
            }
        }

        return $result;
    }

    /** @return array<string, array{names: array{ru: string, en: string}, callingCode: string, nationalPrefix: string, leading: string, pattern: string}> */
    private function countries(): array
    {
        if ($this->countries === null) {
            $countries = json_decode((string) file_get_contents(resource_path('data/profile/phones.json')), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($countries)) {
                throw new \LogicException('Phone reference metadata must be an array.');
            }
            $this->countries = [];
            foreach ($countries as $code => $metadata) {
                if (! is_string($code) || preg_match('/^[A-Z]{2}$/D', $code) !== 1 || ! is_array($metadata)
                    || ! is_array($metadata['names'] ?? null) || ! is_string($metadata['names']['ru'] ?? null)
                    || ! is_string($metadata['names']['en'] ?? null)) {
                    throw new \LogicException('Invalid phone reference country.');
                }
                foreach (['callingCode', 'nationalPrefix', 'leading', 'pattern'] as $field) {
                    if (! is_string($metadata[$field] ?? null)) {
                        throw new \LogicException('Invalid phone reference metadata.');
                    }
                }
                $this->countries[$code] = [
                    'names' => ['ru' => $metadata['names']['ru'], 'en' => $metadata['names']['en']],
                    'callingCode' => $metadata['callingCode'],
                    'nationalPrefix' => $metadata['nationalPrefix'],
                    'leading' => $metadata['leading'],
                    'pattern' => $metadata['pattern'],
                ];
            }
        }

        return $this->countries;
    }

    /** @param array{leading: string, pattern: string} $metadata */
    private function matches(string $national, array $metadata): bool
    {
        return preg_match('~^(?:'.$metadata['pattern'].')$~D', $national) === 1
            && ($metadata['leading'] === '' || preg_match('~^(?:'.$metadata['leading'].')~', $national) === 1);
    }

    private function invalidPhone(): ValidationException
    {
        return ValidationException::withMessages(['phone' => __('Введите корректный номер телефона с кодом страны.')]);
    }
}
