<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $includeInactive = $request->boolean('include_inactive', false);
        $search = trim((string) $request->query('search', ''));
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $query = Brand::query()
            ->withCount('products')
            ->when(! $includeInactive, fn ($builder) => $builder->where('is_active', true))
            ->when($search !== '', fn ($builder) => $builder->where('name', 'like', '%' . $search . '%'))
            ->orderBy('name');

        $paginator = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn (Brand $brand) => $this->mapBrand($brand))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function show(Brand $brand): JsonResponse
    {
        if (! $brand->is_active) {
            abort(404, 'Brand not found');
        }

        return response()->json([
            'success' => true,
            'data' => $this->mapBrand($brand->loadCount('products')),
        ]);
    }

    public function showAdmin(Brand $brand): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->mapBrand($brand->loadCount('products')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/', Rule::unique('brands', 'slug')],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slugInput = trim((string) ($validated['slug'] ?? ''));
        $slug = $this->ensureUniqueSlug($slugInput !== '' ? $slugInput : (string) $validated['name']);

        $brand = Brand::create([
            'name' => trim((string) $validated['name']),
            'slug' => $slug,
            'logo' => $request->hasFile('logo') ? $this->storeLogo($request) : null,
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Brand created successfully',
            'data' => $this->mapBrand($brand->loadCount('products')),
        ], 201);
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:140',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('brands', 'slug')->ignore($brand->id),
            ],
            'logo' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $brand->name = trim((string) $validated['name']);
        }

        if (array_key_exists('slug', $validated)) {
            $slugInput = trim((string) ($validated['slug'] ?? ''));
            $slugSource = $slugInput !== '' ? $slugInput : $brand->name;
            $brand->slug = $this->ensureUniqueSlug($slugSource, $brand->id);
        } elseif (array_key_exists('name', $validated)) {
            // Keep slug synced when name is updated while slug remains auto mode.
            $brand->slug = $this->ensureUniqueSlug($brand->name, $brand->id);
        }

        if ($request->hasFile('logo')) {
            $this->deleteLogo($brand->logo);
            $brand->logo = $this->storeLogo($request);
        } elseif ($request->boolean('remove_logo')) {
            $this->deleteLogo($brand->logo);
            $brand->logo = null;
        }

        if (array_key_exists('description', $validated)) {
            $brand->description = isset($validated['description'])
                ? trim((string) $validated['description'])
                : null;
        }

        if (array_key_exists('is_active', $validated)) {
            $brand->is_active = (bool) $validated['is_active'];
        }

        $brand->save();

        return response()->json([
            'success' => true,
            'message' => 'Brand updated successfully',
            'data' => $this->mapBrand($brand->loadCount('products')),
        ]);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $this->deleteLogo($brand->logo);
        $brand->delete();

        return response()->json([
            'success' => true,
            'message' => 'Brand deleted successfully',
        ]);
    }

    private function storeLogo(Request $request): string
    {
        $path = $request->file('logo')->store('brands/logos', 'public');
        return '/storage/' . ltrim($path, '/');
    }

    private function deleteLogo(?string $logoPath): void
    {
        if (! $logoPath || ! str_starts_with($logoPath, '/storage/')) {
            return;
        }

        $relativePath = ltrim(Str::after($logoPath, '/storage/'), '/');
        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }

    private function ensureUniqueSlug(string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug($source);
        $base = $base !== '' ? $base : 'brand';

        $slug = $base;
        $suffix = 1;

        while (
            Brand::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $suffix;
            $suffix += 1;
        }

        return $slug;
    }

    private function mapBrand(Brand $brand): array
    {
        $logo = $brand->logo;
        $logoUrl = null;

        if (is_string($logo) && trim($logo) !== '') {
            $logoUrl = Str::startsWith($logo, ['http://', 'https://'])
                ? $logo
                : url($logo);
        }

        return [
            'id' => (string) $brand->id,
            'name' => (string) $brand->name,
            'slug' => (string) $brand->slug,
            'logo' => $logo,
            'logo_url' => $logoUrl,
            'description' => $brand->description,
            'is_active' => (bool) $brand->is_active,
            'product_count' => (int) ($brand->products_count ?? $brand->products()->count()),
            'created_at' => optional($brand->created_at)?->toISOString(),
            'updated_at' => optional($brand->updated_at)?->toISOString(),
        ];
    }
}

