<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    public const TRIGGERS = [
        'keyword' => 'Pesan WhatsApp berisi kata kunci',
        'new_customer' => 'Pelanggan baru tercatat',
        'joined_community' => 'Bergabung ke komunitas',
        'purchase' => 'Setelah belanja',
        'birthday' => 'Hari ulang tahun',
        'inactive' => 'Tidak belanja N hari',
    ];

    public const STEP_TYPES = [
        'send_template' => 'Kirim template WhatsApp',
        'send_text' => 'Kirim pesan teks (jendela 24 jam)',
        'wait' => 'Tunggu',
        'add_tag' => 'Beri tag',
        'join_community' => 'Masukkan ke komunitas',
        'assign' => 'Serahkan percakapan ke CS',
    ];

    protected $table = 'crm_workflows';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'trigger_config' => 'array', 'steps' => 'array', 'last_scanned_on' => 'date'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class, 'crm_workflow_id');
    }
}
