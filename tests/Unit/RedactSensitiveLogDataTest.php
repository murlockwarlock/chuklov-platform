<?php

namespace Tests\Unit;

use App\Modules\Security\Infrastructure\Logging\RedactSensitiveLogData;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class RedactSensitiveLogDataTest extends TestCase
{
    public function test_sensitive_context_and_message_values_are_redacted(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'password=plain-password token {token}',
            context: [
                'token' => 'plain-token',
                'nested' => ['authorization' => 'plain-authorization'],
                'safe_id' => 'safe-value',
            ],
        );

        $redacted = (new RedactSensitiveLogData)($record);

        self::assertStringNotContainsString('plain-password', $redacted->message);
        self::assertSame('[REDACTED]', $redacted->context['token']);
        self::assertSame('[REDACTED]', $redacted->context['nested']['authorization']);
        self::assertSame('safe-value', $redacted->context['safe_id']);
    }
}
