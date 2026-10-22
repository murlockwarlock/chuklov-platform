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
    public function test_patient_invitation_tokens_are_redacted_in_commands_urls_and_nested_context(): void
    {
        foreach (['A', '-', '_'] as $lastCharacter) {
            $token = str_repeat('A', 42).$lastCharacter;
            $record = new LogRecord(datetime: new DateTimeImmutable, channel: 'testing', level: Level::Info,
                message: '/start family_'.$token,
                context: ['url' => 'https://t.me/examplebot?start=family_'.$token, 'nested' => ['text' => 'family_'.$token]]);
            $redacted = (new RedactSensitiveLogData)($record);
            self::assertStringNotContainsString($token, $redacted->message);
            self::assertStringNotContainsString($token, $redacted->context['url']);
            self::assertStringNotContainsString($token, $redacted->context['nested']['text']);
        }
    }

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

    public function test_safe_operation_codes_remain_searchable_while_clinical_operation_text_is_redacted(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'testing',
            level: Level::Warning,
            message: 'portal.booking.denied',
            context: [
                'operation' => 'booking.create',
                'clinical_operation' => 'appendectomy recovery notes',
                'operations' => 'appendectomy in 2020',
                'operations_injuries' => 'patient injury details',
            ],
        );

        $redacted = (new RedactSensitiveLogData)($record);

        self::assertSame('booking.create', $redacted->context['operation']);
        self::assertSame('[REDACTED]', $redacted->context['clinical_operation']);
        self::assertSame('[REDACTED]', $redacted->context['operations']);
        self::assertSame('[REDACTED]', $redacted->context['operations_injuries']);
    }

    public function test_unrecognized_operation_values_are_redacted(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'testing',
            level: Level::Warning,
            message: 'clinical event',
            context: ['operation' => 'patient had surgery and described symptoms'],
        );

        $redacted = (new RedactSensitiveLogData)($record);

        self::assertSame('[REDACTED]', $redacted->context['operation']);
    }

    public function test_known_operation_codes_remain_available_for_diagnostics(): void
    {
        foreach ([
            'booking.create',
            'cancel',
            'client.email_auth.code_delivery',
            'client.portal.logout',
            'create',
            'download',
            'fake_gateway_initiation',
            'financial_correction',
            'gateway_initiation',
            'gift_certificate_correction',
            'gift_certificate_redemption',
            'manual_payment',
            'portal.medical-attachments.download',
            'portal.medical-attachments.upload',
            'reconcile',
            'recreate',
            'referral_credit_redemption',
            'referral_credit_restore',
            'replacement',
            'request_referral_payout',
            'start_survey_attempt',
            'upload',
            'update',
            'view_survey_attempt',
        ] as $operation) {
            $record = new LogRecord(
                datetime: new DateTimeImmutable,
                channel: 'testing',
                level: Level::Warning,
                message: 'operation completed',
                context: ['operation' => $operation],
            );

            $redacted = (new RedactSensitiveLogData)($record);

            self::assertSame($operation, $redacted->context['operation']);
        }
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
