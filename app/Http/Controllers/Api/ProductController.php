<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->query();
        $perPage = max(1, min((int) ($filters['per_page'] ?? 12), 100));
        $driver = DB::connection()->getDriverName();
        $search = trim((string) ($filters['search'] ?? ''));
        $hasSearch = $search !== '';
        $relations = method_exists(Product::class, 'category') ? ['category'] : [];

        $products = Product::query()
            ->with($relations)
            ->when($filters['brand'] ?? null, function ($query, $brand) {
                $query->where('brand', $brand);
            })
            ->when($filters['category'] ?? null, function ($query, $category) {
                $query->where('category', $category);
            })
            ->when($hasSearch, function ($query) use ($driver, $search) {
                if ($driver === 'pgsql') {
                    $query->where(function ($nested) use ($search) {
                        $nested->where('name', 'ilike', '%' . $search . '%')
                            ->orWhere('brand', 'ilike', '%' . $search . '%')
                            ->orWhere('spu', 'ilike', '%' . $search . '%');
                    });
                    return;
                }

                $normalizedSearch = '%' . strtolower($search) . '%';
                $query->where(function ($nested) use ($normalizedSearch) {
                    $nested->whereRaw('LOWER(name) LIKE ?', [$normalizedSearch])
                        ->orWhereRaw('LOWER(brand) LIKE ?', [$normalizedSearch])
                        ->orWhereRaw('LOWER(spu) LIKE ?', [$normalizedSearch]);
                });
            })
            ->paginate($perPage)
            ->appends($filters);

        return ProductResource::collection($products)->additional([
            'success' => true,
            'message' => 'List Data Produk Entraverse',
            'api_version' => 'v1',
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function show(string $slug)
    {
        try {
            $relations = method_exists(Product::class, 'category') ? ['category'] : [];
            $product = Product::query()
                ->with($relations)
                ->where('slug', $slug)
                ->where('product_status', 'active')
                ->firstOrFail();
        } catch (ModelNotFoundException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return (new ProductResource($product))->additional([
            'success' => true,
            'message' => 'Detail Data Produk Entraverse',
            'api_version' => 'v1',
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function store(ProductStoreRequest $request)
    {
        $validated = $request->validated();
        $images = $this->uploadImages($request->file('images', []));

        $payload = $this->buildPayload($validated, $images);

        $product = Product::query()->create($payload);

        return (new ProductResource($product))->additional([
            'success' => true,
            'message' => 'Product created successfully',
        ]);
    }

    public function update(ProductUpdateRequest $request, Product $product)
    {
        $validated = $request->validated();
        $images = $product->images ?? [];

        if ($request->hasFile('images')) {
            $images = $this->uploadImages($request->file('images', []));
        }

        $payload = $this->buildPayload($validated, $images, $product);

        $product->update($payload);
        $product->refresh();

        return (new ProductResource($product))->additional([
            'success' => true,
            'message' => 'Product updated successfully',
        ]);
    }

    private function buildPayload(array $validated, array $images, ?Product $product = null): array
    {
        $name = isset($validated['name']) ? $this->sanitizeText($validated['name']) : ($product?->name ?? 'product');
        $brand = isset($validated['brand']) ? $this->sanitizeText($validated['brand']) : ($product?->brand ?? 'entraverse');
        $baseSlug = Str::slug($name);
        $slug = $this->resolveUniqueSlug($baseSlug !== '' ? $baseSlug : 'product', $product?->id);

        $payload = [
            'name' => $name,
            'slug' => $slug,
            'brand' => $brand,
            'category' => $this->sanitizeText((string) ($validated['category'] ?? $product?->category ?? 'Electronics')),
            'description' => $this->sanitizeText((string) ($validated['description'] ?? $product?->description ?? '')),
            'price' => (float) ($validated['price'] ?? $product?->price ?? 0),
            'stock' => (int) ($validated['stock'] ?? $product?->stock ?? 0),
            'spu' => $product?->spu ?? $this->generateSpu($brand),
            'weight' => (int) ($validated['weight'] ?? $product?->weight ?? 0),
            'trade_in' => (bool) ($validated['trade_in'] ?? $product?->trade_in ?? false),
            'images' => $this->sanitizeArray($images),
            'variants' => $this->sanitizeArray($this->decodeJsonField($validated['variants'] ?? ($product?->variants ?? []))),
            'variant_pricing' => $this->sanitizeArray($this->decodeJsonField($validated['variant_pricing'] ?? ($product?->variant_pricing ?? []))),
            'product_status' => $validated['product_status'] ?? $product?->product_status ?? 'draft',
        ];

        return $payload;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{url: string, alt: null, is_primary: bool}>
     */
    private function uploadImages(array $files): array
    {
        $images = [];

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('products', 'public');

            $images[] = [
                'url' => '/storage/' . ltrim($path, '/'),
                'alt' => null,
                'is_primary' => $index === 0,
            ];
        }

        return $images;
    }

    private function decodeJsonField(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function sanitizeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            $cleanKey = is_string($key) ? $this->sanitizeText($key) : $key;

            if (is_array($item)) {
                $result[$cleanKey] = $this->sanitizeArray($item);
                continue;
            }

            $result[$cleanKey] = is_string($item) ? $this->sanitizeText($item) : $item;
        }

        return $result;
    }

    private function sanitizeText(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function resolveUniqueSlug(string $baseSlug, ?int $ignoreId = null): string
    {
        $slug = $baseSlug;
        $counter = 1;

        while (
            Product::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function generateSpu(string $brand): string
    {
        $prefix = strtoupper(Str::of($brand)->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-')->value());
        $prefix = $prefix !== '' ? $prefix : 'ENTRAVERSE';

        do {
            $candidate = sprintf('%s-%s', $prefix, Str::upper(Str::random(8)));
        } while (Product::query()->where('spu', $candidate)->exists());

        return $candidate;
    }
}
