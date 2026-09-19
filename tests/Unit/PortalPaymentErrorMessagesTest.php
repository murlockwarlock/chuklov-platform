<?php

namespace Tests\Unit;

use App\Modules\ClientPortal\Application\PortalPaymentErrorMessages;
use App\Modules\Finance\Domain\Exceptions\PaymentGatewayConfigurationException;
use App\Modules\Finance\Domain\Exceptions\PaymentGatewayProviderException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PortalPaymentErrorMessagesTest extends TestCase
{
    #[DataProvider('gatewayMessages')]
    public function test_gateway_failure_is_presented_safely_in_the_portal_locale(
        string $locale,
        string $expected,
        bool $ambiguous,
    ): void {
        app()->setLocale($locale);
        $exception = $ambiguous
            ? new PaymentGatewayProviderException('provider_timeout', 'legacy fallback', true, 'operator')
            : new PaymentGatewayConfigurationException('missing_credential', 'legacy fallback', true, 'operator');

        self::assertSame($expected, app(PortalPaymentErrorMessages::class)->gateway($exception));
    }

    public function test_validation_errors_are_mapped_without_exposing_internal_messages(): void
    {
        app()->setLocale('en');
        $exception = ValidationException::withMessages([
            'idempotency_key' => 'This key is already used internally.',
        ]);

        self::assertSame(
            'This payment request is already in use. Start a new payment attempt.',
            app(PortalPaymentErrorMessages::class)->validation($exception),
        );
    }

    public function test_payment_checking_message_is_localized(): void
    {
        app()->setLocale('ru');
        self::assertSame(
            'Платёж уже проверяется. Обновите страницу немного позже.',
            app(PortalPaymentErrorMessages::class)->message('payment_checking'),
        );

        app()->setLocale('en');
        self::assertSame(
            'The payment is already being checked. Refresh the page in a moment.',
            app(PortalPaymentErrorMessages::class)->message('payment_checking'),
        );
    }

    public static function gatewayMessages(): array
    {
        return [
            'ru configuration' => ['ru', 'Онлайн-оплата сейчас временно недоступна. Попробуйте позже.', false],
            'en configuration' => ['en', 'Online payment is temporarily unavailable. Please try again later.', false],
            'ru ambiguous' => ['ru', 'Не удалось открыть страницу оплаты. Если деньги уже списались, не оплачивайте повторно — мы проверим платёж.', true],
            'en ambiguous' => ['en', "We couldn't open the payment page. If the money has already been charged, don't pay again — we'll check the payment.", true],
        ];
    }
}
