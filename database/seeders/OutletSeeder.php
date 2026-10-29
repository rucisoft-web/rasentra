<?php

namespace Database\Seeders;

use App\Models\BusinessUnit;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Struktur Indoguna Group. Setiap CV punya instance SAP B1 (SQL Server) sendiri, dan setiap
 * lokasi adalah satu outlet (satu server Rasentra Lokal) yang menjalankan usaha retail dan
 * restoran dengan nama masing-masing.
 */
class OutletSeeder extends Seeder
{
    public const COMPANIES = [
        ['SKU', 'CV Solo Kulina Utama', 'sap_solo', 'SOLO', 'Solo', [
            ['SLO', 'Solo', 'Meatshop & Grocery', 'R&B Solo', 'Asia/Jakarta'],
        ]],
        ['LKU', 'CV Lombok Kulina Utama', 'sap_lombok', 'LOMBOK', 'Lombok', [
            ['LBK', 'Lombok', 'Meatshop', 'El Primo', 'Asia/Makassar'],
        ]],
    ];

    public function run(): void
    {
        foreach (self::COMPANIES as [$buCode, $buName, $sapDb, $regionCode, $regionName, $outlets]) {
            $bu = BusinessUnit::updateOrCreate(['code' => $buCode], ['name' => $buName, 'legal_name' => $buName, 'sap_company_db' => $sapDb]);
            $region = Region::updateOrCreate(['code' => $regionCode], ['name' => $regionName, 'business_unit_id' => $bu->id]);
            foreach ($outlets as [$code, $name, $retail, $restaurant, $tz]) {
                $outlet = Outlet::updateOrCreate(['code' => $code], [
                    'business_unit_id' => $bu->id,
                    'region_id' => $region->id,
                    'name' => $name,
                    'retail_name' => $retail,
                    'restaurant_name' => $restaurant,
                    'sap_warehouse_code' => 'WH-'.$code,
                    'timezone' => $tz,
                    'has_retail' => true,
                    'has_restaurant' => true,
                    'has_cooking_fee' => true,
                ]);
                foreach ([['ETL', 'Etalase & chiller', 'sales'], ['GDG', 'Gudang outlet', 'storage'], ['DPR', 'Dapur', 'kitchen'], ['KRT', 'Karantina', 'quarantine']] as [$lc, $ln, $lt]) {
                    Location::firstOrCreate(['outlet_id' => $outlet->id, 'code' => $lc], ['name' => $ln, 'type' => $lt]);
                }
            }
        }
    }
}
