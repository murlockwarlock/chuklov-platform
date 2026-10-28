<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\FinanceExpenseEntry;
use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\FinanceAnalytics;
use App\Modules\Finance\Application\FinanceMovementConfiguration;
use App\Modules\Finance\Application\RecordExpense;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Models\FinanceAccount;
use App\Modules\Finance\Domain\Models\FinanceExpenseCategory;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FinanceMovementAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expenses_are_append_only_ledger_movements_and_are_idempotent(): void
    {
        [$organization, $admin] = $this->fixture();
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        app(FinanceMovementConfiguration::class)->save($admin, [
            ['name' => 'Касса', 'account_type' => 'cash', 'currency' => 'USD', 'is_active' => true, 'sort_order' => 1],
        ], [
            ['name' => 'Аренда', 'is_active' => true, 'sort_order' => 1],
        ]);
        $account = FinanceAccount::query()->where('organization_id', $organization->getKey())->sole();
        $category = FinanceExpenseCategory::query()->where('organization_id', $organization->getKey())->sole();

        $first = app(RecordExpense::class)->handle($admin, $account, $category, '25.00', '2026-10-10 10:00:00', 'Синтетический расход', 'expense-1');
        $replay = app(RecordExpense::class)->handle($admin, $account, $category, '25.00', '2026-10-10 10:00:00', 'Синтетический расход', 'expense-1');

        self::assertSame($first->getKey(), $replay->getKey());
        self::assertSame(-2500, $first->amount_minor);
        self::assertNull($first->obligation_id);
        self::assertSame($account->getKey(), $first->account_id);
        self::assertSame($category->getKey(), $first->expense_category_id);
        self::assertSame(1, FinancialLedgerEntry::query()->where('organization_id', $organization->getKey())->count());

        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $data = app(FinanceAnalytics::class)->handle($admin, $period);

        self::assertTrue($data->available);
        self::assertSame('0', $data->revenueMinor);
        self::assertSame('2500', $data->expensesMinor);
        self::assertSame('-2500', $data->cashMovementMinor);
        self::assertSame('-2500', $data->accounts[0]['movement_minor']);
    }

    public function test_finance_movement_configuration_is_tenant_scoped(): void
    {
        [$organization, $admin] = $this->fixture();
        [$otherOrganization, $otherAdmin] = $this->fixture();
        app(OrganizationContext::class)->set($organization);
        app(FinanceMovementConfiguration::class)->save($admin, [
            ['name' => 'Касса', 'account_type' => 'cash', 'currency' => 'USD', 'is_active' => true, 'sort_order' => 1],
        ], []);
        $foreignAccount = FinanceAccount::query()->where('organization_id', $organization->getKey())->sole();
        app(OrganizationContext::class)->set($otherOrganization);

        $this->expectException(ModelNotFoundException::class);
        app(FinanceMovementConfiguration::class)->save($otherAdmin, [
            ['id' => $foreignAccount->getKey(), 'name' => 'Чужая касса', 'account_type' => 'cash', 'currency' => 'USD', 'is_active' => true, 'sort_order' => 1],
        ], []);
    }

    public function test_crm_expense_form_records_a_movement_using_configured_account_and_category(): void
    {
        [$organization, $admin] = $this->fixture();
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        app(FinanceMovementConfiguration::class)->save($admin, [
            ['name' => 'Касса', 'account_type' => 'cash', 'currency' => 'USD', 'is_active' => true, 'sort_order' => 1],
        ], [
            ['name' => 'Аренда', 'is_active' => true, 'sort_order' => 1],
        ]);
        $account = FinanceAccount::query()->where('organization_id', $organization->getKey())->sole();
        $category = FinanceExpenseCategory::query()->where('organization_id', $organization->getKey())->sole();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(FinanceExpenseEntry::class)
            ->fillForm([
                'account_id' => $account->getKey(),
                'category_id' => $category->getKey(),
                'amount' => '12.50',
                'occurred_at' => '2026-10-10 10:00',
                'note' => 'Аренда кабинета',
            ])
            ->assertHasNoFormErrors()
            ->call('save');
        $component->assertNotified('Расход записан');

        self::assertSame(-1250, FinancialLedgerEntry::query()->where('organization_id', $organization->getKey())->sole()->amount_minor);
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
