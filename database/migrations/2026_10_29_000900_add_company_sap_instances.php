<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indoguna Group: setiap CV (business unit) punya instance SAP B1 sendiri, misalnya
 * sap_solo untuk CV Solo Kulina Utama dan sap_lombok untuk CV Lombok Kulina Utama.
 * Satu outlet bisa menjalankan dua usaha dengan nama berbeda (retail dan restoran).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_units', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('sap_company_db', 60)->nullable()->after('legal_name');
        });
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('retail_name')->nullable()->after('name');
            $table->string('restaurant_name')->nullable()->after('retail_name');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn(['retail_name', 'restaurant_name']));
        Schema::table('business_units', fn (Blueprint $table) => $table->dropColumn(['legal_name', 'sap_company_db']));
    }
};
