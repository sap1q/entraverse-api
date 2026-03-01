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
        return ProductResource::collection($this->service->paginate($request->query()));
    }

    public function show(Product $product)
    {
        abort_if($product->product_status !== 'active', 404, 'Product not found');
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
}
