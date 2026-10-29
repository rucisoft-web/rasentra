<?php

use App\Http\Controllers\Core;
use Illuminate\Support\Facades\Route;

// Rasentra Core: aplikasi admin kantor pusat, berjalan di instalasi Rasentra Central.
Route::prefix('core')->name('core.')->group(function () {
    Route::get('login', [Core\AuthController::class, 'show'])->name('login');
    Route::post('login', [Core\AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('logout', [Core\AuthController::class, 'logout'])->name('logout');

    Route::middleware('auth')->group(function () {
        Route::get('/', [Core\DashboardController::class, 'index'])->middleware('core:dashboard')->name('dashboard');

        Route::middleware('core:master')->group(function () {
            Route::get('outlets', [Core\OutletController::class, 'index'])->name('outlets.index');
            Route::put('companies/{company}', [Core\OutletController::class, 'updateCompany'])->name('companies.update');
            Route::get('outlets/{outlet}', [Core\OutletController::class, 'edit'])->name('outlets.edit');
            Route::put('outlets/{outlet}', [Core\OutletController::class, 'update'])->name('outlets.update');
            Route::post('outlets/{outlet}/locations', [Core\OutletController::class, 'addLocation'])->name('outlets.locations.store');
            Route::post('locations/{location}/toggle', [Core\OutletController::class, 'toggleLocation'])->name('outlets.locations.toggle');

            Route::get('users', [Core\UserController::class, 'index'])->name('users.index');
            Route::get('users/create', [Core\UserController::class, 'create'])->name('users.create');
            Route::post('users', [Core\UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}', [Core\UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [Core\UserController::class, 'update'])->name('users.update');

            Route::get('devices', [Core\DeviceController::class, 'index'])->name('devices.index');
            Route::post('devices', [Core\DeviceController::class, 'store'])->name('devices.store');
            Route::post('devices/{device}/token', [Core\DeviceController::class, 'rotate'])->name('devices.token');
            Route::post('devices/{device}/revoke', [Core\DeviceController::class, 'revoke'])->name('devices.revoke');
        });

        Route::middleware('core:catalog')->group(function () {
            Route::get('products', [Core\ProductController::class, 'index'])->name('products.index');
            Route::get('products/create', [Core\ProductController::class, 'create'])->name('products.create');
            Route::post('products', [Core\ProductController::class, 'store'])->name('products.store');
            Route::get('products/{product}', [Core\ProductController::class, 'edit'])->name('products.edit');
            Route::put('products/{product}', [Core\ProductController::class, 'update'])->name('products.update');

            Route::get('prices', [Core\PriceController::class, 'index'])->name('prices.index');
            Route::post('prices', [Core\PriceController::class, 'store'])->name('prices.store');
            Route::get('prices/{version}', [Core\PriceController::class, 'show'])->name('prices.show');
            Route::put('prices/{version}', [Core\PriceController::class, 'update'])->name('prices.update');
            Route::post('prices/{version}/publish', [Core\PriceController::class, 'publish'])->name('prices.publish');
        });

        Route::middleware('core:promotions')->group(function () {
            Route::get('promotions', [Core\PromotionController::class, 'index'])->name('promotions.index');
            Route::get('promotions/create', [Core\PromotionController::class, 'create'])->name('promotions.create');
            Route::post('promotions', [Core\PromotionController::class, 'store'])->name('promotions.store');
            Route::get('promotions/versions/{version}', [Core\PromotionController::class, 'show'])->name('promotions.show');
            Route::get('promotions/versions/{version}/edit', [Core\PromotionController::class, 'edit'])->name('promotions.edit');
            Route::put('promotions/versions/{version}', [Core\PromotionController::class, 'update'])->name('promotions.update');
            Route::post('promotions/versions/{version}/simulate', [Core\PromotionController::class, 'simulate'])->name('promotions.simulate');
            Route::post('promotions/versions/{version}/publish', [Core\PromotionController::class, 'publish'])->name('promotions.publish');
            Route::post('promotions/versions/{version}/new-version', [Core\PromotionController::class, 'newVersion'])->name('promotions.new-version');
            Route::post('promotions/versions/{version}/stop', [Core\PromotionController::class, 'stop'])->name('promotions.stop');
        });

        Route::middleware('core:recipes')->group(function () {
            Route::get('recipes', [Core\RecipeController::class, 'index'])->name('recipes.index');
            Route::get('recipes/{recipe}', [Core\RecipeController::class, 'show'])->name('recipes.show');
            Route::post('recipes/{recipe}/versions', [Core\RecipeController::class, 'draft'])->name('recipes.draft');
            Route::put('recipe-versions/{version}', [Core\RecipeController::class, 'update'])->name('recipes.update');
            Route::post('recipe-versions/{version}/approve', [Core\RecipeController::class, 'approve'])->name('recipes.approve');

            Route::get('cooking-fees', [Core\CookingFeeController::class, 'index'])->name('cooking-fees.index');
            Route::post('cooking-fees', [Core\CookingFeeController::class, 'store'])->name('cooking-fees.store');
            Route::get('cooking-fees/{rule}', [Core\CookingFeeController::class, 'edit'])->name('cooking-fees.edit');
            Route::post('cooking-fees/{rule}/supersede', [Core\CookingFeeController::class, 'supersede'])->name('cooking-fees.supersede');
        });

        Route::middleware('core:monitoring')->group(function () {
            Route::get('monitor/sync', [Core\MonitoringController::class, 'sync'])->name('monitor.sync');
            Route::post('monitor/outbox/{event}/retry', [Core\MonitoringController::class, 'retryOutbox'])->name('monitor.outbox.retry');
            Route::get('monitor/sap', [Core\MonitoringController::class, 'sap'])->name('monitor.sap');
            Route::get('monitor/sap/{document}', [Core\MonitoringController::class, 'sapDocument'])->name('monitor.sap.show');
            Route::post('monitor/sap/post', [Core\MonitoringController::class, 'postPending'])->name('monitor.sap.post');
            Route::post('monitor/sap/{document}/retry', [Core\MonitoringController::class, 'retrySap'])->name('monitor.sap.retry');
            Route::get('monitor/exceptions', [Core\MonitoringController::class, 'exceptions'])->name('monitor.exceptions');
            Route::post('monitor/exceptions/{exception}/resolve', [Core\MonitoringController::class, 'resolveException'])->name('monitor.exceptions.resolve');

            Route::get('sap-mappings', [Core\SapMappingController::class, 'index'])->name('sap-mappings.index');
            Route::post('sap-mappings', [Core\SapMappingController::class, 'store'])->name('sap-mappings.store');
            Route::delete('sap-mappings/{mapping}', [Core\SapMappingController::class, 'destroy'])->name('sap-mappings.destroy');

            Route::get('xml', [Core\XmlImportController::class, 'index'])->name('xml.index');
            Route::post('xml', [Core\XmlImportController::class, 'store'])->name('xml.store');
            Route::get('xml/{batch}', [Core\XmlImportController::class, 'show'])->name('xml.show');
        });

        Route::middleware('core:reports')->prefix('reports')->name('reports.')->group(function () {
            Route::get('sales', [Core\ReportController::class, 'sales'])->name('sales');
            Route::get('sales.csv', [Core\ReportController::class, 'salesCsv'])->name('sales.csv');
            Route::get('promotions', [Core\ReportController::class, 'promotions'])->name('promotions');
            Route::get('kitchen', [Core\ReportController::class, 'kitchen'])->name('kitchen');
            Route::get('stock', [Core\ReportController::class, 'stock'])->name('stock');
        });

        Route::get('audit', [Core\AuditController::class, 'index'])->middleware('core:audit')->name('audit');
    });
});
