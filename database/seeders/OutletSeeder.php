<?php

namespace Database\Seeders;

use App\Models\BusinessUnit;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * 18 outlet Toko Tani Indoguna sesuai daftar sumber (29 Oktober 2026). Dua outlet sisa
 * kapasitas belum teridentifikasi dan sengaja tidak dibuat. Kode gudang SAP dan fasilitas
 * restoran/cooking fee diisi saat penyiapan master; nilai di sini hanya default.
 */
class OutletSeeder extends Seeder
{
    public const OUTLETS = [
        ['JKT', 'Jakarta', 'PJT', 'Pangkalan Jati'],
        ['JKT', 'Jakarta', 'PSM', 'Pasar Minggu'],
        ['JKT', 'Jakarta', 'CBB', 'Cibubur'],
        ['JKT', 'Jakarta', 'RWS', 'Rawa Sari'],
        ['JKT', 'Jakarta', 'KLD', 'Kalideres'],
        ['JKT', 'Jakarta', 'KRT', 'Karang Tengah'],
        ['BKS', 'Bekasi', 'BTR', 'Bintara'],
        ['BKS', 'Bekasi', 'BKT', 'Bekasi Timur'],
        ['BKS', 'Bekasi', 'BKU', 'Bekasi Utara'],
        ['BGR', 'Bogor', 'BGR', 'Bogor'],
        ['CKR', 'Cikarang', 'CKR', 'Cikarang'],
        ['TNG', 'Tangerang', 'PML', 'Pamulang'],
        ['DPK', 'Depok', 'CNR', 'Cinere'],
        ['DPK', 'Depok', 'GDC', 'Depok GDC'],
        ['LPG', 'Lampung', 'ANT', 'Antasari'],
        ['LPG', 'Lampung', 'DPN', 'Diponegoro'],
        ['LPG', 'Lampung', 'CTL', 'Citraland'],
        ['LPG', 'Lampung', 'KML', 'Kemiling'],
    ];

    public function run(): void
    {
        $bu = BusinessUnit::firstOrCreate(['code' => 'CVPT'], ['name' => 'CV Pasar Tani']);

        foreach (self::OUTLETS as [$regionCode, $regionName, $code, $name]) {
            $region = Region::firstOrCreate(['code' => $regionCode], ['name' => $regionName, 'business_unit_id' => $bu->id]);
            $outlet = Outlet::firstOrCreate(['code' => $code], [
                'business_unit_id' => $bu->id,
                'region_id' => $region->id,
                'name' => $name,
                'timezone' => $regionCode === 'LPG' ? 'Asia/Jakarta' : 'Asia/Jakarta',
            ]);
            foreach ([['ETL', 'Etalase & chiller', 'sales'], ['GDG', 'Gudang outlet', 'storage'], ['DPR', 'Dapur', 'kitchen'], ['KRT', 'Karantina', 'quarantine']] as [$lc, $ln, $lt]) {
                Location::firstOrCreate(['outlet_id' => $outlet->id, 'code' => $lc], ['name' => $ln, 'type' => $lt]);
            }
        }
    }
}
