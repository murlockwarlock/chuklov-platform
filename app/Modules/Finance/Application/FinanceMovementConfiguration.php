<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Models\FinanceAccount;
use App\Modules\Finance\Domain\Models\FinanceExpenseCategory;
use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceMovementConfiguration
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly CurrencyCatalog $catalog,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @return array{accounts: list<array<string, mixed>>, categories: list<array<string, mixed>>} */
    public function values(): array
    {
        $organizationId = $this->context->id();

        return [
            'accounts' => FinanceAccount::query()
                ->where('organization_id', $organizationId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(static fn (FinanceAccount $account): array => [
                    'id' => $account->getKey(),
                    'name' => $account->getRawOriginal('name'),
                    'account_type' => $account->getRawOriginal('account_type'),
                    'currency' => $account->getRawOriginal('currency'),
                    'is_active' => (bool) $account->getRawOriginal('is_active'),
                    'sort_order' => (int) $account->getRawOriginal('sort_order'),
                ])
                ->all(),
            'categories' => FinanceExpenseCategory::query()
                ->where('organization_id', $organizationId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(static fn (FinanceExpenseCategory $category): array => [
                    'id' => $category->getKey(),
                    'name' => $category->getRawOriginal('name'),
                    'is_active' => (bool) $category->getRawOriginal('is_active'),
                    'sort_order' => (int) $category->getRawOriginal('sort_order'),
                ])
                ->all(),
        ];
    }

    /** @param array<int, mixed> $accounts
     * @param  array<int, mixed>  $categories
     */
    public function save(User $actor, array $accounts, array $categories): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageFinance);
        $normalizedAccounts = $this->accounts($accounts);
        $normalizedCategories = $this->categories($categories);

        DB::transaction(function () use ($organization, $actor, $normalizedAccounts, $normalizedCategories): void {
            $accountIds = [];
            foreach ($normalizedAccounts as $accountData) {
                $account = $this->account($accountData['id'] ?? null, (int) $organization->getKey());
                $account->forceFill([
                    'organization_id' => $organization->getKey(),
                    'name' => $accountData['name'],
                    'account_type' => $accountData['account_type'],
                    'currency' => $accountData['currency'],
                    'is_active' => $accountData['is_active'],
                    'sort_order' => $accountData['sort_order'],
                ])->save();
                $accountIds[] = $account->getKey();
            }
            FinanceAccount::query()
                ->where('organization_id', $organization->getKey())
                ->when($accountIds !== [], fn ($query) => $query->whereNotIn('id', $accountIds))
                ->update(['is_active' => false]);

            $categoryIds = [];
            foreach ($normalizedCategories as $categoryData) {
                $category = $this->category($categoryData['id'] ?? null, (int) $organization->getKey());
                $category->forceFill([
                    'organization_id' => $organization->getKey(),
                    'name' => $categoryData['name'],
                    'is_active' => $categoryData['is_active'],
                    'sort_order' => $categoryData['sort_order'],
                ])->save();
                $categoryIds[] = $category->getKey();
            }
            FinanceExpenseCategory::query()
                ->where('organization_id', $organization->getKey())
                ->when($categoryIds !== [], fn ($query) => $query->whereNotIn('id', $categoryIds))
                ->update(['is_active' => false]);

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'finance.movement.configuration.updated',
                targetType: FinanceAccount::class,
                targetId: 'finance-movement-configuration',
                metadata: [
                    'account_count' => count($normalizedAccounts),
                    'category_count' => count($normalizedCategories),
                ],
            );
        });
    }

    /** @return array<string, string> */
    public function accountTypeOptions(): array
    {
        return [
            'cash' => __('Касса'),
            'bank' => __('Банковский счёт'),
            'other' => __('Другое'),
        ];
    }

    /** @return array<string, string> */
    public function currencyOptions(): array
    {
        return $this->catalog->options();
    }

    /** @return list<array{id: int|null, name: string, account_type: string, currency: string, is_active: bool, sort_order: int}> */
    private function accounts(array $values): array
    {
        $normalized = [];
        $names = [];
        foreach (array_values($values) as $index => $value) {
            if (! is_array($value)) {
                throw ValidationException::withMessages(['accounts' => 'Проверьте счета и кассы.']);
            }
            $name = trim((string) ($value['name'] ?? ''));
            $type = (string) ($value['account_type'] ?? '');
            $currency = (string) ($value['currency'] ?? '');
            if ($name === '' || mb_strlen($name) > 160 || isset($names[mb_strtolower($name)])) {
                throw ValidationException::withMessages(['accounts' => 'Названия счетов должны быть заполнены и уникальны.']);
            }
            if (! array_key_exists($type, $this->accountTypeOptions())) {
                throw ValidationException::withMessages(['accounts' => 'Выберите тип счёта.']);
            }
            try {
                $currency = $this->catalog->code($currency)->value;
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['accounts' => 'Выберите валюту счёта.']);
            }
            $names[mb_strtolower($name)] = true;
            $normalized[] = [
                'id' => isset($value['id']) && is_numeric($value['id']) ? (int) $value['id'] : null,
                'name' => $name,
                'account_type' => $type,
                'currency' => $currency,
                'is_active' => (bool) ($value['is_active'] ?? true),
                'sort_order' => max(0, (int) ($value['sort_order'] ?? $index)),
            ];
        }

        return $normalized;
    }

    /** @return list<array{id: int|null, name: string, is_active: bool, sort_order: int}> */
    private function categories(array $values): array
    {
        $normalized = [];
        $names = [];
        foreach (array_values($values) as $index => $value) {
            if (! is_array($value)) {
                throw ValidationException::withMessages(['categories' => 'Проверьте категории расходов.']);
            }
            $name = trim((string) ($value['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 160 || isset($names[mb_strtolower($name)])) {
                throw ValidationException::withMessages(['categories' => 'Названия категорий расходов должны быть заполнены и уникальны.']);
            }
            $names[mb_strtolower($name)] = true;
            $normalized[] = [
                'id' => isset($value['id']) && is_numeric($value['id']) ? (int) $value['id'] : null,
                'name' => $name,
                'is_active' => (bool) ($value['is_active'] ?? true),
                'sort_order' => max(0, (int) ($value['sort_order'] ?? $index)),
            ];
        }

        return $normalized;
    }

    private function account(?int $id, int $organizationId): FinanceAccount
    {
        if ($id === null) {
            return new FinanceAccount;
        }

        return FinanceAccount::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function category(?int $id, int $organizationId): FinanceExpenseCategory
    {
        if ($id === null) {
            return new FinanceExpenseCategory;
        }

        return FinanceExpenseCategory::query()
            ->where('organization_id', $organizationId)
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
