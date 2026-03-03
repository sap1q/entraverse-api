<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\ProfileController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
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
                Route::post('/', [ProductController::class, 'store'])->name('store');
                Route::put('{product}', [ProductController::class, 'update'])->name('update');
                Route::patch('{product}', [ProductController::class, 'update'])->name('patch');
                Route::delete('{product}', [ProductController::class, 'destroy'])->name('destroy');
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