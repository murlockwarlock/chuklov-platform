<?php

namespace Tests\Unit;

use App\Modules\Finance\Domain\Services\PaymentGatewayEventIdentity;
use PHPUnit\Framework\TestCase;

final class PaymentGatewayEventIdentityTest extends TestCase
{
    public function test_payment_event_key_is_deterministic_without_timestamp(): void
    {
        self::assertSame(
            'lava:payment.success:7ea82675-4ded-4133-95a7-a6efbaf165cc',
            PaymentGatewayEventIdentity::paymentKey(
                'lava',
                'payment.success',
                '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            ),
        );
    }

    public function test_payload_hash_is_stable_for_associative_key_order_and_changes_for_meaningful_data(): void
    {
        $first = PaymentGatewayEventIdentity::payloadHash([
            'eventType' => 'payment.success',
            'contractId' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            'amount' => 12.5,
        ]);
        $same = PaymentGatewayEventIdentity::payloadHash([
            'amount' => 12.5,
            'contractId' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            'eventType' => 'payment.success',
        ]);
        $different = PaymentGatewayEventIdentity::payloadHash([
            'amount' => 12.51,
            'contractId' => '7ea82675-4ded-4133-95a7-a6efbaf165cc',
            'eventType' => 'payment.success',
        ]);

        self::assertSame($first, $same);
        self::assertNotSame($first, $different);
    }
}
