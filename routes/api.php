<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\V1\ProductController;

Route::post('/v1/admin/login', [AdminAuthController::class, 'login'])
    ->middleware(['throttle:admin-login', 'admin.secure']);

Route::prefix('v1')->group(function () {
    Route::prefix('admin')->middleware(['throttle:admin', 'admin.secure'])->group(function () {
        Route::middleware(['auth:sanctum'])->group(function () {
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{product}', [ProductController::class, 'update']);
            Route::patch('/products/{product}', [ProductController::class, 'update']);
        });
    });

    // Route untuk ambil data user yang sedang login (bawaan Sanctum)
    Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
        return $request->user();
    });

    // Route API Produk kamu (Tanpa login/Public)
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
});
