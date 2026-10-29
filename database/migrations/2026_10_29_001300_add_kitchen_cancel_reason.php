<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Alasan batal item dapur, termasuk permintaan batal dari kasir yang menunggu konfirmasi station. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->string('cancel_reason', 200)->nullable()->after('bumped_by');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_items', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
            $table->dropColumn('cancel_reason');
        });
    }
};
