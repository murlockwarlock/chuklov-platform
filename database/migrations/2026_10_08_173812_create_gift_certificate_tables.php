<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id');
            $table->foreignId('purchase_item_id');
            $table->foreignId('purchase_fulfillment_id');
            $table->foreignId('purchaser_client_id');
            $table->foreignId('current_holder_client_id')->nullable();
            $table->bigInteger('original_amount_minor');
            $table->char('currency', 3);
            $table->timestampTz('issued_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'purchase_item_id']);
            $table->unique(['organization_id', 'purchase_fulfillment_id']);
            $table->index(['organization_id', 'current_holder_client_id']);
            $table->foreign(['organization_id', 'purchase_id'])
                ->references(['organization_id', 'id'])
                ->on('commerce_purchases')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'purchase_item_id'])
                ->references(['organization_id', 'id'])
                ->on('commerce_purchase_items')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'purchase_fulfillment_id'])
                ->references(['organization_id', 'id'])
                ->on('commerce_purchase_fulfillments')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'purchaser_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'current_holder_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('gift_certificate_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_id');
            $table->foreignId('initiated_by_client_id');
            $table->foreignId('claimed_client_id')->nullable();
            $table->char('token_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'certificate_id', 'id'], 'gift_certificate_claims_org_certificate_id_unique');
            $table->unique(['organization_id', 'token_hash']);
            $table->index(['organization_id', 'certificate_id', 'status']);
            $table->foreign(['organization_id', 'certificate_id'])
                ->references(['organization_id', 'id'])
                ->on('gift_certificates')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'initiated_by_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'claimed_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
        });

        Schema::create('gift_certificate_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_id');
            $table->foreignId('holder_client_id');
            $table->foreignId('financial_obligation_id');
            $table->foreignId('financial_ledger_entry_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('idempotency_key', 180);
            $table->timestampTz('occurred_at');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'certificate_id', 'id'], 'gift_certificate_redemptions_org_certificate_id_unique');
            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['organization_id', 'financial_ledger_entry_id']);
            $table->foreign(['organization_id', 'certificate_id'])
                ->references(['organization_id', 'id'])
                ->on('gift_certificates')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'holder_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_obligation_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_obligations')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'financial_ledger_entry_id'])
                ->references(['organization_id', 'id'])
                ->on('financial_ledger_entries')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->index(['organization_id', 'certificate_id', 'occurred_at']);
        });

        Schema::create('gift_certificate_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_id');
            $table->string('movement_type', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignId('from_holder_client_id')->nullable();
            $table->foreignId('to_holder_client_id')->nullable();
            $table->foreignId('claim_id')->nullable();
            $table->foreignId('redemption_id')->nullable();
            $table->foreignId('reverses_movement_id')->nullable();
            $table->foreignId('actor_user_id')->nullable();
            $table->string('idempotency_key', 180);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'certificate_id', 'id'], 'gift_certificate_movements_org_certificate_id_unique');
            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['organization_id', 'redemption_id', 'movement_type']);
            $table->unique(['organization_id', 'reverses_movement_id']);
            $table->foreign(['organization_id', 'certificate_id'])
                ->references(['organization_id', 'id'])
                ->on('gift_certificates')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'from_holder_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'to_holder_client_id'])
                ->references(['organization_id', 'id'])
                ->on('clients')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'certificate_id', 'claim_id'], 'gift_certificate_movements_org_certificate_claim_foreign')
                ->references(['organization_id', 'certificate_id', 'id'])
                ->on('gift_certificate_claims')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'certificate_id', 'redemption_id'], 'gift_certificate_movements_org_certificate_redemption_foreign')
                ->references(['organization_id', 'certificate_id', 'id'])
                ->on('gift_certificate_redemptions')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'certificate_id', 'reverses_movement_id'], 'gift_certificate_movements_org_certificate_reverses_foreign')
                ->references(['organization_id', 'certificate_id', 'id'])
                ->on('gift_certificate_movements')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'actor_user_id'])
                ->references(['organization_id', 'user_id'])
                ->on('organization_memberships')
                ->nullOnDelete();
            $table->index(['organization_id', 'certificate_id', 'occurred_at']);
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE gift_certificates ADD CONSTRAINT gift_certificates_values_check CHECK (original_amount_minor > 0 AND currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE gift_certificate_claims ADD CONSTRAINT gift_certificate_claims_values_check CHECK (token_hash ~ '^[0-9a-f]{64}$' AND status IN ('pending', 'claimed', 'revoked') AND ((status = 'pending' AND claimed_client_id IS NULL AND claimed_at IS NULL AND revoked_at IS NULL) OR (status = 'claimed' AND claimed_client_id IS NOT NULL AND claimed_at IS NOT NULL AND revoked_at IS NULL) OR (status = 'revoked' AND claimed_client_id IS NULL AND claimed_at IS NULL AND revoked_at IS NOT NULL)))");
            DB::statement("ALTER TABLE gift_certificate_redemptions ADD CONSTRAINT gift_certificate_redemptions_values_check CHECK (amount_minor > 0 AND currency ~ '^[A-Z]{3}$' AND idempotency_key <> '')");
            DB::statement("ALTER TABLE gift_certificate_movements ADD CONSTRAINT gift_certificate_movements_values_check CHECK (amount_minor >= 0 AND currency ~ '^[A-Z]{3}$' AND idempotency_key <> '' AND ((movement_type = 'issued' AND amount_minor > 0 AND from_holder_client_id IS NULL AND to_holder_client_id IS NOT NULL AND claim_id IS NULL AND redemption_id IS NULL AND reverses_movement_id IS NULL) OR (movement_type = 'transferred' AND amount_minor = 0 AND from_holder_client_id IS NOT NULL AND to_holder_client_id IS NULL AND claim_id IS NOT NULL AND redemption_id IS NULL AND reverses_movement_id IS NULL) OR (movement_type = 'claimed' AND amount_minor = 0 AND from_holder_client_id IS NULL AND to_holder_client_id IS NOT NULL AND claim_id IS NOT NULL AND redemption_id IS NULL AND reverses_movement_id IS NULL) OR (movement_type = 'redeemed' AND amount_minor > 0 AND from_holder_client_id IS NOT NULL AND to_holder_client_id IS NULL AND claim_id IS NULL AND redemption_id IS NOT NULL AND reverses_movement_id IS NULL) OR (movement_type = 'redemption_reversed' AND amount_minor > 0 AND from_holder_client_id IS NULL AND to_holder_client_id IS NULL AND claim_id IS NULL AND redemption_id IS NOT NULL AND reverses_movement_id IS NOT NULL)))");
            DB::statement("CREATE UNIQUE INDEX gift_certificate_claims_pending_unique ON gift_certificate_claims (organization_id, certificate_id) WHERE status = 'pending'");
            DB::statement("CREATE UNIQUE INDEX gift_certificate_issued_movement_unique ON gift_certificate_movements (organization_id, certificate_id) WHERE movement_type = 'issued'");
            DB::statement('CREATE UNIQUE INDEX gift_certificate_claim_movement_unique ON gift_certificate_movements (organization_id, certificate_id, claim_id, movement_type) WHERE claim_id IS NOT NULL');
            DB::statement('DROP FUNCTION IF EXISTS prevent_gift_certificate_value_mutation()');
            DB::statement(<<<'SQL'
                CREATE FUNCTION prevent_gift_certificate_value_mutation() RETURNS trigger AS $$
                BEGIN
                    IF NEW.organization_id IS DISTINCT FROM OLD.organization_id
                        OR NEW.purchase_id IS DISTINCT FROM OLD.purchase_id
                        OR NEW.purchase_item_id IS DISTINCT FROM OLD.purchase_item_id
                        OR NEW.purchase_fulfillment_id IS DISTINCT FROM OLD.purchase_fulfillment_id
                        OR NEW.purchaser_client_id IS DISTINCT FROM OLD.purchaser_client_id
                        OR NEW.original_amount_minor IS DISTINCT FROM OLD.original_amount_minor
                        OR NEW.currency IS DISTINCT FROM OLD.currency
                        OR NEW.issued_at IS DISTINCT FROM OLD.issued_at THEN
                        RAISE EXCEPTION 'gift certificate issued value is immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement('CREATE TRIGGER gift_certificates_immutable_values BEFORE UPDATE ON gift_certificates FOR EACH ROW EXECUTE FUNCTION prevent_gift_certificate_value_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS validate_gift_certificate_movement()');
            DB::statement(<<<'SQL'
                CREATE FUNCTION validate_gift_certificate_movement() RETURNS trigger AS $$
                DECLARE
                    certificate_currency char(3);
                    certificate_amount bigint;
                BEGIN
                    SELECT currency, original_amount_minor
                    INTO certificate_currency, certificate_amount
                    FROM gift_certificates
                    WHERE organization_id = NEW.organization_id AND id = NEW.certificate_id;

                    IF certificate_currency IS NULL OR NEW.currency IS DISTINCT FROM certificate_currency THEN
                        RAISE EXCEPTION 'gift certificate movement currency is invalid';
                    END IF;

                    IF NEW.movement_type = 'issued' AND NEW.amount_minor IS DISTINCT FROM certificate_amount THEN
                        RAISE EXCEPTION 'gift certificate issued amount is invalid';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement('CREATE TRIGGER gift_certificate_movements_values BEFORE INSERT ON gift_certificate_movements FOR EACH ROW EXECUTE FUNCTION validate_gift_certificate_movement()');
            DB::statement('DROP FUNCTION IF EXISTS prevent_gift_certificate_history_mutation()');
            DB::statement(<<<'SQL'
                CREATE FUNCTION prevent_gift_certificate_history_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'gift certificate history is append-only';
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement('CREATE TRIGGER gift_certificate_movements_immutable BEFORE UPDATE OR DELETE ON gift_certificate_movements FOR EACH ROW EXECUTE FUNCTION prevent_gift_certificate_history_mutation()');
            DB::statement('CREATE TRIGGER gift_certificate_redemptions_immutable BEFORE UPDATE OR DELETE ON gift_certificate_redemptions FOR EACH ROW EXECUTE FUNCTION prevent_gift_certificate_history_mutation()');
        } elseif ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX gift_certificate_claims_pending_unique ON gift_certificate_claims (organization_id, certificate_id) WHERE status = 'pending'");
            DB::statement("CREATE UNIQUE INDEX gift_certificate_issued_movement_unique ON gift_certificate_movements (organization_id, certificate_id) WHERE movement_type = 'issued'");
            DB::statement('CREATE UNIQUE INDEX gift_certificate_claim_movement_unique ON gift_certificate_movements (organization_id, certificate_id, claim_id, movement_type) WHERE claim_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS gift_certificates_immutable_values ON gift_certificates');
            DB::statement('DROP TRIGGER IF EXISTS gift_certificate_movements_values ON gift_certificate_movements');
            DB::statement('DROP TRIGGER IF EXISTS gift_certificate_movements_immutable ON gift_certificate_movements');
            DB::statement('DROP TRIGGER IF EXISTS gift_certificate_redemptions_immutable ON gift_certificate_redemptions');
            DB::statement('DROP FUNCTION IF EXISTS prevent_gift_certificate_value_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS validate_gift_certificate_movement()');
            DB::statement('DROP FUNCTION IF EXISTS prevent_gift_certificate_history_mutation()');
        }

        DB::statement('DROP INDEX IF EXISTS gift_certificate_claims_pending_unique');
        DB::statement('DROP INDEX IF EXISTS gift_certificate_issued_movement_unique');
        DB::statement('DROP INDEX IF EXISTS gift_certificate_claim_movement_unique');
        Schema::dropIfExists('gift_certificate_movements');
        Schema::dropIfExists('gift_certificate_redemptions');
        Schema::dropIfExists('gift_certificate_claims');
        Schema::dropIfExists('gift_certificates');
    }
};
