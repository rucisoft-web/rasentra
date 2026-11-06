<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembukuan per perusahaan: SAP Business One (SQL Server, lewat Service Layer) atau
 * Accurate Online (API Token). Token Accurate disimpan terenkripsi dan tidak pernah
 * dipublikasikan ke outlet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_units', function (Blueprint $table) {
            $table->string('accounting_system', 20)->default('sap_b1');
            $table->text('accurate_api_token')->nullable();
            $table->text('accurate_signature_secret')->nullable();
            $table->string('accurate_host', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_units', fn (Blueprint $table) => $table->dropColumn(['accounting_system', 'accurate_api_token', 'accurate_signature_secret', 'accurate_host']));
    }
};
