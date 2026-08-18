<?php

namespace App\Modules\AI\Domain\Enums;

enum BudgetReservationStatus: string
{
    case Reserved = 'reserved';
    case Settled = 'settled';
    case Released = 'released';
    case ConservativelyCharged = 'conservatively_charged';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Зарезервировано',
            self::Settled => 'Урегулировано (списано по факту)',
            self::Released => 'Возвращено (освобождено)',
            self::ConservativelyCharged => 'Списано консервативно (при сбое)',
        };
    }
}
