<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ditulis di transaksi yang sama dengan dokumen bisnis; dikirim worker setelah commit.
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('source_id', 40);
            $table->foreignId('outlet_id')->constrained();
            $table->string('entity_type', 40);
            $table->uuid('entity_id');
            $table->unsignedInteger('entity_version');
            $table->string('event_type', 60);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamp('occurred_at', 6);
            $table->date('business_date');
            $table->uuid('correlation_id');
            $table->json('payload');
            $table->char('checksum', 64);
            $table->string('status', 20)->default('pending'); // pending, sending, acked, failed, parked
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('ack_ref', 64)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        // Di pusat: penerimaan idempoten. Kirim ulang event yang sama mengembalikan ack_ref yang sama.
        Schema::create('inbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('source_id', 40);
            $table->uuid('event_id');
            $table->foreignId('outlet_id')->constrained();
            $table->string('entity_type', 40);
            $table->uuid('entity_id');
            $table->unsignedInteger('entity_version');
            $table->string('event_type', 60);
            $table->json('payload');
            $table->char('checksum', 64);
            $table->string('ack_ref', 64);
            $table->string('status', 30)->default('accepted'); // accepted, processed, deferred_dependency, rejected
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'event_id']);
        });

        Schema::create('publication_versions', function (Blueprint $table) {
            $table->id(); // id monoton = cursor pusat
            $table->string('entity_type', 40);
            $table->string('entity_key', 64);
            $table->json('payload');
            $table->json('outlet_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('sap_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_db', 60);
            $table->string('source_type', 40);
            $table->uuid('source_id');
            $table->string('sap_object', 40);
            $table->string('status', 30); // pending, validated, waiting_dependency, posting, posted, partially_posted, failed, needs_review
            $table->integer('doc_entry')->nullable();
            $table->integer('doc_num')->nullable();
            $table->decimal('posted_amount', 18, 2)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->date('posting_date')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['company_db', 'source_type', 'source_id', 'sap_object'], 'uq_sap_source');
        });

        Schema::create('xml_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 10); // MSI, MSR
            $table->string('file_name');
            $table->char('sha256', 64);
            $table->string('storage_path');
            $table->unsignedInteger('document_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('status', 20)->default('received');
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('source_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('xml_import_batch_id');
            $table->string('system', 20)->default('NMEXML');
            $table->string('branch_code', 40);
            $table->string('doc_type', 20); // SALESINVOICE, CUSTOMERRECEIPT
            $table->string('source_no', 60);
            $table->date('business_date')->nullable();
            $table->string('legacy_customer_code', 40)->nullable();
            $table->string('legacy_warehouse_code', 40)->nullable();
            $table->decimal('document_total_source', 18, 2)->nullable();
            $table->decimal('document_total_calculated', 18, 4)->nullable();
            $table->decimal('rounding_difference', 18, 4)->nullable();
            $table->json('header');
            $table->string('status', 30)->default('staged'); // staged, waiting_dependency, mapped, posted, failed
            $table->timestamps();
            $table->unique(['system', 'branch_code', 'doc_type', 'source_no'], 'uq_source_doc');
        });

        Schema::create('source_document_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('source_document_id');
            $table->string('source_line_key', 40)->nullable();
            $table->string('legacy_item_code', 40)->nullable();
            $table->decimal('quantity', 18, 6)->nullable();
            $table->string('uom', 20)->nullable();
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->decimal('line_discount_pct', 9, 4)->nullable();
            $table->string('ref_invoice_no', 60)->nullable(); // MSR: ARINVOICEID
            $table->decimal('amount', 18, 2)->nullable();
            $table->json('raw');
            $table->index('source_document_id');
        });

        Schema::create('integration_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40); // missing_mapping, invalid_data, period_locked, dependency_timeout
            $table->string('source_type', 40);
            $table->string('source_id', 64);
            $table->text('message');
            $table->string('status', 20)->default('open');
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->nullable();
            $table->foreignId('actor_id')->nullable();
            $table->foreignId('approver_id')->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 40);
            $table->string('subject_id', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['outlet_id', 'action']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'integration_exceptions', 'source_document_lines', 'source_documents', 'xml_import_batches', 'sap_documents', 'publication_versions', 'inbox_events', 'outbox_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
