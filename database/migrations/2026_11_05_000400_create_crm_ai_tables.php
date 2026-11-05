<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rasentra CRM tahap 4: knowledge base AI, asisten chat dan skor lead. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_kb_articles', function (Blueprint $t) {
            $t->id();
            $t->string('title', 160);
            $t->text('body');
            $t->string('keywords', 300)->nullable();
            $t->foreignId('crm_branch_id')->nullable()->constrained('crm_branches')->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('crm_wa_channels', function (Blueprint $t) {
            $t->string('ai_mode', 10)->default('off'); // off | suggest | auto
        });

        Schema::table('crm_conversations', function (Blueprint $t) {
            $t->text('ai_suggestion')->nullable();
            $t->decimal('ai_confidence', 3, 2)->nullable();
            $t->timestamp('ai_suggested_at')->nullable();
            $t->boolean('ai_handoff')->default(false);
            $t->string('ai_handoff_reason', 255)->nullable();
            $t->unsignedTinyInteger('lead_score')->nullable()->index();
            $t->string('lead_stage', 10)->nullable(); // cold | warm | hot
            $t->string('lead_intent', 20)->nullable();
            $t->string('lead_summary', 500)->nullable();
            $t->string('lead_interest', 255)->nullable();
            $t->decimal('lead_value', 15, 2)->nullable();
            $t->string('lead_next_action', 255)->nullable();
            $t->timestamp('lead_scored_at')->nullable();
        });

        Schema::table('crm_messages', function (Blueprint $t) {
            $t->boolean('by_ai')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('crm_messages', fn (Blueprint $t) => $t->dropColumn('by_ai'));
        Schema::table('crm_conversations', fn (Blueprint $t) => $t->dropColumn([
            'ai_suggestion', 'ai_confidence', 'ai_suggested_at', 'ai_handoff', 'ai_handoff_reason', 'lead_score', 'lead_stage',
            'lead_intent', 'lead_summary', 'lead_interest', 'lead_value', 'lead_next_action', 'lead_scored_at',
        ]));
        Schema::table('crm_wa_channels', fn (Blueprint $t) => $t->dropColumn('ai_mode'));
        Schema::dropIfExists('crm_kb_articles');
    }
};
