<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\ProfileController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CustomerOrderController;
use App\Http\Controllers\Api\PaymentCallbackController;
use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Api\ShippingTrackingCallbackController;
use App\Http\Controllers\Api\TradeInTransactionController as CustomerTradeInTransactionController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Integration\JurnalSyncController;
use App\Http\Controllers\Api\V1\Integration\MarketplaceIntegrationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\RajaOngkirRegionController;
use App\Http\Controllers\Api\V1\SalesOrderController;
use App\Http\Controllers\Api\V1\StoreOriginController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\TradeInTransactionController;
use App\Http\Controllers\Api\V1\UserAddressController;
use App\Http\Controllers\Api\V1\WarrantyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'customer'])->prefix('shipping')->name('shipping.')->group(function (): void {
    Route::post('cost', [ShippingController::class, 'cost'])->name('cost');
});

Route::middleware(['auth:sanctum', 'customer'])->prefix('checkout')->name('checkout.')->group(function (): void {
    Route::post('process', [CheckoutController::class, 'process'])->name('process');
});

Route::middleware(['auth:sanctum', 'customer'])->prefix('orders')->name('orders.')->group(function (): void {
    Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
    Route::get('{orderId}', [CustomerOrderController::class, 'show'])->name('show');
    Route::match(['get', 'post'], '{orderId}/payment', [CustomerOrderController::class, 'payment'])->name('payment');
    Route::post('{orderId}/cancel', [CustomerOrderController::class, 'cancel'])->name('cancel');
    Route::post('{orderId}/received', [CustomerOrderController::class, 'received'])->name('received');
    Route::post('{orderId}/trade-in-fulfillment', [CustomerOrderController::class, 'submitTradeInFulfillment'])->name('trade-in-fulfillment');
});

Route::middleware(['auth:sanctum', 'customer'])->prefix('trade-in')->name('trade-in.')->group(function (): void {
    Route::post('transactions', [CustomerTradeInTransactionController::class, 'store'])->name('transactions.store');
});

Route::get('user/avatar/{user}', [UserProfileController::class, 'avatar'])->name('user.avatar.show');

Route::middleware(['auth:sanctum', 'customer'])->group(function (): void {
    Route::get('user', [UserProfileController::class, 'show'])->name('user.profile');
    Route::match(['put', 'post'], 'user/update', [UserProfileController::class, 'update'])->name('user.update');
});

Route::prefix('payment')->name('payment.')->group(function (): void {
    Route::post('callback', [PaymentCallbackController::class, 'callback'])->name('callback');
});

Route::prefix('shipping')->name('shipping.public.')->group(function (): void {
    Route::post('tracking/webhook', [ShippingTrackingCallbackController::class, 'callback'])->name('tracking.webhook');
});

Route::prefix('rajaongkir')->name('rajaongkir.')->group(function (): void {
    Route::get('provinces', [RajaOngkirRegionController::class, 'provinces'])->name('provinces');
    Route::get('cities', [RajaOngkirRegionController::class, 'cities'])->name('cities');
    Route::get('districts', [RajaOngkirRegionController::class, 'districts'])->name('districts');
    Route::get('subdistricts', [RajaOngkirRegionController::class, 'subdistricts'])->name('subdistricts');
    Route::get('origin', [RajaOngkirRegionController::class, 'origin'])->name('origin');
});

Route::prefix('v1')->group(function (): void {
    Route::prefix('products')->name('products.')->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('suggestions', [ProductController::class, 'suggestions'])->name('suggestions');
        Route::get('load-more', [ProductController::class, 'loadMore'])->name('load-more');
        Route::get('{product}', [ProductController::class, 'show'])->name('show');
    });

    Route::prefix('brands')->name('brands.')->group(function (): void {
        Route::get('/', [BrandController::class, 'index'])->name('index');
        Route::get('{brand:slug}', [BrandController::class, 'show'])->name('show');
    });

    Route::prefix('categories')->name('categories.')->group(function (): void {
        Route::get('/', [CategoryController::class, 'index'])->name('index');
        Route::get('{category}', [CategoryController::class, 'show'])->name('show');
    });

    Route::prefix('banners')->name('banners.')->group(function (): void {
        Route::get('active', [BannerController::class, 'getActiveBanners'])->name('active');
    });

    Route::prefix('warranties')->name('warranties.')->group(function (): void {
        Route::post('lookup', [WarrantyController::class, 'lookup'])->name('lookup');
    });

    Route::middleware('auth:sanctum')->prefix('user-addresses')->name('user-addresses.')->group(function (): void {
        Route::get('/', [UserAddressController::class, 'index'])->name('index');
        Route::post('/', [UserAddressController::class, 'store'])->name('store');
        Route::patch('set-main', [UserAddressController::class, 'setMain'])->name('set-main');
        Route::patch('{addressId}/set-main', [UserAddressController::class, 'setMain'])->name('set-main-by-id');
        Route::put('{addressId}', [UserAddressController::class, 'update'])->name('update');
        Route::patch('{addressId}', [UserAddressController::class, 'update'])->name('patch');
        Route::delete('{addressId}', [UserAddressController::class, 'destroy'])->name('destroy');
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
                Route::get('/', [ProductController::class, 'indexAdmin'])->name('index');
                Route::get('{product}', [ProductController::class, 'showAdmin'])->name('show');
                Route::post('/', [ProductController::class, 'store'])->name('store');
                Route::put('{product}', [ProductController::class, 'update'])->name('update');
                Route::patch('{product}', [ProductController::class, 'update'])->name('patch');
                Route::patch('{product}/status', [ProductController::class, 'updateStatus'])->name('patch-status');
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
                Route::patch('/{orderId}/status', [SalesOrderController::class, 'updateStatus'])->name('update-status');
                Route::patch('/{orderId}/fulfillment', [SalesOrderController::class, 'fulfillment'])->name('fulfillment');
                Route::delete('/{orderId}', [SalesOrderController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('trade-in-transactions')->name('trade-in-transactions.')->group(function (): void {
                Route::get('/', [TradeInTransactionController::class, 'index'])->name('index');
                Route::patch('/{transactionId}/status', [TradeInTransactionController::class, 'updateStatus'])
                    ->name('update-status');
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

            Route::prefix('banners')->name('banners.')->group(function (): void {
                Route::get('/', [BannerController::class, 'index'])->name('index');
                Route::post('/', [BannerController::class, 'store'])->name('store');
                Route::post('reorder', [BannerController::class, 'reorder'])->name('reorder');
                Route::get('{id}', [BannerController::class, 'show'])->name('show');
                Route::put('{id}', [BannerController::class, 'update'])->name('update');
                Route::patch('{id}', [BannerController::class, 'update'])->name('patch');
                Route::delete('{id}', [BannerController::class, 'destroy'])->name('destroy');
                Route::post('{id}/restore', [BannerController::class, 'restore'])->name('restore');
                Route::delete('{id}/force', [BannerController::class, 'forceDelete'])->name('force-delete');
            });

            Route::prefix('brands')->name('brands.')->group(function (): void {
                Route::get('/', [BrandController::class, 'index'])->name('index');
                Route::post('/', [BrandController::class, 'store'])->name('store');
                Route::get('{brand}', [BrandController::class, 'showAdmin'])->name('show');
                Route::put('{brand}', [BrandController::class, 'update'])->name('update');
                Route::patch('{brand}', [BrandController::class, 'update'])->name('patch');
                Route::post('{brand}', [BrandController::class, 'update'])->name('update.post');
                Route::delete('{brand}', [BrandController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('warranties')->name('warranties.')->group(function (): void {
                Route::get('/', [WarrantyController::class, 'index'])->name('index');
                Route::post('/', [WarrantyController::class, 'store'])->name('store');
                Route::get('{warranty}', [WarrantyController::class, 'show'])->name('show');
                Route::put('{warranty}', [WarrantyController::class, 'update'])->name('update');
                Route::patch('{warranty}', [WarrantyController::class, 'update'])->name('patch');
                Route::delete('{warranty}', [WarrantyController::class, 'destroy'])->name('destroy');
            });

            Route::prefix('shipping')->name('shipping.')->group(function (): void {
                Route::get('origin', [StoreOriginController::class, 'show'])->name('origin.show');
                Route::put('origin', [StoreOriginController::class, 'upsert'])->name('origin.upsert');
                Route::patch('origin', [StoreOriginController::class, 'upsert'])->name('origin.patch');
            });
        });
    });
});

Route::middleware(['auth:sanctum', 'admin.secure', 'admin'])
    ->patch('products/{product}/status', [ProductController::class, 'updateStatus'])
    ->name('products.patch-status.compat');

Route::middleware('auth:sanctum')->prefix('user-addresses')->name('user-addresses.compat.')->group(function (): void {
    Route::get('/', [UserAddressController::class, 'index'])->name('index');
    Route::post('/', [UserAddressController::class, 'store'])->name('store');
    Route::patch('set-main', [UserAddressController::class, 'setMain'])->name('set-main');
    Route::patch('{addressId}/set-main', [UserAddressController::class, 'setMain'])->name('set-main-by-id');
    Route::put('{addressId}', [UserAddressController::class, 'update'])->name('update');
    Route::patch('{addressId}', [UserAddressController::class, 'update'])->name('patch');
    Route::delete('{addressId}', [UserAddressController::class, 'destroy'])->name('destroy');
});

Route::middleware('auth:sanctum')->prefix('user/addresses')->name('user-addresses.alt.')->group(function (): void {
    Route::patch('{addressId}/set-main', [UserAddressController::class, 'setMain'])->name('set-main');
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

Route::prefix('v1/integrations/marketplaces')->name('integrations.marketplaces.')->group(function (): void {
    Route::get('{channel}/callback', [MarketplaceIntegrationController::class, 'callback'])->name('callback');

    Route::middleware(['auth:sanctum', 'admin.secure', 'admin'])->group(function (): void {
        Route::get('connections', [MarketplaceIntegrationController::class, 'connections'])->name('connections');
        Route::post('{channel}/connect', [MarketplaceIntegrationController::class, 'connect'])->name('connect');
        Route::post('{channel}/disconnect', [MarketplaceIntegrationController::class, 'disconnect'])->name('disconnect');
        Route::post('{channel}/sync', [MarketplaceIntegrationController::class, 'sync'])->name('sync');
        Route::post('{channel}/mappings', [MarketplaceIntegrationController::class, 'storeMapping'])->name('mappings.store');
        Route::delete('{channel}/mappings', [MarketplaceIntegrationController::class, 'destroyMapping'])->name('mappings.destroy');
    });
});
