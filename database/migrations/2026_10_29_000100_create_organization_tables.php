<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->timestamps();
        });

        // Kelompok wilayah mengikuti daftar sumber (Jakarta, Bekasi, Lampung, ...).
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained();
            $table->foreignId('region_id')->constrained();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('sap_warehouse_code', 20)->nullable();
            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->boolean('has_retail')->default(true);
            $table->boolean('has_restaurant')->default(false);
            $table->boolean('has_cooking_fee')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Lokasi operasional di dalam outlet: etalase, chiller, gudang, dapur, karantina.
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained();
            $table->string('code', 30);
            $table->string('name');
            $table->string('type', 20); // sales, storage, kitchen, quarantine, transit
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['outlet_id', 'code']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained();
            $table->string('code', 40)->unique();
            $table->string('app', 20); // pos, mobile, kitchen, kiosk, outlet_server
            $table->char('token_hash', 64)->nullable()->unique(); // token perangkat/server (sha256), dapat dicabut
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('cashier')->after('email');
            $table->foreignId('outlet_id')->nullable()->after('role')->constrained();
            $table->string('pin_hash')->nullable()->after('password');
            $table->decimal('approval_limit', 18, 2)->default(0)->after('pin_hash');
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outlet_id');
            $table->dropColumn(['role', 'pin_hash', 'approval_limit', 'is_active']);
        });
        Schema::dropIfExists('devices');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('business_units');
    }
};
