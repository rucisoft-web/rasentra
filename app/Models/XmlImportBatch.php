<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class XmlImportBatch extends Model
{
    use HasUuids;

    protected $table = 'xml_import_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }
}
