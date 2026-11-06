<?php

namespace App\Http\Controllers\Lokal;

use App\Models\Outlet;
use Illuminate\Http\Request;

/** Outlet yang dilayani halaman operasional: outlet petugas, atau outlet server ini untuk admin. */
trait OutletScope
{
    protected function outletId(Request $request): int
    {
        $id = $request->user()?->outlet_id ?? Outlet::where('code', config('rasentra.outlet_code'))->value('id');
        abort_if($id === null, 404, 'Server ini belum terpasang ke outlet.');

        return (int) $id;
    }
}
