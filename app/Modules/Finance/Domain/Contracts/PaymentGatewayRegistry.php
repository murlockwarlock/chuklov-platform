<?php

namespace App\Modules\Finance\Domain\Contracts;

use InvalidArgumentException;

final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    /** @param iterable<PaymentGateway> $gateways */
    public function __construct(iterable $gateways)
    {
        foreach ($gateways as $gateway) {
            $name = $gateway->name();
            if ($name === '' || isset($this->gateways[$name])) {
                throw new InvalidArgumentException('Payment gateway names must be unique and non-empty.');
            }

            $this->gateways[$name] = $gateway;
        }
    }

    public function resolve(string $name): PaymentGateway
    {
        return $this->gateways[$name]
            ?? throw new InvalidArgumentException('The requested payment gateway is not configured.');
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->gateways);
    }
}
