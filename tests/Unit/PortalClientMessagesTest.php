<?php

namespace Tests\Unit;

use App\Modules\ClientPortal\Application\PortalClientMessages;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PortalClientMessagesTest extends TestCase
{
    public function test_client_messages_follow_the_application_locale(): void
    {
        app()->setLocale('ru');
        self::assertSame('Код неверный или уже истёк.', app(PortalClientMessages::class)->message('email_code_invalid'));

        app()->setLocale('en');
        self::assertSame('The code is incorrect or has expired.', app(PortalClientMessages::class)->message('email_code_invalid'));
    }

    public function test_validation_messages_and_application_errors_are_localized(): void
    {
        app()->setLocale('en');
        $messages = app(PortalClientMessages::class);

        self::assertSame(
            'Enter a positive amount in a supported format.',
            $messages->validationMessages('payout')['amount.regex'],
        );
        self::assertSame(
            ['amount' => ['The amount exceeds the available balance.']],
            $messages->validationException(
                'payout',
                ValidationException::withMessages(['amount' => 'Сумма превышает доступный остаток.']),
            ),
        );
    }
}
