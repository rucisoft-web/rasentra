<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger append-only. Saldo dibaca dari stock_balances yang diperbarui pada transaksi yang sama.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('batch_id')->nullable();
            $table->uuid('pack_id')->nullable();
            $table->string('movement_type', 30);
            $table->decimal('quantity', 18, 6); // bertanda: + masuk, - keluar
            $table->string('uom_code', 10);
            $table->string('stock_state', 20)->default('available'); // available, reserved, quarantine, in_transit
            $table->string('source_type', 40);
            $table->uuid('source_id');
            $table->unsignedSmallInteger('source_line_no')->default(0);
            $table->timestamp('occurred_at');
            $table->date('business_date');
            $table->unique(['source_type', 'source_id', 'source_line_no', 'movement_type', 'product_id'], 'uq_move_source');
            $table->index(['outlet_id', 'product_id', 'business_date']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('stock_state', 20)->default('available');
            $table->decimal('quantity', 18, 6)->default(0);
            $table->timestamps();
            $table->unique(['outlet_id', 'location_id', 'product_id', 'stock_state'], 'uq_balance');
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained(); // menu atau hasil prep
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained();
            $table->unsignedInteger('version');
            $table->decimal('yield_quantity', 18, 6)->default(1); // hasil per resep (porsi/kg)
            $table->string('yield_uom', 10)->default('PORSI');
            $table->string('kind', 20)->default('menu'); // menu, stocked_prep, virtual_prep, cooking_service
            $table->string('status', 20)->default('draft'); // draft, approved, retired
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->json('outlet_ids')->nullable(); // null = semua outlet
            $table->timestamps();
            $table->unique(['recipe_id', 'version']);
        });

        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products');
            $table->decimal('quantity', 18, 6); // per yield_quantity
            $table->string('uom_code', 10);
            $table->string('modifier_key', 40)->nullable(); // baris hanya berlaku bila modifier dipilih
            $table->timestamps();
        });

        Schema::create('consumption_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->string('source_type', 40); // ticket_item, cooking_job, production
            $table->uuid('source_id');
            $table->foreignId('recipe_version_id')->nullable();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 18, 6);
            $table->string('uom_code', 10);
            $table->string('reason', 20)->default('sale'); // sale, complimentary, staff_meal, tasting, waste, remake
            $table->timestamp('occurred_at');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('production_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->foreignId('recipe_version_id')->nullable();
            $table->json('inputs');  // [{product_id, quantity}]
            $table->json('outputs'); // [{product_id, quantity, kind: main|byproduct}]
            $table->decimal('loss_quantity', 18, 6)->default(0);
            $table->string('loss_reason')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('produced_at');
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->timestamps();
        });

        // Versi yang sudah diterbitkan bersifat immutable; perubahan = versi baru.
        Schema::create('promotion_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained();
            $table->unsignedInteger('version');
            $table->string('type', 30); // percent, amount, special_price, buy_x_get_y, bundle, weight_tier, voucher
            $table->string('scope', 10)->default('item'); // item, basket
            $table->json('rules'); // syarat & manfaat
            $table->json('channels')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('stacking', 20)->default('exclusive'); // exclusive, stackable, best_benefit
            $table->string('funded_by', 20)->default('internal');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('draft'); // draft, simulated, approved, published, superseded, expired
            $table->foreignId('created_by')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['promotion_id', 'version']);
        });

        // Snapshot outlet target pada saat publikasi, agar perubahan kelompok tidak mengubah cakupan historis.
        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained();
            $table->string('ack_status', 20)->default('pending'); // pending, received, applied, failed
            $table->timestamp('acked_at')->nullable();
            $table->unique(['promotion_version_id', 'outlet_id']);
        });

        Schema::create('count_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->string('type', 20); // full, cycle, special
            $table->boolean('blind')->default(true);
            $table->timestamp('cutoff_at');
            $table->decimal('recount_threshold', 18, 6)->default(0);
            $table->string('status', 20)->default('planned'); // planned, frozen, counting, recount, review, approved, posted_sap
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();
        });

        Schema::create('count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('count_session_id');
            $table->unsignedTinyInteger('round')->default(1);
            $table->foreignId('location_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->uuid('pack_id')->nullable();
            $table->decimal('quantity', 18, 6);
            $table->foreignId('counted_by')->constrained('users');
            $table->timestamps();
            $table->unique(['count_session_id', 'round', 'pack_id']);
        });
    }

    public function down(): void
    {
        foreach (['count_lines', 'count_sessions', 'promotion_targets', 'promotion_versions', 'promotions', 'production_batches', 'consumption_events', 'recipe_lines', 'recipe_versions', 'recipes', 'stock_balances', 'stock_movements'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
