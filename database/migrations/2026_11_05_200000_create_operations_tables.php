<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur operasional grup: peran & izin, pengaturan umum berjenjang (grup → perusahaan → outlet),
 * metode pembayaran, membership & poin, self ordering via QR meja, delivery order, template
 * shift, roster dan kehadiran staf.
 *
 * Master (peran, pengaturan, metode bayar, tier, template shift) dibuat di Rasentra Central dan
 * dipublikasikan ke setiap Rasentra Lokal. Transaksi outlet (poin, kehadiran, pengiriman) memakai
 * UUID agar bisa disalin apa adanya ke Central lewat outbox tanpa bentrok id antar-outlet.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Peran dan izin. Baris di sini menimpa izin bawaan di kode (CoreAccess, Permissions).
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->string('scope', 10); // central, outlet
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // Pengaturan umum. scope global (scope_id null), company (business_unit_id), outlet (outlet_id).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('key', 60);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['scope', 'scope_id', 'key']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->string('type', 20); // cash, integrated, manual, voucher, points
            $table->boolean('requires_reference')->default(false);
            $table->string('sap_account', 30)->nullable();
            $table->json('outlet_ids')->nullable(); // null = semua outlet
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('member_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->decimal('min_spend', 18, 2)->default(0); // belanja kumulatif untuk naik ke tier ini
            $table->decimal('point_multiplier', 6, 2)->default(1);
            $table->string('benefits', 300)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Master pelanggan dimiliki modul Rasentra CRM. Bila modul itu belum terpasang, tabel dibuat
        // dengan definisi yang sama agar membership tetap bisa berjalan.
        if (! Schema::hasTable('crm_customers')) {
            Schema::create('crm_customers', function (Blueprint $table) {
                $table->id();
                $table->string('phone', 20)->unique();
                $table->string('name', 120);
                $table->string('email', 120)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('city', 60)->nullable();
                $table->string('wa_status', 20)->default('unknown');
                $table->unsignedInteger('purchase_count')->default(0);
                $table->decimal('total_spent', 18, 2)->default(0);
                $table->date('first_purchase_on')->nullable();
                $table->date('last_purchase_on')->nullable();
                $table->timestamps();
            });
        }
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique(); // nomor member grup
            $table->unsignedBigInteger('home_outlet_id')->nullable();
            $table->unsignedBigInteger('member_tier_id')->nullable();
            $table->decimal('points_balance', 18, 2)->default(0);
            $table->decimal('member_spend', 18, 2)->default(0); // belanja member yang dihitung untuk tier
            $table->date('member_since')->nullable();
            $table->boolean('is_member')->default(false);
        });

        Schema::create('member_point_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('crm_customer_id')->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->uuid('order_id')->nullable()->index();
            $table->string('type', 10); // earn, redeem, adjust
            $table->decimal('points', 18, 2);
            $table->decimal('order_amount', 18, 2)->default(0);
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('applied_at_central')->default(false);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_customer_id')->nullable()->after('customer_code');
            $table->decimal('points_earned', 18, 2)->default(0);
            $table->decimal('points_redeemed', 18, 2)->default(0);
        });

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->string('qr_token', 40)->nullable()->unique();
        });

        // Pesanan mandiri dari QR meja: menunggu diterima pelayan sebelum masuk ke pesanan meja.
        Schema::create('self_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->unsignedBigInteger('dining_table_id');
            $table->string('guest_name', 60)->nullable();
            $table->unsignedSmallInteger('guests')->default(1);
            $table->json('items');
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->string('note', 200)->nullable();
            $table->string('status', 12)->default('submitted'); // submitted, accepted, rejected
            $table->uuid('visit_id')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('reject_reason', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->string('doc_no', 30)->nullable();
            $table->string('provider', 20)->default('internal'); // internal, gofood, grabfood, shopeefood, lainnya
            $table->string('external_ref', 60)->nullable();
            $table->unsignedBigInteger('courier_id')->nullable();
            $table->string('courier_name', 80)->nullable();
            $table->string('customer_name', 80)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('address', 300)->nullable();
            $table->decimal('order_amount', 18, 2)->default(0);
            $table->decimal('fee', 18, 2)->default(0);
            $table->string('status', 12)->default('pending'); // pending, assigned, on_the_way, delivered, failed
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->timestamp('promised_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(60);
            $table->json('outlet_ids')->nullable(); // null = semua outlet
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('staff_rosters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->foreignId('user_id')->constrained();
            $table->date('work_date');
            $table->foreignId('shift_template_id')->nullable()->constrained(); // null = libur
            $table->string('note', 120)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('user_name', 80)->nullable();
            $table->date('work_date');
            $table->string('shift_code', 20)->nullable();
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        foreach (['attendances', 'staff_rosters', 'shift_templates', 'deliveries', 'self_orders', 'member_point_ledger', 'member_tiers', 'payment_methods', 'settings', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('dining_tables', fn (Blueprint $table) => $table->dropColumn('qr_token'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['crm_customer_id', 'points_earned', 'points_redeemed']));
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'home_outlet_id', 'member_tier_id', 'points_balance', 'member_spend', 'member_since', 'is_member']);
        });
    }
};
