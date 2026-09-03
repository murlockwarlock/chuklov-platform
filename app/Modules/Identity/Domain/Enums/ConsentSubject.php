<?php

namespace App\Modules\Identity\Domain\Enums;

enum ConsentSubject: string
{
    case Offer = 'offer';
    case Privacy = 'privacy';
    case MedicalDisclaimer = 'medical_disclaimer';
    case Marketing = 'marketing';

    public function isRequired(): bool
    {
        return $this !== self::Marketing;
    }

    public function label(string $locale = 'ru'): string
    {
        if ($locale === 'en') {
            return match ($this) {
                self::Offer => 'Offer',
                self::Privacy => 'Privacy policy',
                self::MedicalDisclaimer => 'Medical disclaimer',
                self::Marketing => 'Marketing messages',
            };
        }

        return match ($this) {
            self::Offer => 'Оферта',
            self::Privacy => 'Политика конфиденциальности',
            self::MedicalDisclaimer => 'Медицинский дисклеймер',
            self::Marketing => 'Маркетинговые сообщения',
        };
    }
}
