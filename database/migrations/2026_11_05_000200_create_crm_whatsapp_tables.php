<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rasentra CRM tahap 2: WhatsApp Business Cloud API (resmi Meta). Satu "channel" = satu nomor
 * WhatsApp Business. Percakapan dan pesan disimpan per pelanggan; kampanye (iklan/broadcast)
 * dikirim sebagai template marketing ke anggota komunitas atau segmen yang sudah opt-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_wa_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('display_phone', 30)->nullable();
            $table->string('phone_number_id', 40)->unique();
            $table->string('waba_id', 40);
            $table->text('access_token');
            $table->foreignId('crm_branch_id')->nullable()->constrained(); // nomor milik outlet tertentu, atau pusat
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('send_per_minute')->default(60);
            $table->timestamp('templates_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_wa_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_wa_channel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('language', 10);
            $table->string('category', 20); // MARKETING, UTILITY, AUTHENTICATION
            $table->string('status', 20); // APPROVED, PENDING, REJECTED, PAUSED
            $table->string('header_type', 10)->nullable(); // TEXT, IMAGE, VIDEO, DOCUMENT
            $table->text('body');
            $table->unsignedTinyInteger('body_params')->default(0);
            $table->json('components');
            $table->timestamps();
            $table->unique(['crm_wa_channel_id', 'name', 'language']);
        });

        Schema::create('crm_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_wa_channel_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open'); // open, closed
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable(); // jendela layanan 24 jam
            $table->string('last_preview', 160)->nullable();
            $table->timestamps();
            $table->unique(['crm_customer_id', 'crm_wa_channel_id']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('crm_communities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            $table->foreignId('crm_branch_id')->nullable()->constrained();
            $table->string('join_keyword', 30)->nullable()->unique(); // pelanggan kirim kata ini untuk bergabung
            $table->timestamps();
        });

        Schema::create('crm_community_members', function (Blueprint $table) {
            $table->foreignId('crm_community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_customer_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20)->default('manual'); // manual, segment, keyword
            $table->timestamp('joined_at')->nullable();
            $table->primary(['crm_community_id', 'crm_customer_id']);
        });

        Schema::create('crm_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('crm_wa_channel_id')->constrained();
            $table->foreignId('crm_wa_template_id')->constrained();
            $table->json('audience'); // {community_ids: [], segment_id: ?}
            $table->json('params')->nullable(); // isi {{1}}, {{2}}: teks atau {name}, {outlet}
            $table->string('media_url', 500)->nullable(); // gambar/video header iklan
            $table->string('status', 20)->default('draft'); // draft, scheduled, sending, done, cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('crm_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in, out
            $table->string('type', 20); // text, template, image, video, document, audio, sticker, location, button, interactive
            $table->text('body')->nullable();
            $table->string('media_id', 120)->nullable();
            $table->string('wa_message_id', 120)->nullable()->unique();
            $table->string('status', 20); // queued, sent, delivered, read, failed, received
            $table->string('error', 500)->nullable();
            $table->foreignId('crm_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestamp('status_at')->nullable();
            $table->timestamps();
            $table->index(['crm_conversation_id', 'id']);
        });

        Schema::create('crm_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending, sent, delivered, read, failed, skipped
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['crm_campaign_id', 'crm_customer_id']);
            $table->index(['crm_campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['crm_campaign_recipients', 'crm_messages', 'crm_campaigns', 'crm_community_members', 'crm_communities', 'crm_conversations', 'crm_wa_templates', 'crm_wa_channels'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
