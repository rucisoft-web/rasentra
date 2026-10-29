<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra Inventory (handheld inventory): ronde hitung aktif, daftar produk yang wajib
 * dihitung ulang, ringkasan review opname dan waktu posting penyesuaian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_round')->default(1)->after('status');
            $table->json('recount_product_ids')->nullable()->after('current_round');
            $table->json('summary')->nullable()->after('recount_product_ids');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->dropColumn(['current_round', 'recount_product_ids', 'summary', 'approved_at']);
        });
    }
};
