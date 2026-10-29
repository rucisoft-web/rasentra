<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceDocumentLine extends Model
{
    protected $table = 'source_document_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
        ];
    }

    public $timestamps = false;
}
