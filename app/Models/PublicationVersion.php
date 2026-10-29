<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicationVersion extends Model
{
    protected $table = 'publication_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'outlet_ids' => 'array',
        ];
    }
}
