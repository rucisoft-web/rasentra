<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produksi dapur: batch dari resep (saus, kaldu) atau potong & porsi, dengan hasil standar,
 * dokumen penyesuaian stok (dikirim ke Rasentra Central dan SAP) dan catatan. Draft resep mencatat
 * penyunting terakhir agar persetujuan dilakukan rekan lain (maker-checker).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->string('kind', 20)->default('recipe')->after('recipe_version_id'); // recipe, cutting
            $table->decimal('expected_quantity', 18, 6)->nullable()->after('outputs'); // hasil standar resep
            $table->string('doc_no', 40)->nullable()->after('kind');
            $table->string('notes')->nullable()->after('loss_reason');
            $table->index(['outlet_id', 'produced_at']);
        });
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->foreignId('edited_by')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->dropColumn('edited_by');
        });
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'produced_at']);
            $table->dropColumn(['kind', 'expected_quantity', 'doc_no', 'notes']);
        });
    }
};
