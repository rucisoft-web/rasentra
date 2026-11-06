<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffRoster extends Model
{
    protected $table = 'staff_rosters';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
