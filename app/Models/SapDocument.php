<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SapDocument extends Model
{
    use HasUuids;

    protected $table = 'sap_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'posting_date' => 'date',
            'posted_amount' => 'decimal:2',
        ];
    }
}
