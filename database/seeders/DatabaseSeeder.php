<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(OutletSeeder::class);

        if (! app()->isProduction()) {
            $this->call([DemoSeeder::class, JmSoloSeeder::class, CoreSeeder::class]);
        }
    }
}
