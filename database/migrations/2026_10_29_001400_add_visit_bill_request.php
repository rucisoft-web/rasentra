<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rasentra Mobile Terminal: meja yang minta bayar tampil kuning di peta meja dan di kasir. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->timestamp('bill_requested_at')->nullable()->after('opened_at');
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'status']);
            $table->dropColumn('bill_requested_at');
        });
    }
};
