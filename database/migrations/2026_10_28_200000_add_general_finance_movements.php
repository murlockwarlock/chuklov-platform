<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('account_type', 32)->default('cash');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        Schema::create('finance_expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active', 'sort_order']);
        });

        Schema::table('financial_ledger_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('obligation_id')->nullable()->change();
            $table->unsignedBigInteger('account_id')->nullable()->after('obligation_id');
            $table->unsignedBigInteger('expense_category_id')->nullable()->after('account_id');
            $table->index(['organization_id', 'account_id', 'occurred_at'], 'financial_ledger_org_account_occurred_index');
            $table->index(['organization_id', 'expense_category_id', 'occurred_at'], 'financial_ledger_org_expense_occurred_index');
            $table->foreign(['organization_id', 'account_id'], 'financial_ledger_org_account_fk')
                ->references(['organization_id', 'id'])
                ->on('finance_accounts')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'expense_category_id'], 'financial_ledger_org_expense_category_fk')
                ->references(['organization_id', 'id'])
                ->on('finance_expense_categories')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE finance_accounts ADD CONSTRAINT finance_accounts_type_check CHECK (account_type IN (\'cash\', \'bank\', \'other\') AND currency ~ \'^[A-Z]{3}$\' AND name <> \'\')');
            DB::statement('ALTER TABLE finance_expense_categories ADD CONSTRAINT finance_expense_categories_name_check CHECK (name <> \'\')');
            DB::statement('ALTER TABLE financial_ledger_entries DROP CONSTRAINT IF EXISTS financial_ledger_entries_shape_check');
            DB::statement("ALTER TABLE financial_ledger_entries ADD CONSTRAINT financial_ledger_entries_shape_check CHECK (
                (entry_type = 'manual_payment' AND source = 'crm' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL)
                OR (entry_type = 'fake_gateway_settlement' AND source = 'fake_gateway' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL)
                OR (entry_type = 'gateway_settlement' AND source = 'payment_gateway' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND corrects_ledger_entry_id IS NULL)
                OR (entry_type = 'referral_credit' AND source = 'referral' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND payment_method = 'referral_credit' AND corrects_ledger_entry_id IS NULL)
                OR (entry_type = 'gift_certificate_redemption' AND source = 'gift_certificate' AND amount_minor > 0 AND payment_amount_minor > 0 AND settlement_amount_minor > 0 AND payment_method IS NULL AND corrects_ledger_entry_id IS NULL)
                OR (entry_type = 'expense' AND source = 'crm' AND amount_minor < 0 AND payment_amount_minor < 0 AND settlement_amount_minor < 0 AND obligation_id IS NULL AND account_id IS NOT NULL AND expense_category_id IS NOT NULL AND corrects_ledger_entry_id IS NULL)
                OR (entry_type = 'correction' AND source = 'crm' AND amount_minor < 0 AND payment_amount_minor < 0 AND settlement_amount_minor < 0 AND corrects_ledger_entry_id IS NOT NULL)
            )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE financial_ledger_entries DROP CONSTRAINT IF EXISTS financial_ledger_entries_shape_check');
        }

        Schema::table('financial_ledger_entries', function (Blueprint $table): void {
            $table->dropForeign('financial_ledger_org_account_fk');
            $table->dropForeign('financial_ledger_org_expense_category_fk');
            $table->dropIndex('financial_ledger_org_account_occurred_index');
            $table->dropIndex('financial_ledger_org_expense_occurred_index');
            $table->dropColumn(['account_id', 'expense_category_id']);
            $table->unsignedBigInteger('obligation_id')->nullable(false)->change();
        });

        Schema::dropIfExists('finance_expense_categories');
        Schema::dropIfExists('finance_accounts');
    }
};
