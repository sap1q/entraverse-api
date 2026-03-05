<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private ProductService $service) {}

    public function index(Request $request)
    {
        $filters = $request->query();

        // Scope Master Produk: hanya produk aktif dan tidak gagal sinkronisasi marketplace/Jurnal.
        if ($request->boolean('only_active')) {
            $filters['status'] = $filters['status'] ?? $filters['product_status'] ?? 'active';
            $filters['exclude_failed_sync'] = $filters['exclude_failed_sync'] ?? true;
            $filters['only_sync_activated'] = $filters['only_sync_activated'] ?? true;
        }

        return ProductResource::collection($this->service->paginate($filters));
    }

    public function show(Product $product)
    {
        abort_if($product->product_status !== 'active', 404, 'Product not found');
        return new ProductResource($product);
    }

    public function showAdmin(Product $product)
    {
        return new ProductResource($product);
    }

    public function store(StoreProductRequest $request)
    {
        $product = $this->service->store($request->validated(), $request->file('images', []));
        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        return new ProductResource(
            $this->service->update($product, $request->validated(), $request->file('images', []))
        );
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }
}
