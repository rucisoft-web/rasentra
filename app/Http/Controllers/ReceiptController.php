<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Domain\Cash\ShiftService;
use App\Http\Middleware\EnsureLokalAccess;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\SalesReturn;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Dokumen cetak Rasentra POS untuk printer thermal 80 mm: struk, struk retur, dan laporan tutup shift.
 * Cetakan pertama adalah asli; setiap cetakan berikutnya bertanda SALINAN dan tercatat di audit log.
 */
class ReceiptController extends Controller
{
    public function order(Request $request, Order $order): View
    {
        $this->authorizeOutlet($request, $order->outlet_id);
        abort_unless($order->isFinal(), 404);

        $copy = DB::transaction(function () use ($request, $order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $copy = $order->print_count; // 0 = cetakan asli
            $order->update(['print_count' => $copy + 1]);
            if ($copy > 0) {
                Audit::record('receipt.reprinted', $order, $request->user()->id, outletId: $order->outlet_id, amount: $order->net_amount,
                    after: ['doc_no' => $order->doc_no, 'copy' => $copy]);
            }

            return $copy;
        });

        $order->load(['lines' => fn ($q) => $q->where('status', 'active')->with(['product:id,name', 'pack:id,pack_code']), 'payments' => fn ($q) => $q->where('status', 'succeeded'), 'user:id,name', 'returns']);

        return view('pos.print.receipt', [
            'order' => $order,
            'outlet' => $this->outlet($order->outlet_id),
            'copy' => $copy,
            'auto' => $request->boolean('auto'),
        ]);
    }

    public function salesReturn(Request $request, SalesReturn $salesReturn): View
    {
        $this->authorizeOutlet($request, $salesReturn->outlet_id);
        $salesReturn->load(['lines.product:id,name', 'order:id,doc_no,business_date', 'user:id,name', 'approver:id,name']);

        return view('pos.print.return', [
            'return' => $salesReturn,
            'outlet' => $this->outlet($salesReturn->outlet_id),
            'auto' => $request->boolean('auto'),
        ]);
    }

    public function shift(Request $request, Shift $shift, ShiftService $shifts): View
    {
        $this->authorizeOutlet($request, $shift->outlet_id);
        // Kasir hanya melihat shift-nya sendiri; kepala outlet boleh melihat semua shift outlet.
        abort_unless((int) $shift->user_id === (int) $request->user()->id || EnsureLokalAccess::allows($request->user()), 403);
        $shift->load('user:id,name');

        return view('pos.print.shift', [
            'shift' => $shift,
            'report' => $shifts->report($shift),
            'outlet' => $this->outlet($shift->outlet_id),
            'approver' => $shift->closing_approved_by ? User::find($shift->closing_approved_by)?->name : null,
            'auto' => $request->boolean('auto'),
        ]);
    }

    private function authorizeOutlet(Request $request, int $outletId): void
    {
        abort_unless((int) $request->user()->outlet_id === $outletId, 403, 'Dokumen ini milik outlet lain.');
    }

    private function outlet(int $id): Outlet
    {
        return Outlet::with('businessUnit')->findOrFail($id);
    }
}
