<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ProviderAccountAffinity
{
    public function __construct(
        public string $accountId,
        public string $hostUserId,
    ) {
        if ($this->accountId === '' || $this->hostUserId === '') {
            throw new InvalidArgumentException('The provider account affinity is invalid.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->accountId === $other->accountId
            && $this->hostUserId === $other->hostUserId;
    }

    /** @return array{account_id: string, host_user_id: string} */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'host_user_id' => $this->hostUserId,
        ];
    }
}
