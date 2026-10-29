<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceDocument extends Model
{
    use HasUuids;

    protected $table = 'source_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'header' => 'array',
            'business_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SourceDocumentLine::class);
    }
}
