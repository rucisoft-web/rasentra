<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SapMapping extends Model
{
    protected $table = 'sap_mappings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
        ];
    }
}
