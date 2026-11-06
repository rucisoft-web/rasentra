<?php

namespace App\Domain\Settings;

use App\Models\Outlet;
use App\Models\Setting;

/**
 * Pengaturan umum berjenjang. Nilai diambil dari level paling spesifik yang mengisinya:
 * outlet → perusahaan (CV) → grup → bawaan di kode. Diatur di Rasentra Central dan
 * dipublikasikan ke Rasentra Lokal, sehingga semua outlet bekerja dengan aturan yang sama
 * kecuali pusat sengaja memberi pengecualian per CV atau per outlet.
 */
final class Settings
{
    /** key => [grup, label, tipe (text|int|money|bool|percent), bawaan, keterangan] */
    public const KEYS = [
        'receipt.header' => ['Struk', 'Baris judul struk', 'text', '', 'Tampil di bawah nama outlet, mis. alamat atau NPWP.'],
        'receipt.footer' => ['Struk', 'Pesan penutup struk', 'text', 'Terima kasih atas kunjungan Anda', 'Tampil di bagian bawah struk 80 mm.'],
        'member.enabled' => ['Membership', 'Program member aktif', 'bool', true, 'Matikan untuk menghentikan perolehan dan penukaran poin.'],
        'member.earn_per_amount' => ['Membership', 'Belanja per 1 poin (Rp)', 'money', 10000, 'Setiap kelipatan nominal ini memberi 1 poin sebelum pengali tier.'],
        'member.point_value' => ['Membership', 'Nilai tukar 1 poin (Rp)', 'money', 100, 'Nilai rupiah satu poin saat dipakai membayar.'],
        'member.min_redeem' => ['Membership', 'Minimal poin sekali tukar', 'int', 100, ''],
        'member.offline_redeem_max' => ['Membership', 'Batas tukar poin saat Central offline', 'int', 200, 'Saat outlet tidak terhubung ke Central, penukaran per member dibatasi sebanyak ini agar poin tidak terpakai ganda di outlet lain. Isi 0 untuk menolak penukaran saat offline.'],
        'self_order.enabled' => ['Self ordering', 'Pesan mandiri lewat QR meja', 'bool', true, ''],
        'self_order.auto_send_kitchen' => ['Self ordering', 'Langsung kirim ke dapur setelah diterima pelayan', 'bool', true, ''],
        'delivery.default_fee' => ['Delivery', 'Ongkos kurir internal bawaan (Rp)', 'money', 10000, ''],
        'delivery.providers' => ['Delivery', 'Mitra pengiriman (pisahkan koma)', 'text', 'internal,gofood,grabfood,shopeefood', ''],
        'delivery.post_fee' => ['Delivery', 'Posting ongkos kirim ke akuntansi', 'bool', true, 'Pengiriman yang selesai dengan ongkos > 0 dibukukan sebagai jurnal biaya pengiriman di SAP B1 / Accurate milik CV.'],
        'staff.late_grace_minutes' => ['Staf & shift', 'Toleransi terlambat (menit)', 'int', 10, 'Absen masuk setelah jam mulai + toleransi dihitung terlambat.'],
    ];

    public function get(string $key, ?int $outletId = null): mixed
    {
        $companyId = $outletId ? Outlet::whereKey($outletId)->value('business_unit_id') : null;
        $rows = Setting::where('key', $key)->get()->filter(fn (Setting $s) => $s->value !== null)->keyBy(fn (Setting $s) => $s->scope.':'.($s->scope_id ?? 0));

        $value = match (true) {
            $outletId && $rows->has("outlet:{$outletId}") => $rows["outlet:{$outletId}"]->value,
            $companyId && $rows->has("company:{$companyId}") => $rows["company:{$companyId}"]->value,
            $rows->has('global:0') => $rows['global:0']->value,
            default => self::KEYS[$key][3] ?? null,
        };

        return $this->cast($key, $value);
    }

    /** Semua nilai efektif untuk satu outlet (dikirim ke layar POS). */
    public function all(?int $outletId = null): array
    {
        return collect(self::KEYS)->keys()->mapWithKeys(fn ($k) => [$k => $this->get($k, $outletId)])->all();
    }

    public function cast(string $key, mixed $value): mixed
    {
        return match (self::KEYS[$key][2] ?? 'text') {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'money', 'percent' => (string) (0 + (string) $value),
            default => (string) $value,
        };
    }
}
