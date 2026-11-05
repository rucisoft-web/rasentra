<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra CRM tahap 3: chat automation workflow. Satu workflow = satu pemicu + daftar
 * langkah (JSON). Setiap pelanggan yang memicu mendapat satu "run" yang maju langkah demi
 * langkah; langkah "tunggu" menunda run sampai resume_at dan dilanjutkan scheduler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('is_active')->default(false);
            $table->string('trigger', 30); // keyword, new_customer, birthday, inactive, purchase, joined_community
            $table->json('trigger_config')->nullable();
            $table->json('steps');
            $table->unsignedSmallInteger('cooldown_days')->default(0); // jangan ulang untuk pelanggan yang sama dalam N hari
            $table->date('last_scanned_on')->nullable(); // pemicu harian (ulang tahun, tidak belanja)
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['is_active', 'trigger']);
        });

        Schema::create('crm_workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step')->default(0);
            $table->string('status', 20)->default('running'); // running, waiting, done, failed, cancelled
            $table->timestamp('resume_at')->nullable();
            $table->json('log')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'resume_at']);
            $table->index(['crm_workflow_id', 'crm_customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_workflow_runs');
        Schema::dropIfExists('crm_workflows');
    }
};
