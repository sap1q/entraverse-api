<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\ProfileController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Integration\JurnalSyncController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\StockController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('products')->name('products.')->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('{product}', [ProductController::class, 'show'])->name('show');
    });

    Route::prefix('categories')->name('categories.')->group(function (): void {
        Route::get('/', [CategoryController::class, 'index'])->name('index');
        Route::get('{category}', [CategoryController::class, 'show'])->name('show');
    });

    Route::prefix('admin')->name('admin.')->group(function (): void {
        // Public auth routes
        Route::post('login', [LoginController::class, 'login'])
            ->middleware(['throttle:admin-login', 'admin.secure'])
            ->name('login');

        Route::post('register', [RegisterController::class, 'register'])
            ->middleware(['throttle:3,1', 'admin.secure'])
            ->name('register');

        // Protected auth routes
        Route::middleware(['auth:sanctum', 'throttle:admin', 'admin.secure', 'admin'])->group(function (): void {
            Route::post('logout', [LogoutController::class, 'logout'])->name('logout');
            Route::get('profile', [ProfileController::class, 'profile'])->name('profile');
            Route::get('user', fn (Request $request) => $request->user())->name('user');

            Route::prefix('products')->name('products.')->group(function (): void {
                Route::get('{product}', [ProductController::class, 'showAdmin'])->name('show');
                Route::post('/', [ProductController::class, 'store'])->name('store');
                Route::put('{product}', [ProductController::class, 'update'])->name('update');
                Route::patch('{product}', [ProductController::class, 'update'])->name('patch');
                Route::delete('{product}', [ProductController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('stocks')->name('stocks.')->group(function (): void {
                Route::get('/', [StockController::class, 'index'])->name('index');
                Route::get('/mutations', [StockController::class, 'mutations'])->name('mutations');
                Route::post('/adjust', [StockController::class, 'adjust'])->name('adjust');
            });

            Route::prefix('sales-orders')->name('sales-orders.')->group(function (): void {
                Route::get('/', [SalesOrderController::class, 'index'])->name('index');
                Route::get('/catalog', [SalesOrderController::class, 'catalog'])->name('catalog');
                Route::get('/{orderId}', [SalesOrderController::class, 'show'])->name('show');
                Route::post('/', [SalesOrderController::class, 'store'])->name('store');
            });

            Route::prefix('categories')->name('categories.')->group(function (): void {
                Route::post('/', [CategoryController::class, 'store'])->name('store');
                Route::put('{category}', [CategoryController::class, 'update'])->name('update');
                Route::delete('{category}', [CategoryController::class, 'destroy'])->name('destroy');
                Route::post('{category}/restore', [CategoryController::class, 'restore'])->name('restore');
                Route::delete('{category}/force', [CategoryController::class, 'forceDelete'])->name('force-delete');
                Route::post('bulk/delete', [CategoryController::class, 'bulkDelete'])->name('bulk-delete');
                Route::get('stats/overview', [CategoryController::class, 'statistics'])->name('stats');
                Route::post('check/name', [CategoryController::class, 'checkName'])->name('check-name');
            });
        });
    });
});

Route::prefix('v1/integrations/jurnal')->name('integrations.jurnal.')->group(function (): void {
    Route::post('webhook', [JurnalSyncController::class, 'webhook'])->name('webhook');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('products/{id}/sync', [JurnalSyncController::class, 'syncProduct'])->name('products.sync');
        Route::post('products/{id}/archive', [JurnalSyncController::class, 'archiveProduct'])->name('products.archive');
        Route::post('products/{id}/unarchive', [JurnalSyncController::class, 'unarchiveProduct'])->name('products.unarchive');
        Route::post('products/import', [JurnalSyncController::class, 'importJurnalProducts'])->name('products.import');
        Route::post('products/sync-all', [JurnalSyncController::class, 'syncAllProducts'])->name('products.sync-all');
        Route::get('products', [JurnalSyncController::class, 'getJurnalProducts'])->name('products.index');
    });
});
