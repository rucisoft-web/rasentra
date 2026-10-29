<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra Inventory: dokumen penyesuaian stok (waste, hasil opname, lepas karantina).
 * Setiap baris menyebut status asal dan tujuan; null berarti keluar/masuk dari luar stok,
 * sehingga Central tahu mana yang menjadi Goods Issue atau Goods Receipt di SAP.
 * Tabel hq_* adalah proyeksinya di Rasentra Central untuk laporan Core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->string('doc_no', 40);
            $table->string('kind', 20); // waste, count, release
            $table->string('reason')->nullable();
            $table->string('source_type', 40)->nullable(); // count_session, cooking_job
            $table->uuid('source_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->date('business_date');
            $table->timestamps();
            $table->unique(['outlet_id', 'doc_no']);
            $table->index(['outlet_id', 'business_date']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('stock_adjustment_id');
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained();
            $table->uuid('pack_id')->nullable();
            $table->decimal('quantity', 18, 6); // selalu positif; arah dari from_state ke to_state
            $table->string('uom_code', 10);
            $table->string('from_state', 20)->nullable(); // available, quarantine, null = dari luar stok
            $table->string('to_state', 20)->nullable(); // available, quarantine, null = keluar dari stok
            $table->index('stock_adjustment_id');
        });

        Schema::create('hq_stock_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('inbox_event_id')->unique();
            $table->foreignId('outlet_id')->constrained();
            $table->string('doc_no', 40);
            $table->string('kind', 20);
            $table->string('reason')->nullable();
            $table->date('business_date');
            $table->timestamps();
            $table->index(['business_date', 'outlet_id']);
        });

        Schema::create('hq_stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('hq_stock_adjustment_id');
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id');
            $table->uuid('pack_id')->nullable();
            $table->decimal('quantity', 18, 6);
            $table->string('uom', 10);
            $table->string('from_state', 20)->nullable();
            $table->string('to_state', 20)->nullable();
            $table->index('hq_stock_adjustment_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        foreach (['hq_stock_adjustment_lines', 'hq_stock_adjustments', 'stock_adjustment_lines', 'stock_adjustments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
