<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra Central: kondisi tiap Rasentra Lokal dari heartbeat, rekonsiliasi tutup hari,
 * dan penyimpanan status kecil (cursor publikasi di Lokal, jejak job terjadwal, cek SAP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained();
            $table->foreignId('outlet_id')->constrained();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedBigInteger('applied_cursor')->default(0);
            $table->timestamp('last_pull_at')->nullable();
            $table->unsignedInteger('outbox_pending')->default(0);
            $table->unsignedInteger('outbox_failed')->default(0);
            $table->unsignedInteger('outbox_parked')->default(0);
            $table->timestamp('outbox_oldest_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->integer('clock_skew_seconds')->nullable();
            $table->string('open_business_date', 10)->nullable();
            $table->unsignedSmallInteger('open_shifts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Satu baris per outlet per tanggal bisnis: angka Lokal saat tutup hari dibanding data di Central.
        Schema::create('daily_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained();
            $table->date('business_date');
            $table->unsignedInteger('closing_version')->default(1);
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('local_orders')->default(0);
            $table->decimal('local_net', 18, 2)->default(0);
            $table->unsignedInteger('central_orders')->default(0);
            $table->decimal('central_net', 18, 2)->default(0);
            $table->decimal('cash_difference', 18, 2)->default(0);
            $table->unsignedSmallInteger('shifts')->default(0);
            $table->unsignedSmallInteger('open_shifts')->default(0);
            $table->string('status', 20)->default('waiting'); // matched, waiting, mismatch
            $table->json('local_summary')->nullable();
            $table->json('missing_doc_nos')->nullable(); // ada di Lokal, belum sampai di Central
            $table->json('extra_doc_nos')->nullable(); // ada di Central, tidak dilaporkan Lokal
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['outlet_id', 'business_date']);
        });

        Schema::create('system_states', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_states');
        Schema::dropIfExists('daily_reconciliations');
        Schema::dropIfExists('outlet_sync_states');
    }
};
