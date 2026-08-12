<?php

namespace Tests\Unit;

use App\Modules\Security\Infrastructure\Logging\RedactSensitiveLogData;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function test_bearer_authorization_values_are_redacted_as_complete_values(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'Authorization: Bearer exposed-value',
            context: [
                'headers' => [
                    'Authorization' => 'Bearer exposed-value',
                    'X-Api-Key' => 'api-exposed-value',
                ],
                'request_dump' => 'Authorization: Bearer exposed-value',
                'exception' => new RuntimeException('Authorization: Bearer exposed-value'),
            ],
        );

        $redacted = (new RedactSensitiveLogData)($record);

        self::assertStringNotContainsString('exposed-value', $redacted->message);
        self::assertSame('[REDACTED]', $redacted->context['headers']['Authorization']);
        self::assertSame('[REDACTED]', $redacted->context['headers']['X-Api-Key']);
        self::assertStringNotContainsString('exposed-value', $redacted->context['request_dump']);
        self::assertSame('[REDACTED]', $redacted->context['exception']['message']);
        self::assertStringNotContainsString('exposed-value', json_encode($redacted->context, JSON_THROW_ON_ERROR));
    }
}
