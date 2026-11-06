<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasUuids;

    protected $table = 'attendances';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_start' => 'datetime', 'clock_in_at' => 'datetime', 'clock_out_at' => 'datetime'];
    }
}
