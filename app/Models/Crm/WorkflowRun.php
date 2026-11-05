<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRun extends Model
{
    protected $table = 'crm_workflow_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['resume_at' => 'datetime', 'log' => 'array'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'crm_workflow_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'crm_customer_id');
    }
}
