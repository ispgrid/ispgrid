<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Invoicing and payments: payment methods, invoice numbering, invoices with their lines and tax lines, payments,
 * the customer and plan billing columns, and the seven Billing boxes on the Roles screen. Issue invoices stays off
 * (billing.enabled is not set), so nothing changes until an operator turns it on. FreeRADIUS reads none of this.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['manage-billing', 'set-customer-pricing', 'view-invoices', 'create-invoices',
        'record-payments', 'cancel-invoices', 'view-sales'];

    /**
     * New boxes for the roles that already held the access they come from.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'manage-app-settings' => ['manage-billing', 'set-customer-pricing', 'cancel-invoices', 'view-sales'],
        'recharge-customers' => ['view-invoices', 'create-invoices', 'record-payments'],
    ];

    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->id();
            $table->string('series', 20)->unique();
            $table->unsignedInteger('next_number');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->string('series', 20);
            $table->foreignId('account_id')->constrained();
            $table->foreignId('recharge_id')->nullable()->constrained();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('title', 20);
            $table->date('issue_date');
            $table->date('due_date');
            $table->unsignedSmallInteger('terms_days')->default(0);
            $table->dateTime('period_from')->nullable();
            $table->dateTime('period_to')->nullable();
            $table->string('validity_text', 100)->nullable();
            $table->char('place_of_supply', 2)->nullable();
            $table->string('tax_setup', 20);
            $table->string('item_code_label', 20)->nullable();
            $table->json('company');
            $table->json('customer');
            $table->decimal('taxable_total', 12, 2);
            $table->decimal('tax_total', 12, 2);
            $table->decimal('round_off', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('balance', 12, 2);
            $table->string('total_in_words');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'issue_date']);
            $table->index(['status', 'issue_date']);
            $table->index('issue_date');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description');
            $table->string('sub_description')->nullable();
            $table->string('item_code', 20)->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('taxable', 12, 2);
            $table->boolean('taxed')->default(true);
        });

        Schema::create('invoice_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 40);
            $table->decimal('rate', 6, 3);
            $table->decimal('amount', 12, 2);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('payment_method_id')->constrained();
            $table->decimal('amount', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->string('note')->nullable();
            $table->dateTime('paid_at');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('removed_by')->nullable()->constrained('users');
            $table->string('remove_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['account_id', 'paid_at']);
            $table->index('paid_at');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('billing_free')->default(false)->after('auto_renew');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('billing_free');
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('discount_percent');
            $table->string('billing_name', 150)->nullable()->after('payment_terms_days');
            $table->foreignId('billing_address_id')->nullable()->after('billing_name')->constrained('addresses');
            $table->char('place_of_supply', 2)->nullable()->after('billing_address_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('charge_tax')->default(true)->after('price');
            $table->boolean('round_off')->default(false)->after('charge_tax');
            $table->string('item_code', 20)->nullable()->after('round_off');
        });

        Schema::table('recharges', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('amount')->constrained();
        });

        Schema::table('advance_renewals', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('recharge_id')->constrained();
        });

        $now = now();

        foreach (['Cash' => 10, 'UPI' => 20, 'Bank transfer' => 30, 'Cheque' => 40] as $name => $position) {
            DB::table('payment_methods')->insertOrIgnore(['name' => $name, 'position' => $position, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->grant();
    }

    /**
     * Gives each role the new boxes for the access it already holds, never a role picked by name.
     */
    public function grant(): void
    {
        $permission = fn (string $name): ?int => DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
        $holders = fn (string $name): array => DB::table('role_has_permissions')->where('permission_id', $permission($name))->pluck('role_id')->all();

        foreach (self::GRANTS as $held => $boxes) {
            foreach ($holders($held) as $role) {
                foreach ($boxes as $box) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permission($box), 'role_id' => $role]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('advance_renewals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
        Schema::table('recharges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['charge_tax', 'round_off', 'item_code']);
        });
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_address_id');
            $table->dropColumn(['billing_free', 'discount_percent', 'payment_terms_days', 'billing_name', 'place_of_supply']);
        });
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_tax_lines');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_counters');
        Schema::dropIfExists('payment_methods');
    }
};
