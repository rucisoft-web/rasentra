<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('device_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->date('business_date');
            $table->decimal('opening_cash', 18, 2);
            $table->string('status', 20)->default('open'); // open, closed, reopened
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->json('closing_counts')->nullable(); // hitungan aktual per metode
            $table->json('closing_expected')->nullable();
            $table->decimal('cash_difference', 18, 2)->nullable();
            $table->foreignId('closing_approved_by')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shift_id');
            $table->string('type', 20); // cash_in, cash_out, no_sale_open
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('reason');
            $table->foreignId('user_id')->constrained();
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();
            $table->index('shift_id');
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained();
            $table->string('code', 20);
            $table->string('area', 40)->nullable();
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['outlet_id', 'code']);
        });

        // Satu kunjungan tamu di satu meja; token mencegah order QR masuk ke meja lama.
        Schema::create('visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('dining_table_id')->constrained();
            $table->string('visit_token', 64)->unique();
            $table->unsignedSmallInteger('guest_count');
            $table->foreignId('waiter_id')->nullable();
            $table->string('status', 20)->default('open'); // open, closed
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->string('doc_no', 40)->nullable(); // nomor struk, terbit saat finalisasi
            $table->string('channel', 20); // retail, dine_in, takeaway, pickup, delivery
            $table->foreignId('device_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->uuid('shift_id')->nullable();
            $table->uuid('visit_id')->nullable();
            $table->string('customer_code', 40)->nullable();
            $table->string('status', 20)->default('draft'); // draft, held, confirmed, paid, closed, voided
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('sync_status', 20)->default('local');
            $table->date('business_date');
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->json('promotion_snapshot')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['outlet_id', 'doc_no']);
            $table->index(['outlet_id', 'business_date']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->unsignedSmallInteger('line_no');
            $table->string('line_type', 20); // retail_product, restaurant_menu, cooking_service, add_on
            $table->foreignId('product_id')->constrained();
            $table->uuid('pack_id')->nullable();
            $table->uuid('cooking_job_id')->nullable();
            $table->foreignId('recipe_version_id')->nullable();
            $table->decimal('quantity', 18, 6);
            $table->string('uom_code', 10);
            $table->decimal('unit_price', 18, 2);
            $table->foreignId('price_version_id')->nullable();
            $table->decimal('label_price', 18, 2)->nullable();
            $table->string('price_override_reason')->nullable();
            $table->foreignId('price_override_by')->nullable();
            $table->decimal('gross_amount', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2);
            $table->string('tax_code', 20)->nullable();
            $table->json('modifiers')->nullable();
            $table->string('course', 20)->nullable();
            $table->string('note')->nullable();
            $table->string('status', 20)->default('active'); // active, voided
            $table->decimal('returned_quantity', 18, 6)->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['order_id', 'line_no']);
            $table->index('pack_id');
        });

        Schema::create('discount_allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_line_id');
            $table->foreignId('promotion_version_id')->nullable();
            $table->string('voucher_code', 40)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('funded_by', 20)->default('internal'); // internal, supplier, bank, platform
            $table->timestamps();
            $table->index('order_line_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('method', 20); // cash, qris, edc, edc_manual, voucher, points
            $table->decimal('amount', 18, 2);
            $table->decimal('tendered', 18, 2)->nullable();
            $table->decimal('change_amount', 18, 2)->nullable();
            $table->string('status', 20); // created, pending, succeeded, failed, expired, uncertain, refunded_partial, refunded
            $table->string('provider', 30)->nullable();
            $table->string('provider_ref', 80)->nullable();
            $table->boolean('verified_by_api')->default(false);
            $table->timestamps();
            $table->unique(['provider', 'provider_ref']);
            $table->index('order_id');
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('station', 20);
            $table->unsignedInteger('sequence');
            $table->timestamps();
            $table->index(['station', 'created_at']);
        });

        Schema::create('ticket_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('kitchen_ticket_id');
            $table->uuid('order_line_id');
            $table->unsignedInteger('revision');
            $table->decimal('quantity', 18, 6);
            $table->string('status', 20)->default('queued'); // queued, acknowledged, in_preparation, ready, served, cancel_requested, cancelled
            $table->json('instructions')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
            $table->unique(['order_line_id', 'revision']);
        });

        Schema::create('cooking_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('outlet_id')->nullable()->constrained();
            $table->string('method', 30)->nullable(); // grill, pan_sear, ...
            $table->string('basis', 20); // per_kg, per_pack, per_portion, tiered
            $table->decimal('rate', 18, 2);
            $table->decimal('minimum_fee', 18, 2)->default(0);
            $table->json('tiers')->nullable(); // [{"up_to":1.0,"rate":40000}, ...]
            $table->json('included_items')->nullable(); // bahan pendamping yang sudah termasuk
            $table->foreignId('service_product_id')->constrained('products');
            $table->foreignId('recipe_version_id')->nullable(); // resep jasa (butter, bumbu, saus)
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
        });

        Schema::create('cooking_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('pack_id');
            $table->uuid('parent_job_id')->nullable();
            $table->uuid('entitlement_id')->nullable();
            $table->decimal('raw_weight', 18, 6); // dasar cooking fee: berat mentah
            $table->unsignedSmallInteger('portions')->default(1);
            $table->string('method', 30)->nullable();
            $table->string('doneness', 20)->nullable();
            $table->string('sauce', 40)->nullable();
            $table->string('allergy_note')->nullable();
            $table->foreignId('fee_rule_id')->constrained('cooking_fee_rules');
            $table->unsignedInteger('fee_rule_version');
            $table->decimal('fee_amount', 18, 2);
            $table->string('status', 30)->default('requested'); // requested, pack_handed_over, cooking, served, cancelled_before_cook, cancelled_after_cook, remade
            $table->foreignId('handed_over_by')->nullable();
            $table->timestamps();
        });

        // Hak layanan memasak untuk daging yang sudah dibayar di kasir retail.
        Schema::create('service_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('receipt_order_id');
            $table->uuid('pack_id');
            $table->decimal('remaining_weight', 18, 6);
            $table->string('status', 20)->default('open'); // open, used, revoked
            $table->timestamps();
            $table->unique(['receipt_order_id', 'pack_id']);
        });
    }

    public function down(): void
    {
        foreach (['service_entitlements', 'cooking_jobs', 'cooking_fee_rules', 'ticket_items', 'kitchen_tickets', 'payments', 'discount_allocations', 'order_lines', 'orders', 'visits', 'dining_tables', 'cash_movements', 'shifts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
