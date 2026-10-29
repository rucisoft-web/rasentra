<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboxEvent extends Model
{
    protected $table = 'inbox_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
