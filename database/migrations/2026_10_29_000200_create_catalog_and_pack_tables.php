<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique(); // KG, PCS, PORSI
            $table->string('name');
            $table->unsignedTinyInteger('display_scale')->default(3);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            // Kode barang selalu string: nol di depan wajib dipertahankan.
            $table->string('code', 40)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('name');
            $table->string('type', 20); // retail, weighed, menu, service, ingredient, prep
            $table->string('uom_code', 10);
            $table->boolean('is_weighed')->default(false);
            $table->boolean('is_stocked')->default(true);
            $table->string('category', 40)->nullable();
            $table->string('kitchen_station', 20)->nullable(); // grill, hot, cold, bar, pastry, packing
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Versi harga berlaku per outlet/kanal dengan tanggal efektif.
        Schema::create('price_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->foreignId('outlet_id')->nullable()->constrained(); // null = semua outlet
            $table->string('channel', 20)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->string('status', 20)->default('published');
            $table->timestamps();
        });

        Schema::create('price_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('unit_price', 18, 2); // per uom produk (mis. per kg)
            $table->unique(['price_version_id', 'product_id']);
        });

        Schema::create('sap_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('company_db', 60);
            $table->string('entity_type', 40); // product, outlet, payment_method, customer
            $table->string('entity_key', 64);
            $table->string('sap_code', 64);
            $table->json('attributes')->nullable(); // tax code, dimensi, akun perantara
            $table->timestamps();
            $table->unique(['company_db', 'entity_type', 'entity_key']);
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->string('batch_no', 40);
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'batch_no']);
        });

        // Satu baris = satu kemasan fisik dengan berat aktual. Pack ID tidak pernah dipakai ulang.
        Schema::create('packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('pack_code', 32)->unique();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('batch_id')->nullable()->constrained();
            $table->decimal('net_weight', 18, 6);
            $table->string('uom_code', 10)->default('KG');
            $table->string('weigh_source', 60);
            $table->timestamp('packed_at');
            $table->date('expires_at')->nullable();
            $table->decimal('label_price', 18, 2)->nullable();
            $table->string('location_type', 20); // central, transit, outlet, return
            $table->foreignId('outlet_id')->nullable()->constrained();
            $table->string('status', 20); // available, allocated, shipped, received, reserved, sold, hold, damaged, repacked
            $table->unsignedInteger('lock_version')->default(0);
            $table->uuid('reserved_order_id')->nullable();
            // Penjaga utama agar satu kemasan hanya terjual satu kali.
            $table->uuid('sold_order_line_id')->nullable()->unique();
            $table->timestamps();
            $table->index(['outlet_id', 'status']);
        });

        Schema::create('pack_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('pack_id');
            $table->string('event', 30); // packed, shipped, received, reserved, released, sold, hold, repacked, reprinted, returned
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('actor_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->timestamp('occurred_at');
            $table->index('pack_id');
        });

        Schema::create('repack_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('source_pack_id');
            $table->uuid('result_pack_id');
            $table->decimal('weight_from_source', 18, 6);
            $table->string('loss_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['repack_links', 'pack_events', 'packs', 'batches', 'sap_mappings', 'price_items', 'price_versions', 'products', 'uoms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
