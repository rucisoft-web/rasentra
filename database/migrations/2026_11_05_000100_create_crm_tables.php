<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra CRM (modul Rasentra Central). Satu pelanggan = satu nomor WhatsApp yang sudah
 * dinormalkan ke format 62…; keanggotaan per outlet dicatat terpisah sehingga member yang
 * terdaftar di Solo dan Lombok tetap satu profil. Riwayat belanja menggabungkan penjualan POS
 * Rasentra dan faktur Accurate Online dalam satu tabel crm_purchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cabang/outlet untuk CRM: outlet Rasentra (outlet_id terisi) dan outlet CV lain yang masih
        // memakai POS lain (Rene POS, Raptor) dan Accurate Online. Dipisah dari tabel outlets agar
        // outlet non-Rasentra tidak ikut ke POS, sinkronisasi, harga, promo dan posting SAP.
        Schema::create('crm_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->constrained();
            $table->foreignId('outlet_id')->nullable()->unique()->constrained();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('pos_system', 20)->default('rasentra'); // rasentra, rene, raptor, lainnya
            $table->string('city', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('name', 120);
            $table->string('email', 120)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('wa_status', 20)->default('unknown'); // unknown, opted_in, opted_out
            $table->timestamp('wa_status_at')->nullable();
            $table->unsignedInteger('purchase_count')->default(0);
            $table->decimal('total_spent', 18, 2)->default(0);
            $table->date('first_purchase_on')->nullable();
            $table->date('last_purchase_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('name');
            $table->index('last_purchase_on');
        });

        Schema::create('crm_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_branch_id')->constrained();
            $table->string('member_code', 40)->nullable();
            $table->string('source', 20); // import, pos, accurate, manual
            $table->date('joined_on')->nullable();
            $table->timestamps();
            $table->unique(['crm_customer_id', 'crm_branch_id']);
            $table->index(['crm_branch_id', 'member_code']);
        });

        Schema::create('crm_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('color', 20)->default('slate');
            $table->timestamps();
        });

        Schema::create('crm_customer_tag', function (Blueprint $table) {
            $table->foreignId('crm_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['crm_customer_id', 'crm_tag_id']);
        });

        // Kode pelanggan di sistem lain, mis. customerNo Accurate per perusahaan.
        Schema::create('crm_external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_customer_id')->constrained()->cascadeOnDelete();
            $table->string('system', 20); // accurate
            $table->unsignedBigInteger('scope_id'); // business_unit_id untuk Accurate
            $table->string('external_id', 60);
            $table->timestamps();
            $table->unique(['system', 'scope_id', 'external_id']);
        });

        // Satu baris per transaksi sumber: POS (id hq_orders) atau Accurate (nomor faktur per database).
        Schema::create('crm_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_customer_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // pos, accurate
            $table->string('source_ref', 80);
            $table->foreignId('crm_branch_id')->nullable()->constrained();
            $table->foreignId('business_unit_id')->nullable()->constrained();
            $table->date('purchased_on');
            $table->decimal('amount', 18, 2);
            $table->string('doc_no', 60)->nullable();
            $table->timestamps();
            $table->unique(['source', 'source_ref']);
            $table->index(['crm_customer_id', 'purchased_on']);
        });

        Schema::create('crm_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('file_name');
            $table->char('sha256', 64);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('merged_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();
        });

        // Segmen: aturan (JSON) dievaluasi saat dibuka/dikirim, jadi isinya selalu terkini.
        Schema::create('crm_segments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            $table->json('rules');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // Koneksi Accurate Online per perusahaan (CV). Token disimpan terenkripsi.
        Schema::create('crm_accurate_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_unit_id')->unique()->constrained();
            $table->string('database_id', 40)->nullable();
            $table->foreignId('default_branch_id')->nullable()->constrained('crm_branches'); // pelanggan Accurate dicatat sebagai member cabang ini
            $table->text('api_token')->nullable();
            $table->text('signature_secret')->nullable();
            $table->boolean('sync_customers')->default(true);
            $table->boolean('sync_invoices')->default(true);
            $table->date('invoices_synced_until')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 20)->nullable(); // ok, failed
            $table->text('last_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['crm_accurate_connections', 'crm_segments', 'crm_imports', 'crm_purchases', 'crm_external_ids', 'crm_customer_tag', 'crm_tags', 'crm_memberships', 'crm_customers', 'crm_branches'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
