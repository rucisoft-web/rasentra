<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra POS: retur penjualan dari struk yang sudah final, jumlah cetak struk, dan data pemesan pickup/delivery.
 * Di Central, retur diproyeksikan ke hq_orders sebagai dokumen bernilai negatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('outlet_id')->constrained();
            $table->uuid('order_id');
            $table->string('doc_no', 40);
            $table->uuid('shift_id')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('approved_by')->nullable();
            $table->date('business_date');
            $table->string('reason');
            $table->string('refund_method', 20); // cash, qris, edc, edc_manual, voucher
            $table->string('refund_ref', 80)->nullable();
            $table->decimal('gross_amount', 18, 2);
            $table->decimal('discount_amount', 18, 2);
            $table->decimal('net_amount', 18, 2);
            $table->timestamps();
            $table->unique(['outlet_id', 'doc_no']);
            $table->index('order_id');
            $table->index(['shift_id', 'refund_method']);
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('sales_return_id');
            $table->uuid('order_line_id');
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained();
            $table->uuid('pack_id')->nullable();
            $table->decimal('quantity', 18, 6);
            $table->string('uom_code', 10);
            $table->decimal('gross_amount', 18, 2);
            $table->decimal('discount_amount', 18, 2);
            $table->decimal('net_amount', 18, 2);
            $table->string('disposition', 20); // restock, quarantine, none (jasa/menu)
            $table->index('sales_return_id');
            $table->index('order_line_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('print_count')->default(0);
            // Data pemesan untuk kanal pickup dan delivery.
            $table->string('customer_name', 80)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('delivery_address', 300)->nullable();
            $table->timestamp('promised_at')->nullable();
            $table->string('fulfilment_note', 200)->nullable();
        });

        Schema::table('hq_orders', function (Blueprint $table) {
            $table->string('kind', 10)->default('sale'); // sale, return
            $table->uuid('original_order_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hq_orders', fn (Blueprint $table) => $table->dropColumn(['kind', 'original_order_id']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['print_count', 'customer_name', 'customer_phone', 'delivery_address', 'promised_at', 'fulfilment_note']));
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
    }
};
