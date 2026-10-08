<?php

namespace Tests\Feature;

use App\Filament\Support\FinancePresentation;
use App\Models\User;
use App\Modules\Finance\Application\CorrectFinancialPayment;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class FinanceBarterPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ru');
    }

    public function test_barter_payment_method_is_available(): void
    {
        self::assertSame('barter', PaymentMethod::Barter->value);
        self::assertSame(PaymentMethod::Barter, PaymentMethod::tryFrom('barter'));
    }

    public function test_barter_payment_creates_a_manual_entry_and_reduces_the_existing_balance(): void
    {
        [$organization, $admin, , $obligation] = $this->fixture();

        $entry = $this->record(
            $admin,
            $obligation,
            '20000.00',
            PaymentMethod::Barter,
            '  Рекламная интеграция  ',
            'barter-partial',
        );

        self::assertSame('manual_payment', $entry->getRawOriginal('entry_type'));
        self::assertSame('barter', $entry->getRawOriginal('payment_method'));
        self::assertSame('Рекламная интеграция', $entry->note);
        self::assertSame(1, FinancialLedgerEntry::query()->where('obligation_id', $obligation->getKey())->count());

        $reconciliation = app(ReconcileFinancialObligation::class)->handle(
            $organization->getKey(),
            $obligation->getKey(),
        );

        self::assertSame(2_000_000, $reconciliation->applied->minorUnits());
        self::assertSame(3_000_000, $reconciliation->outstanding->minorUnits());
        self::assertSame(FinancialStatus::PartiallyPaid, $reconciliation->status);
    }

    public function test_barter_requires_a_trimmed_description_with_the_existing_note_limit(): void
    {
        foreach ([null, '   ', str_repeat('x', 2001)] as $index => $note) {
            [, $admin, , $obligation] = $this->fixture();

            try {
                $this->record(
                    $admin,
                    $obligation,
                    '20000.00',
                    PaymentMethod::Barter,
                    $note,
                    'barter-invalid-'.$index,
                );
                self::fail('The barter payment should be rejected.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('note', $exception->errors());
            }

            self::assertSame(0, FinancialLedgerEntry::query()->count());
        }
    }

    public function test_card_and_barter_payments_are_separate_entries_and_settle_the_same_obligation(): void
    {
        [$organization, $admin, , $obligation] = $this->fixture();

        $card = $this->record(
            $admin,
            $obligation,
            '30000.00',
            PaymentMethod::ManualCard,
            null,
            'mixed-card',
        );
        $barter = $this->record(
            $admin,
            $obligation,
            '20000.00',
            PaymentMethod::Barter,
            'Рекламная интеграция',
            'mixed-barter',
        );

        $reconciliation = app(ReconcileFinancialObligation::class)->handle(
            $organization->getKey(),
            $obligation->getKey(),
        );
        $entries = FinancialLedgerEntry::query()
            ->where('obligation_id', $obligation->getKey())
            ->orderBy('id')
            ->get();

        self::assertNotSame($card->getKey(), $barter->getKey());
        self::assertSame(['manual_card', 'barter'], $entries
            ->map(fn (FinancialLedgerEntry $entry): string => (string) $entry->getRawOriginal('payment_method'))
            ->all());
        self::assertSame(5_000_000, $reconciliation->applied->minorUnits());
        self::assertSame(0, $reconciliation->outstanding->minorUnits());
        self::assertSame(FinancialStatus::Settled, $reconciliation->status);
    }

    public function test_overpayment_with_barter_is_rejected_without_a_ledger_entry(): void
    {
        [, $admin, , $obligation] = $this->fixture();

        try {
            $this->record(
                $admin,
                $obligation,
                '50000.01',
                PaymentMethod::Barter,
                'Рекламная интеграция',
                'barter-overpayment',
            );
            self::fail('The barter overpayment should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amount', $exception->errors());
        }

        self::assertSame(0, FinancialLedgerEntry::query()->count());
    }

    public function test_idempotent_barter_retry_does_not_duplicate_the_entry(): void
    {
        [$organization, $admin, , $obligation] = $this->fixture();

        $first = $this->record(
            $admin,
            $obligation,
            '20000.00',
            PaymentMethod::Barter,
            'Рекламная интеграция',
            'barter-retry',
        );
        $replayed = $this->record(
            $admin,
            $obligation,
            '20000.00',
            PaymentMethod::Barter,
            'Рекламная интеграция',
            'barter-retry',
        );

        self::assertSame($first->getKey(), $replayed->getKey());
        self::assertSame(1, FinancialLedgerEntry::query()->where('obligation_id', $obligation->getKey())->count());
        self::assertSame(
            2_000_000,
            app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey())->applied->minorUnits(),
        );
    }

    public function test_barter_correction_is_append_only_and_restores_the_balance(): void
    {
        [$organization, $admin, , $obligation] = $this->fixture();
        $original = $this->record(
            $admin,
            $obligation,
            '20000.00',
            PaymentMethod::Barter,
            'Рекламная интеграция',
            'barter-correction',
        );

        $correction = app(CorrectFinancialPayment::class)->handle(
            actor: $admin,
            original: $original,
            reason: 'Бартерная операция отменена.',
            idempotencyKey: 'barter-correction-entry',
        );
        $replayedCorrection = app(CorrectFinancialPayment::class)->handle(
            actor: $admin,
            original: $original,
            reason: 'Бартерная операция отменена.',
            idempotencyKey: 'barter-correction-entry',
        );
        $reconciliation = app(ReconcileFinancialObligation::class)->handle(
            $organization->getKey(),
            $obligation->getKey(),
        );
        $original->refresh();

        self::assertSame($correction->getKey(), $replayedCorrection->getKey());
        self::assertSame('barter', $original->getRawOriginal('payment_method'));
        self::assertSame('Рекламная интеграция', $original->note);
        self::assertSame(-2_000_000, $correction->settlement_amount_minor);
        self::assertSame($original->getKey(), $correction->corrects_ledger_entry_id);
        self::assertSame(2, FinancialLedgerEntry::query()->where('obligation_id', $obligation->getKey())->count());
        self::assertSame(0, $reconciliation->applied->minorUnits());
        self::assertSame(5_000_000, $reconciliation->outstanding->minorUnits());
        self::assertSame(FinancialStatus::Outstanding, $reconciliation->status);
    }

    public function test_cross_organization_barter_payment_is_rejected(): void
    {
        [$organization, , , $obligation] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($otherOrganization);

        $this->expectException(AuthorizationException::class);
        app(RecordManualPayment::class)->handle(
            actor: $otherAdmin,
            obligation: $obligation,
            amount: '20000.00',
            currency: 'RUB',
            paymentMethod: PaymentMethod::Barter,
            occurredAt: $this->occurredAt(),
            note: 'Рекламная интеграция',
            receipt: null,
            idempotencyKey: 'barter-cross-org',
        );
    }

    public function test_barter_has_safe_audit_presentation_and_client_finance_label(): void
    {
        [$organization, $admin, $client, $obligation] = $this->fixture();
        $entry = $this->record(
            $admin,
            $obligation,
            '20000.00',
            PaymentMethod::Barter,
            'Рекламная интеграция',
            'barter-presentation',
        );

        self::assertSame('Бартер', app(FinancePresentation::class)->paymentMethodLabel($entry));

        $audit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'finance.manual_payment.recorded')
            ->latest('id')
            ->firstOrFail();
        self::assertSame('barter', $audit->metadata['payment_method']);
        self::assertArrayNotHasKey('note', $audit->metadata);

        $client->forceFill(['language' => 'ru'])->save();
        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.finance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('obligations.0.history.0.methodLabel', 'Бартер'));
    }

    private function record(
        User $admin,
        FinancialObligation $obligation,
        string $amount,
        PaymentMethod $paymentMethod,
        ?string $note,
        string $idempotencyKey,
    ): FinancialLedgerEntry {
        return app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: $amount,
            currency: 'RUB',
            paymentMethod: $paymentMethod,
            occurredAt: $this->occurredAt(),
            note: $note,
            receipt: null,
            idempotencyKey: $idempotencyKey,
        );
    }

    /** @return array{Organization, User, Client, FinancialObligation} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->createOne([
            'price_minor' => 5_000_000,
            'price_currency' => 'RUB',
        ]);
        $service = Service::query()->whereKey($service->getKey())->firstOrFail();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->subHour(),
                'blocking_ends_at' => now()->subHour(),
            ]);
        $this->setOrganization($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        $completed = app(CompleteBooking::class)->handle($admin, $booking);

        return [
            $organization,
            $admin,
            $client,
            FinancialObligation::query()->where('booking_id', $completed->getKey())->firstOrFail(),
        ];
    }

    private function occurredAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-08 10:00:00', 'UTC');
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
