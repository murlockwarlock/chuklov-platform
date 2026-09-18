<?php

namespace App\Modules\Finance\Domain\Exceptions;

interface PaymentGatewayInitiationFailure
{
    public function reasonCode(): string;

    public function clientMessage(): string;

    public function shouldNotifyOperations(): bool;
}
