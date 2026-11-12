<?php

namespace App\Modules\Specialists\Application;

use App\Modules\Identity\Application\ProfileReferenceData;
use App\Modules\Identity\Domain\ValueObjects\NormalizedEmail;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistContactProfile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class NormalizeSpecialistContacts
{
    public function __construct(private readonly ProfileReferenceData $referenceData) {}

    public function handle(
        mixed $phone,
        mixed $email,
        mixed $telegram,
        mixed $phoneVisible,
        mixed $emailVisible,
        mixed $telegramVisible,
        ?string $country,
    ): SpecialistContactProfile {
        $normalizedPhone = $this->nullableString($phone);
        if ($normalizedPhone !== null) {
            try {
                $normalizedPhone = $this->referenceData->phone($normalizedPhone, $country);
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'phone' => ['Введите корректный номер телефона с кодом страны.'],
                ]);
            }
        }

        $normalizedEmail = $this->nullableString($email);
        if ($normalizedEmail !== null) {
            try {
                $normalizedEmail = NormalizedEmail::from($normalizedEmail)->value;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'email' => ['Введите корректный email.'],
                ]);
            }
        }

        return new SpecialistContactProfile(
            phone: $normalizedPhone,
            email: $normalizedEmail,
            telegram: $this->telegram($telegram),
            phoneVisible: (bool) $phoneVisible,
            emailVisible: (bool) $emailVisible,
            telegramVisible: (bool) $telegramVisible,
        );
    }

    private function telegram(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $username = null;
        if (preg_match('/\A@?([A-Za-z0-9_]{5,32})\z/', $value, $matches) === 1) {
            $username = $matches[1];
        } elseif (preg_match('~\Ahttps://(?:t\.me|telegram\.me)/@?([A-Za-z0-9_]{5,32})/?\z~i', $value, $matches) === 1) {
            $username = $matches[1];
        }

        if ($username === null) {
            throw ValidationException::withMessages([
                'telegram' => ['Укажите username или ссылку на профиль Telegram, например @username.'],
            ]);
        }

        return '@'.$username;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                'contacts' => ['Контакты должны быть указаны обычным текстом.'],
            ]);
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
