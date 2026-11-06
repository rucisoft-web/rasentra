<?php

use App\Http\Controllers\Core;
use App\Http\Controllers\Lokal\AttendanceController;
use App\Http\Controllers\Lokal\DeliveryController;
use App\Http\Controllers\Lokal\SelfOrderQueueController;
use App\Http\Controllers\Lokal\StaffController;
use App\Http\Controllers\PosMemberController;
use App\Http\Controllers\SelfOrderController;
use Illuminate\Support\Facades\Route;

/*
| Fitur operasional grup. Master diatur di Rasentra Central (/core/...), dijalankan di
| setiap Rasentra Lokal (resto dan/atau meatshop).
*/

// Self ordering: tamu memindai QR di meja (tanpa login).
Route::prefix('order')->name('self-order.')->middleware('throttle:60,1')->controller(SelfOrderController::class)->group(function () {
    Route::get('{token}', 'show')->name('show');
    Route::get('{token}/menu', 'menu')->name('menu');
    Route::post('{token}', 'submit')->middleware('throttle:10,1')->name('submit');
    Route::get('{token}/status/{selfOrder}', 'status')->name('status');
});

// Absensi staf dengan PIN di perangkat outlet.
Route::get('absen', [AttendanceController::class, 'show'])->name('attendance');
Route::post('absen', [AttendanceController::class, 'clock'])->middleware('throttle:20,1')->name('attendance.clock');

Route::middleware('auth')->group(function () {
    // Member di Rasentra POS.
    Route::prefix('pos/api')->controller(PosMemberController::class)->group(function () {
        Route::get('members', 'lookup');
        Route::post('members', 'enroll')->middleware('perm:member.enroll');
        Route::post('orders/{order}/member', 'attach');
        Route::get('payment-methods', 'methods');
    });

    Route::prefix('lokal')->name('lokal.')->group(function () {
        Route::middleware('perm:self_order.accept')->controller(SelfOrderQueueController::class)->group(function () {
            Route::get('self-orders', 'index')->name('self-orders');
            Route::get('self-orders/count', 'count')->name('self-orders.count');
            Route::post('self-orders/{selfOrder}/accept', 'accept')->name('self-orders.accept');
            Route::post('self-orders/{selfOrder}/reject', 'reject')->name('self-orders.reject');
        });
        Route::middleware('perm:delivery.manage')->controller(DeliveryController::class)->group(function () {
            Route::get('deliveries', 'index')->name('deliveries');
            Route::post('deliveries/{order}/assign', 'assign')->name('deliveries.assign');
            Route::post('deliveries/{delivery}/status', 'status')->name('deliveries.status');
        });
        Route::middleware('perm:staff.roster')->controller(StaffController::class)->group(function () {
            Route::get('staff', 'index')->name('staff');
            Route::post('staff/roster', 'save')->name('staff.roster');
        });
    });
});

// Rasentra Core: master operasional, membership, izin, pengaturan dan insight grup.
Route::prefix('core')->name('core.')->middleware('auth')->group(function () {
    Route::middleware('core:organization')->group(function () {
        Route::get('organization', [Core\OrganizationController::class, 'index'])->name('organization');
        Route::post('organization/regions', [Core\OrganizationController::class, 'storeRegion'])->name('organization.regions.store');
    });

    Route::middleware('core:access')->group(function () {
        Route::get('access', [Core\AccessController::class, 'index'])->name('access.index');
        Route::put('access', [Core\AccessController::class, 'update'])->name('access.update');
        Route::post('access', [Core\AccessController::class, 'store'])->name('access.store');
    });

    Route::middleware('core:settings')->group(function () {
        Route::get('settings', [Core\SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [Core\SettingController::class, 'update'])->name('settings.update');
    });

    Route::middleware('core:operations')->group(function () {
        Route::get('payment-methods', [Core\PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::post('payment-methods', [Core\PaymentMethodController::class, 'store'])->name('payment-methods.store');
        Route::put('payment-methods/{method}', [Core\PaymentMethodController::class, 'update'])->name('payment-methods.update');

        Route::get('tables', [Core\TableController::class, 'index'])->name('tables.index');
        Route::post('tables', [Core\TableController::class, 'store'])->name('tables.store');
        Route::put('tables/{table}', [Core\TableController::class, 'update'])->name('tables.update');
        Route::post('tables/{table}/qr', [Core\TableController::class, 'regenerate'])->name('tables.qr');
        Route::get('tables/print/{outlet}', [Core\TableController::class, 'print'])->name('tables.print');

        Route::get('shift-templates', [Core\ShiftTemplateController::class, 'index'])->name('shift-templates.index');
        Route::post('shift-templates', [Core\ShiftTemplateController::class, 'store'])->name('shift-templates.store');
        Route::put('shift-templates/{template}', [Core\ShiftTemplateController::class, 'update'])->name('shift-templates.update');
    });

    Route::middleware('core:members')->group(function () {
        Route::get('members', [Core\MembershipController::class, 'index'])->name('members.index');
        Route::post('members', [Core\MembershipController::class, 'enroll'])->name('members.store');
        Route::get('members/{member}', [Core\MembershipController::class, 'show'])->name('members.show');
        Route::post('members/{member}/adjust', [Core\MembershipController::class, 'adjust'])->name('members.adjust');
        Route::get('member-tiers', [Core\MembershipController::class, 'tiers'])->name('member-tiers.index');
        Route::post('member-tiers', [Core\MembershipController::class, 'storeTier'])->name('member-tiers.store');
        Route::put('member-tiers/{tier}', [Core\MembershipController::class, 'updateTier'])->name('member-tiers.update');
    });

    Route::middleware('core:reports')->prefix('reports')->name('reports.')->group(function () {
        Route::get('group', [Core\InsightController::class, 'group'])->name('group');
        Route::get('deliveries', [Core\InsightController::class, 'deliveries'])->name('deliveries');
        Route::get('attendance', [Core\InsightController::class, 'attendance'])->name('attendance');
    });
});
