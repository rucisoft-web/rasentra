<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra Kitchen (order management dapur): kode bump untuk scan barcode tiket,
 * waktu disajikan untuk analisis waktu saji, dan nomor antrean pickup per hari.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('order_id')->constrained();
            $table->string('bump_code', 12)->nullable()->unique()->after('sequence');
            $table->index(['outlet_id', 'station', 'created_at']);
        });

        Schema::table('ticket_items', function (Blueprint $table) {
            $table->timestamp('served_at')->nullable()->after('ready_at');
            $table->foreignId('bumped_by')->nullable()->after('served_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('queue_no')->nullable()->after('doc_no');
            $table->index(['outlet_id', 'business_date', 'queue_no']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'business_date', 'queue_no']);
            $table->dropColumn('queue_no');
        });
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->dropColumn(['served_at', 'bumped_by']);
        });
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'station', 'created_at']);
            $table->dropUnique(['bump_code']);
            $table->dropColumn('bump_code');
            $table->dropConstrainedForeignId('outlet_id');
        });
    }
};
