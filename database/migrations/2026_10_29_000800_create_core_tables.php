<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pendukung Rasentra Core. Proyeksi penjualan di Rasentra Central untuk laporan Rasentra Core. Diisi dari event
 * order.finalized yang diterima inbox, sehingga laporan tidak membaca JSON mentah dan
 * dapat dibangun ulang kapan saja dari inbox (rasentra:rebuild-sales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hq_orders', function (Blueprint $table) {
            $table->uuid('id')->primary(); // id order dari outlet
            $table->foreignId('inbox_event_id')->unique();
            $table->foreignId('outlet_id')->constrained();
            $table->string('doc_no', 40)->nullable();
            $table->string('channel', 20);
            $table->date('business_date');
            $table->decimal('gross_amount', 18, 2);
            $table->decimal('discount_amount', 18, 2);
            $table->decimal('net_amount', 18, 2);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['business_date', 'outlet_id']);
        });

        Schema::create('hq_order_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('hq_order_id');
            $table->unsignedSmallInteger('line_no');
            $table->string('line_type', 20);
            $table->foreignId('product_id');
            $table->decimal('quantity', 18, 6);
            $table->string('uom', 10);
            $table->decimal('gross_amount', 18, 2);
            $table->decimal('discount_amount', 18, 2);
            $table->decimal('net_amount', 18, 2);
            $table->index('hq_order_id');
            $table->index('product_id');
        });

        Schema::create('hq_order_discounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('hq_order_id');
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('promotion_version_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('funded_by', 20);
            $table->index('hq_order_id');
            $table->index('promotion_version_id');
        });

        Schema::create('hq_order_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('hq_order_id');
            $table->string('method', 20);
            $table->decimal('amount', 18, 2);
            $table->index('hq_order_id');
        });

        // Maker-checker versi harga: pembuat draft tidak boleh menerbitkan sendiri.
        Schema::table('price_versions', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('status');
            $table->foreignId('published_by')->nullable()->after('created_by');
            $table->timestamp('published_at')->nullable()->after('published_by');
            $table->string('note')->nullable()->after('published_at');
        });

        // Tarif cooking fee berversi: kode tetap, versi naik, versi lama ditutup tanggal efektifnya.
        Schema::table('cooking_fee_rules', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable();
            $table->dropUnique(['code']);
            $table->unique(['code', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('cooking_fee_rules', function (Blueprint $table) {
            $table->dropUnique(['code', 'version']);
            $table->unique('code');
            $table->dropColumn('created_by');
        });
        Schema::table('price_versions', fn (Blueprint $table) => $table->dropColumn(['created_by', 'published_by', 'published_at', 'note']));
        foreach (['hq_order_payments', 'hq_order_discounts', 'hq_order_lines', 'hq_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
