<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductService
{
    private const MAX_PRODUCT_PHOTOS = 5;
    private const DEFAULT_WAREHOUSE = 'Gudang Utama';
    private const DEFAULT_WARRANTY_VARIANT_NAME = 'Garansi';
    private const DEFAULT_WARRANTY_OPTIONS = ['Tanpa Garansi', 'Toko - 1 Tahun'];
    private const WARRANTY_COST_LABEL = 'biaya program garansi';
    private const WARRANTY_PROFIT_LABEL = 'keuntungan program garansi';
    private const ACTIVATED_SYNC_STATUSES = [
        'activate',
        'active',
        'success',
        'synced',
        'imported_from_jurnal',
        'created',
        'updated',
    ];
    private ?bool $postgresTrigramAvailable = null;

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 12), 100));
        $query = $this->buildCatalogQuery($filters);
        $this->applyCatalogSorting($query, $filters);

        return $query
            ->paginate($perPage)
            ->appends($filters);
    }

    /**
     * @return array{products: Collection<int, Product>, keywords: array<int, string>}
     */
    public function suggest(string $search, int $limit = 6): array
    {
        $normalizedSearch = $this->normalizeSearchText($search);
        if ($normalizedSearch === '') {
            return [
                'products' => collect(),
                'keywords' => [],
            ];
        }

        $query = $this->buildCatalogQuery([
            'apply_visible' => true,
            'status' => Product::STATUS_ACTIVE,
            'search' => $normalizedSearch,
        ]);

        $this->applyCatalogSorting($query, [
            'search' => $normalizedSearch,
            'sort_by' => 'relevance',
        ]);

        $products = $query
            ->limit(max(1, min($limit, 10)))
            ->get();

        return [
            'products' => $products,
            'keywords' => $this->buildSuggestedKeywords($products, $normalizedSearch),
        ];
    }

    private function buildCatalogQuery(array $filters): Builder
    {
        $driver = DB::connection()->getDriverName();
        $rawStatus = strtolower(trim((string) ($filters['status'] ?? $filters['product_status'] ?? '')));
        $status = $this->normalizeStatusFilter($rawStatus);
        $rawFeatured = $filters['featured'] ?? $filters['is_featured'] ?? null;
        $isFeatured = $this->normalizeBooleanFilter($rawFeatured);
        $rawTradeIn = $filters['trade_in'] ?? $filters['tradeIn'] ?? null;
        $isTradeIn = $this->normalizeBooleanFilter($rawTradeIn);
        $stockStatus = strtolower(trim((string) ($filters['stock_status'] ?? '')));
        $applyVisible = filter_var($filters['apply_visible'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $excludeFailedSync = filter_var($filters['exclude_failed_sync'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $onlySyncActivated = filter_var($filters['only_sync_activated'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return Product::query()
            ->select('products.*')
            ->with([
                'category:id,name',
                'brandModel:id,name,slug,logo,is_active',
            ])
            ->when($applyVisible, fn (Builder $query) => $query->visible())
            ->when($status !== null, fn (Builder $query) => $query->whereRaw('LOWER(COALESCE(status, product_status)) = ?', [$status]))
            ->when($isFeatured !== null, fn (Builder $query) => $query->where('is_featured', $isFeatured))
            ->when($isTradeIn !== null, fn (Builder $query) => $query->where('trade_in', $isTradeIn))
            ->when($stockStatus !== '', fn (Builder $query) => $query->where('stock_status', $stockStatus))
            ->when($filters['category_id'] ?? null, fn (Builder $query, string $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['brand_id'] ?? null, fn (Builder $query, string $brandId) => $query->where('brand_id', $brandId))
            ->when($filters['brand'] ?? null, fn (Builder $query, string $brand) => $query->where('brand', $brand))
            ->when($filters['brands'] ?? null, fn (Builder $query, mixed $brands) => $this->applyBrandFilter($query, $brands))
            ->when($filters['category'] ?? null, fn (Builder $query, mixed $category) => $this->applyCategoryFilter($query, $category))
            ->when($filters['categories'] ?? null, fn (Builder $query, mixed $categories) => $this->applyCategoryFilter($query, $categories))
            ->when(array_key_exists('price_min', $filters) && $filters['price_min'] !== null && $filters['price_min'] !== '', function (Builder $query) use ($driver, $filters) {
                $query->whereRaw($this->resolveInventoryPriceExpression($driver) . ' >= ?', [(float) $filters['price_min']]);
            })
            ->when(array_key_exists('price_max', $filters) && $filters['price_max'] !== null && $filters['price_max'] !== '', function (Builder $query) use ($driver, $filters) {
                $query->whereRaw($this->resolveInventoryPriceExpression($driver) . ' <= ?', [(float) $filters['price_max']]);
            })
            ->when($onlySyncActivated, function (Builder $query) use ($driver) {
                if ($driver === 'pgsql') {
                    $query->whereIn(
                        DB::raw("LOWER(COALESCE(mekari_status->>'sync_status', ''))"),
                        self::ACTIVATED_SYNC_STATUSES
                    );
                    return;
                }

                $query->whereIn(
                    DB::raw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(mekari_status, '$.sync_status')), ''))"),
                    self::ACTIVATED_SYNC_STATUSES
                );
            })
            ->when($excludeFailedSync, function (Builder $query) use ($driver) {
                if ($driver === 'pgsql') {
                    $query->whereRaw("LOWER(COALESCE(mekari_status->>'sync_status', '')) <> 'failed'");
                    return;
                }

                $query->whereRaw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(mekari_status, '$.sync_status')), '')) <> 'failed'");
            });
    }

    private function applyCatalogSorting(Builder $query, array $filters): void
    {
        $search = $this->normalizeSearchText((string) ($filters['search'] ?? ''));
        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'popular')));
        $driver = DB::connection()->getDriverName();

        if ($search !== '') {
            $this->applySearchConditions($query, $search, $driver);
            $query->orderByDesc('search_relevance');
        }

        match ($sortBy) {
            'price_asc' => $query->orderByRaw($this->resolveInventoryPriceExpression($driver) . ' asc'),
            'price_desc' => $query->orderByRaw($this->resolveInventoryPriceExpression($driver) . ' desc'),
            'newest' => $query->latest(),
            default => $query->orderByDesc('is_featured')->latest(),
        };
    }

    private function applyBrandFilter(Builder $query, mixed $brands): void
    {
        $brandTokens = collect(explode(',', (string) $brands))
            ->map(fn ($token) => trim((string) $token))
            ->filter()
            ->values();

        if ($brandTokens->isEmpty()) {
            return;
        }

        $brandIdTokens = $brandTokens
            ->filter(fn (string $token) => Str::isUuid($token))
            ->values();
        $normalized = $brandTokens->map(fn (string $token) => strtolower($token))->all();

        $query->where(function (Builder $nested) use ($brandIdTokens, $normalized) {
            if ($brandIdTokens->isNotEmpty()) {
                $nested
                    ->whereIn('brand_id', $brandIdTokens->all())
                    ->orWhereIn(DB::raw('LOWER(brand)'), $normalized);
            } else {
                $nested->whereIn(DB::raw('LOWER(brand)'), $normalized);
            }

            $nested->orWhereHas('brandModel', function (Builder $brandQuery) use ($normalized) {
                $brandQuery
                    ->whereIn(DB::raw('LOWER(slug)'), $normalized)
                    ->orWhereIn(DB::raw('LOWER(name)'), $normalized);
            });
        });
    }

    private function applyCategoryFilter(Builder $query, mixed $categories): void
    {
        $categoryTokens = collect(explode(',', (string) $categories))
            ->map(fn ($token) => trim((string) $token))
            ->filter()
            ->values();

        if ($categoryTokens->isEmpty()) {
            return;
        }

        $categoryIdTokens = $categoryTokens
            ->filter(fn (string $token) => Str::isUuid($token))
            ->values();
        $normalized = $categoryTokens
            ->flatMap(function (string $token): array {
                $lowered = strtolower($token);
                $spaced = preg_replace('/[-_]+/', ' ', $lowered) ?? $lowered;
                $spaced = preg_replace('/\s+/', ' ', trim($spaced)) ?? trim($lowered);

                return array_values(array_unique([
                    $lowered,
                    $spaced,
                ]));
            })
            ->filter()
            ->values()
            ->all();

        $query->where(function (Builder $nested) use ($categoryIdTokens, $normalized) {
            if ($categoryIdTokens->isNotEmpty()) {
                $nested
                    ->whereIn('category_id', $categoryIdTokens->all())
                    ->orWhereIn(DB::raw('LOWER(category)'), $normalized);
            } else {
                $nested->whereIn(DB::raw('LOWER(category)'), $normalized);
            }

            $nested->orWhereHas('category', function (Builder $categoryQuery) use ($categoryIdTokens, $normalized) {
                if ($categoryIdTokens->isNotEmpty()) {
                    $categoryQuery
                        ->whereIn('id', $categoryIdTokens->all())
                        ->orWhereIn(DB::raw('LOWER(name)'), $normalized);

                    return;
                }

                $categoryQuery->whereIn(DB::raw('LOWER(name)'), $normalized);
            });
        });
    }

    private function applySearchConditions(Builder $query, string $search, string $driver): void
    {
        if ($driver === 'pgsql') {
            $this->applyPostgresSearchConditions($query, $search);
            return;
        }

        $keyword = '%' . strtolower($search) . '%';
        $prefixKeyword = strtolower($search) . '%';

        $query
            ->selectRaw(
                "(CASE
                    WHEN LOWER(products.name) = ? THEN 100
                    WHEN LOWER(products.name) LIKE ? THEN 80
                    WHEN LOWER(COALESCE(products.spu, '')) = ? THEN 70
                    WHEN LOWER(products.name) LIKE ? THEN 40
                    WHEN LOWER(COALESCE(products.brand, '')) LIKE ? THEN 25
                    WHEN LOWER(COALESCE(products.category, '')) LIKE ? THEN 20
                    ELSE 0
                END) as search_relevance",
                [
                    strtolower($search),
                    $prefixKeyword,
                    strtolower($search),
                    $keyword,
                    $keyword,
                    $keyword,
                ]
            )
            ->where(function (Builder $nested) use ($keyword) {
                $nested
                    ->whereRaw('LOWER(products.name) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(COALESCE(products.brand, \'\')) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(COALESCE(products.category, \'\')) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(COALESCE(products.spu, \'\')) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(COALESCE(products.description, \'\')) LIKE ?', [$keyword]);
            });
    }

    private function applyPostgresSearchConditions(Builder $query, string $search): void
    {
        $hasTrigram = $this->supportsPostgresTrigram();
        $exactKeyword = strtolower($search);
        $prefixKeyword = $exactKeyword . '%';
        $containsKeyword = '%' . str_replace(' ', '%', $exactKeyword) . '%';
        $searchDocument = $this->resolvePostgresSearchDocumentExpression();
        $tsQuery = $this->buildPostgresTsQuery($search);

        $relevanceSql = "(CASE
                WHEN LOWER(products.name) = ? THEN 140
                WHEN LOWER(products.name) LIKE ? THEN 110
                WHEN LOWER(COALESCE(products.spu, '')) = ? THEN 95
                WHEN LOWER(COALESCE(products.category, '')) = ? THEN 75
                WHEN LOWER(products.name) LIKE ? THEN 55
                WHEN LOWER(COALESCE(products.brand, '')) LIKE ? THEN 35
                WHEN LOWER(COALESCE(products.category, '')) LIKE ? THEN 25
                ELSE 0
            END"
            . ($hasTrigram
                ? "
            + (GREATEST(similarity(LOWER(products.name), ?), 0) * 30)
            + (GREATEST(similarity(LOWER(COALESCE(products.spu, '')), ?), 0) * 20)
            + (GREATEST(similarity(LOWER(COALESCE(products.brand, '')), ?), 0) * 12)
            + (GREATEST(similarity(LOWER(COALESCE(products.category, '')), ?), 0) * 10)"
                : '')
            . ($tsQuery !== null
                ? " + (CASE
                    WHEN {$searchDocument} @@ to_tsquery('simple', ?) THEN ts_rank_cd({$searchDocument}, to_tsquery('simple', ?)) * 60
                    ELSE 0
                END)"
                : '')
            . ') as search_relevance';

        $bindings = [
            $exactKeyword,
            $prefixKeyword,
            $exactKeyword,
            $exactKeyword,
            $containsKeyword,
            $containsKeyword,
            $containsKeyword,
        ];

        if ($hasTrigram) {
            $bindings[] = $exactKeyword;
            $bindings[] = $exactKeyword;
            $bindings[] = $exactKeyword;
            $bindings[] = $exactKeyword;
        }

        if ($tsQuery !== null) {
            $bindings[] = $tsQuery;
            $bindings[] = $tsQuery;
        }

        $query->selectRaw($relevanceSql, $bindings)
            ->where(function (Builder $nested) use ($containsKeyword, $exactKeyword, $searchDocument, $tsQuery, $hasTrigram) {
                if ($tsQuery !== null) {
                    $nested->whereRaw("{$searchDocument} @@ to_tsquery('simple', ?)", [$tsQuery]);
                }

                $nested
                    ->orWhereRaw('LOWER(products.name) LIKE ?', [$containsKeyword])
                    ->orWhereRaw('LOWER(COALESCE(products.brand, \'\')) LIKE ?', [$containsKeyword])
                    ->orWhereRaw('LOWER(COALESCE(products.category, \'\')) LIKE ?', [$containsKeyword])
                    ->orWhereRaw('LOWER(COALESCE(products.spu, \'\')) LIKE ?', [$containsKeyword])
                    ->orWhereRaw('LOWER(COALESCE(products.description, \'\')) LIKE ?', [$containsKeyword]);

                if ($hasTrigram) {
                    $nested
                        ->orWhereRaw('similarity(LOWER(products.name), ?) >= 0.14', [$exactKeyword])
                        ->orWhereRaw('similarity(LOWER(COALESCE(products.spu, \'\')), ?) >= 0.14', [$exactKeyword]);
                }
            });
    }

    private function supportsPostgresTrigram(): bool
    {
        if ($this->postgresTrigramAvailable !== null) {
            return $this->postgresTrigramAvailable;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->postgresTrigramAvailable = false;
            return false;
        }

        try {
            $result = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm') AS available");
            $this->postgresTrigramAvailable = (bool) ($result->available ?? false);
        } catch (\Throwable) {
            $this->postgresTrigramAvailable = false;
        }

        return $this->postgresTrigramAvailable;
    }

    private function resolveInventoryPriceExpression(string $driver): string
    {
        if ($driver === 'pgsql') {
            return "COALESCE(NULLIF(products.inventory->>'price', ''), '0')::numeric";
        }

        return "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(products.inventory, '$.price')), '0') AS DECIMAL(15,2))";
    }

    private function resolvePostgresSearchDocumentExpression(): string
    {
        return "to_tsvector('simple', trim(both ' ' from concat_ws(' ', COALESCE(products.name, ''), COALESCE(products.spu, ''), COALESCE(products.brand, ''), COALESCE(products.category, ''), COALESCE(products.description, ''))))";
    }

    private function buildPostgresTsQuery(string $search): ?string
    {
        $terms = collect(preg_split('/\s+/', strtolower($search)) ?: [])
            ->map(fn (string $term) => preg_replace('/[^[:alnum:]]+/u', '', $term) ?? '')
            ->filter(fn (string $term) => $term !== '')
            ->take(8)
            ->values();

        if ($terms->isEmpty()) {
            return null;
        }

        return $terms
            ->map(fn (string $term) => "{$term}:*")
            ->implode(' & ');
    }

    private function normalizeSearchText(string $search): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($search))) ?? '';
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, string>
     */
    private function buildSuggestedKeywords(Collection $products, string $search): array
    {
        $searchTerms = collect(preg_split('/\s+/', strtolower($search)) ?: [])
            ->filter()
            ->values();

        $keywords = $products
            ->flatMap(function (Product $product): array {
                $items = [];

                $brand = trim((string) ($product->brand ?? ''));
                $category = trim((string) ($product->category ?? ''));
                $name = trim((string) ($product->name ?? ''));

                if ($brand !== '') {
                    $items[] = $brand;
                }

                if ($category !== '') {
                    $items[] = $category;
                }

                if ($name !== '') {
                    $nameParts = preg_split('/\s+/', $name) ?: [];
                    $items[] = implode(' ', array_slice($nameParts, 0, min(2, count($nameParts))));
                }

                return $items;
            })
            ->map(fn (string $keyword) => trim(preg_replace('/\s+/', ' ', $keyword) ?? ''))
            ->filter(fn (string $keyword) => $keyword !== '')
            ->reject(function (string $keyword) use ($search, $searchTerms): bool {
                $normalized = strtolower($keyword);
                return $normalized === strtolower($search)
                    || $searchTerms->contains($normalized)
                    || str_contains(strtolower($search), $normalized);
            })
            ->unique(fn (string $keyword) => strtolower($keyword))
            ->take(6)
            ->values();

        return $keywords->all();
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function store(array $validated, array $images = []): Product
    {
        $payload = $this->buildPayload($validated, null, $images);
        return Product::query()->create($payload);
    }

    /**
     * @param  array<int, UploadedFile>  $images
     */
    public function update(Product $product, array $validated, array $images = []): Product
    {
        $payload = $this->buildPayload($validated, $product, $images);
        $product->update($payload);
        return $product->refresh();
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedImages
     */
    private function buildPayload(array $validated, ?Product $product, array $uploadedImages): array
    {
        $existingInventory = is_array($product?->inventory) ? $product->inventory : [];
        $existingJurnalMetadata = is_array($product?->jurnal_metadata) ? $product->jurnal_metadata : [];
        $requestedInventory = is_array($validated['inventory'] ?? null) ? $validated['inventory'] : [];
        $inventory = array_merge($existingInventory, $requestedInventory);

        if (array_key_exists('price', $validated)) {
            $inventory['price'] = (float) $validated['price'];
        }
        if (array_key_exists('weight', $validated)) {
            $inventory['weight'] = (int) $validated['weight'];
        }
        $inventory = $this->normalizeInventory($inventory);

        $variantPricing = $this->normalizeVariantPricing(
            $validated['variant_pricing'] ?? ($product?->variant_pricing ?? [])
        );

        $calculatedStock = $this->calculateTotalStock(
            $variantPricing,
            $validated['stock'] ?? null,
            $inventory['total_stock'] ?? null,
            $product?->stock
        );
        $inventory['total_stock'] = $calculatedStock;
        $categoryId = (string) ($validated['category_id'] ?? $product?->category_id ?? '');
        $categoryName = $this->cleanText((string) ($validated['category'] ?? $product?->category ?? ''));
        $category = $this->resolveCategoryForPricing($categoryId, $categoryName);
        $brandId = $this->cleanText((string) ($validated['brand_id'] ?? $product?->brand_id ?? ''));
        $brandName = $this->cleanText((string) ($validated['brand'] ?? $product?->brand ?? ''));
        [$brandId, $brandName] = $this->resolveBrandReference($brandId, $brandName);

        if ($categoryName === '' && $categoryId !== '') {
            $categoryName = (string) (Category::query()->where('id', $categoryId)->value('name') ?? '');
        }

        $variantPricing = $this->recalculateVariantPricing($variantPricing, $category);
        $hasExplicitStatusInput = array_key_exists('status', $validated) || array_key_exists('product_status', $validated);
        $status = $this->resolveStatusValue(
            $validated['status'] ?? null,
            $validated['product_status'] ?? null,
            $product?->status,
            $product?->product_status
        );
        $productStatus = $hasExplicitStatusInput
            ? $this->mapStatusToLegacyProductStatus($status)
            : (string) ($product?->product_status ?? $this->mapStatusToLegacyProductStatus($status));
        $stockStatus = $this->resolveStockStatusValue(
            $validated['stock_status'] ?? null,
            $calculatedStock,
            $product?->stock_status
        );
        $isFeatured = array_key_exists('is_featured', $validated)
            ? (bool) $validated['is_featured']
            : (bool) ($product?->is_featured ?? false);
        $jurnalMetadata = $existingJurnalMetadata;

        if ($this->shouldLockMarketplaceState($validated)) {
            $jurnalMetadata['local_marketplace_state'] = [
                ...((is_array($existingJurnalMetadata['local_marketplace_state'] ?? null)
                    ? $existingJurnalMetadata['local_marketplace_state']
                    : [])),
                'locked' => true,
                'source' => 'admin_edit',
                'updated_at' => now()->toISOString(),
            ];
        }

        return [
            'name' => $this->cleanText((string) ($validated['name'] ?? $product?->name ?? '')),
            'category' => $categoryName,
            'category_id' => $categoryId !== '' ? $categoryId : null,
            'brand' => $brandName,
            'brand_id' => $brandId !== '' ? $brandId : null,
            'description' => $this->cleanDescription((string) ($validated['description'] ?? $product?->description ?? '')),
            'trade_in' => (bool) ($validated['trade_in'] ?? $product?->trade_in ?? false),
            'inventory' => $inventory,
            'variants' => $this->normalizeVariantsWithDefaults($validated['variants'] ?? ($product?->variants ?? [])),
            'variant_pricing' => $variantPricing,
            'photos' => $this->resolvePhotos($validated['photos'] ?? null, $uploadedImages, $product?->photos ?? []),
            'spu' => $validated['spu'] ?? $product?->spu ?? $this->generateSpu((string) ($validated['brand'] ?? $product?->brand ?? 'ENTRAVERSE')),
            'jurnal_metadata' => $jurnalMetadata !== [] ? $jurnalMetadata : null,
            'product_status' => $productStatus,
            'status' => $status,
            'is_featured' => $isFeatured,
            'stock_status' => $stockStatus,
            'stock' => $calculatedStock,
        ];
    }

    private function shouldLockMarketplaceState(array $validated): bool
    {
        if (array_key_exists('variant_pricing', $validated) || array_key_exists('variants', $validated)) {
            return true;
        }

        if (array_key_exists('price', $validated) || array_key_exists('weight', $validated)) {
            return true;
        }

        $inventory = is_array($validated['inventory'] ?? null) ? $validated['inventory'] : [];

        return array_key_exists('price', $inventory)
            || array_key_exists('weight', $inventory)
            || array_key_exists('total_stock', $inventory);
    }

    private function normalizeVariantPricing(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $stock = max(0, (int) ($item['stock'] ?? 0));
            $warehouse = trim((string) ($item['warehouse'] ?? ''));
            $warehouse = $warehouse !== '' ? $warehouse : self::DEFAULT_WAREHOUSE;
            $warehouseStock = $this->normalizeWarehouseStock(
                $item['warehouse_stock'] ?? null,
                $warehouse,
                $stock
            );

            $item['warehouse_stock'] = $warehouseStock;
            $item['warehouse'] = (string) (array_key_first($warehouseStock) ?? $warehouse);
            $item['stock'] = (int) array_sum($warehouseStock);
            $item['purchase_price'] = (float) ($item['purchase_price'] ?? 0);
            $item['purchase_price_idr'] = (float) ($item['purchase_price_idr'] ?? 0);
            $item['margin_percent'] = (float) ($item['margin_percent'] ?? 0);

            $normalized[] = $this->normalizeArray($item);
        }

        return $normalized;
    }

    private function resolveCategoryForPricing(string $categoryId, string $categoryName): ?Category
    {
        if ($categoryId !== '') {
            return Category::query()->find($categoryId)
                ?? Category::query()->withTrashed()->find($categoryId);
        }

        if ($categoryName === '') {
            return null;
        }

        return Category::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($categoryName)])
            ->first();
    }

    private function resolveBrandReference(string $brandId, string $brandName): array
    {
        $brand = null;

        if ($brandId !== '') {
            $brand = Brand::query()->find($brandId);
        }

        if (! $brand && $brandName !== '') {
            $normalized = strtolower($brandName);
            $brand = Brand::query()
                ->whereRaw('LOWER(name) = ?', [$normalized])
                ->orWhereRaw('LOWER(slug) = ?', [$normalized])
                ->first();
        }

        if ($brand) {
            return [(string) $brand->id, $this->cleanText((string) $brand->name)];
        }

        return ['', $brandName];
    }

    private function recalculateVariantPricing(array $variantPricing, ?Category $category): array
    {
        if ($variantPricing === []) {
            return [];
        }

        if (! $category) {
            return $variantPricing;
        }

        $minMarginPercent = max(0, $this->toFloat($category->margin_percent ?? $category->min_margin));
        $fees = is_array($category->fees) ? $category->fees : [];
        $warrantyConfig = $this->extractWarrantyConfig($category->program_garansi);
        $tokopediaChannel = $this->resolveFeeChannel($fees, ['tokopedia', 'tokopedia_tiktok', 'marketplace']);
        $shopeeChannel = $this->resolveFeeChannel($fees, ['shopee']);
        $entraverseChannel = $this->resolveFeeChannel($fees, ['entraverse']);

        if (($entraverseChannel['components'] ?? []) === []) {
            $entraverseChannel = ['components' => []];
        }

        return array_map(function (array $item) use (
            $minMarginPercent,
            $tokopediaChannel,
            $shopeeChannel,
            $entraverseChannel,
            $warrantyConfig
        ): array {
            $purchasePrice = max(0, $this->toFloat($item['purchase_price'] ?? 0));
            $exchangeValue = max(0, $this->toFloat($item['exchange_value'] ?? ($item['exchange_rate'] ?? 0)));
            $arrivalCost = max(0, $this->toFloat($item['arrival_cost'] ?? 0));
            $fixedCost = max(0, $this->toFloat($item['shipping_cost'] ?? 0));
            $marginPercent = $minMarginPercent;
            $purchasePriceIdr = round(($purchasePrice * $exchangeValue) + $arrivalCost);

            $warrantyOption = $this->extractWarrantyOption($item);
            $entraverseFee = $this->calculateFeeTotals($entraverseChannel, $purchasePriceIdr);
            $tokopediaFee = $this->calculateFeeTotals($tokopediaChannel, $purchasePriceIdr);
            $shopeeFee = $this->calculateFeeTotals($shopeeChannel, $purchasePriceIdr);

            $offlineBase = $this->calculateSellingPrice($purchasePriceIdr, 0, $marginPercent / 100, 0);
            $entraverseBase = $this->calculateSellingPrice(
                $purchasePriceIdr,
                $entraverseFee['fixed_total'],
                $marginPercent / 100,
                $entraverseFee['percent_total'],
            );
            $tokopediaBase = $this->calculateSellingPrice(
                $purchasePriceIdr,
                $tokopediaFee['fixed_total'],
                $marginPercent / 100,
                $tokopediaFee['percent_total'],
            );
            $shopeeBase = $this->calculateSellingPrice(
                $purchasePriceIdr,
                $shopeeFee['fixed_total'],
                $marginPercent / 100,
                $shopeeFee['percent_total'],
            );

            $offlineWithWarranty = $this->applyWarrantyMultiplier($warrantyOption, $offlineBase);
            $entraverseWithWarranty = $this->applyWarrantyMultiplier($warrantyOption, $entraverseBase);
            $tokopediaWithWarranty = $this->applyWarrantyMultiplier($warrantyOption, $tokopediaBase);
            $shopeeWithWarranty = $this->applyWarrantyMultiplier($warrantyOption, $shopeeBase);

            $item['exchange_rate'] = (float) round($exchangeValue, 4);
            $item['arrival_cost'] = (float) round($arrivalCost);
            $item['shipping_cost'] = (float) round($fixedCost);
            $item['margin_percent'] = (float) round($marginPercent, 4);
            $item['purchase_price_idr'] = (float) round($purchasePriceIdr);
            $item['offline_price'] = (float) $this->applyPriceRounding($offlineWithWarranty);
            $item['entraverse_price'] = (float) $this->applyPriceRounding($entraverseWithWarranty);
            $item['tokopedia_price'] = (float) $this->applyPriceRounding($tokopediaWithWarranty);
            $item['tiktok_price'] = (float) $this->applyPriceRounding($tokopediaWithWarranty);
            $item['shopee_price'] = (float) $this->applyPriceRounding($shopeeWithWarranty);
            $item['tokopedia_fee'] = (float) $tokopediaFee['percent_display'];
            $item['tiktok_fee'] = (float) $tokopediaFee['percent_display'];
            $item['shopee_fee'] = (float) $shopeeFee['percent_display'];

            return $item;
        }, $variantPricing);
    }

    private function resolveFeeChannel(array $fees, array $keys): array
    {
        $fallback = null;

        foreach ($keys as $key) {
            $candidate = $fees[$key] ?? null;
            if (is_array($candidate)) {
                $fallback ??= $candidate;
                if ($this->hasFeeComponents($candidate)) {
                    return $candidate;
                }
            }
        }

        return $fallback ?? ['components' => []];
    }

    private function hasFeeComponents(array $channel): bool
    {
        $components = $channel['components'] ?? null;
        if (! is_array($components)) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $label = trim((string) ($component['label'] ?? ''));
            $valueType = strtolower(trim((string) ($component['valueType'] ?? 'percent')));
            $isAmount = $valueType === 'amount' || $valueType === 'rp';
            $value = $isAmount
                ? $this->parseRupiahAmount($component['value'] ?? 0)
                : max(0, $this->toFloat($component['value'] ?? 0));
            $min = $this->parseRupiahAmount($component['min'] ?? 0);
            $max = $this->parseRupiahAmount($component['max'] ?? 0);

            if ($label !== '' || $value > 0 || $min > 0 || $max > 0) {
                return true;
            }
        }

        return false;
    }

    private function resolveFeeSummaryPercent(array $channel): float
    {
        $summary = is_array($channel['summary'] ?? null) ? $channel['summary'] : [];
        $candidates = [
            $channel['percent'] ?? null,
            $channel['rate'] ?? null,
            $channel['percentage'] ?? null,
            $channel['total_percent'] ?? null,
            $channel['totalPercent'] ?? null,
            $channel['summary'] ?? null,
            $summary['percent'] ?? null,
            $summary['rate'] ?? null,
            $summary['percentage'] ?? null,
            $summary['total_percent'] ?? null,
            $summary['totalPercent'] ?? null,
            $summary['value'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) || is_string($candidate)) {
                $resolved = max(0, $this->toFloat($candidate));
                if ($resolved > 0) {
                    return $resolved;
                }
            }
        }

        return 0.0;
    }

    private function calculateFeeTotals(array $channel, float $purchasePriceIdr): array
    {
        $components = is_array($channel['components'] ?? null) ? $channel['components'] : [];
        $safePurchasePrice = max(0, $purchasePriceIdr);
        $fixedTotal = 0.0;
        $percentTotal = 0.0;

        if ($components === []) {
            $summaryPercent = $this->resolveFeeSummaryPercent($channel);

            return [
                'fixed_total' => 0.0,
                'percent_total' => $summaryPercent / 100,
                'percent_display' => $summaryPercent,
            ];
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $valueType = strtolower(trim((string) ($component['valueType'] ?? 'percent')));
            $isAmount = $valueType === 'amount' || $valueType === 'rp';
            $value = $isAmount
                ? $this->parseRupiahAmount($component['value'] ?? 0)
                : max(0, $this->toFloat($component['value'] ?? 0));
            $minValue = $this->parseRupiahAmount($component['min'] ?? 0);
            $maxValue = $this->parseRupiahAmount($component['max'] ?? 0);

            if ($isAmount) {
                $fee = $value;

                if ($minValue > 0) {
                    $fee = max($fee, $minValue);
                }
                if ($maxValue > 0) {
                    $fee = min($fee, $maxValue);
                }

                $fixedTotal += max(0, $fee);
                continue;
            }

            $effectiveRate = $value / 100;

            if ($safePurchasePrice > 0) {
                if ($maxValue > 0) {
                    $effectiveRate = min($effectiveRate, $maxValue / $safePurchasePrice);
                }
                if ($minValue > 0) {
                    $effectiveRate = max($effectiveRate, $minValue / $safePurchasePrice);
                }
            }

            $percentTotal += max(0, $effectiveRate);
        }

        return [
            'fixed_total' => round($fixedTotal),
            'percent_total' => round($percentTotal, 6),
            'percent_display' => round($percentTotal * 100, 4),
        ];
    }

    private function calculateSellingPrice(
        float $purchasePriceIdr,
        float $fixedFeeAmount,
        float $marginRate,
        float $platformFeePercent,
    ): float {
        $safePurchasePrice = max(0, $purchasePriceIdr);
        $safeFixedFee = max(0, $fixedFeeAmount);
        $denominator = 1 - max(0, $marginRate) - max(0, $platformFeePercent);

        if ($denominator <= 0) {
            return $safePurchasePrice + $safeFixedFee;
        }

        return ($safePurchasePrice + $safeFixedFee) / $denominator;
    }

    private function parseRupiahAmount(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) round(max(0, $value));
        }

        if (! is_string($value)) {
            return 0.0;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return 0.0;
        }

        return (float) round((float) $digits);
    }

    private function applyPriceRounding(float $value): float
    {
        $safeValue = max(0, $value);

        if ($safeValue >= 500000) {
            return max(0, (round($safeValue / 50000) * 50000) - 1000);
        }

        if ($safeValue >= 250000) {
            return max(0, (round($safeValue / 10000) * 10000) - 1000);
        }

        if ($safeValue >= 100000) {
            return max(0, (round($safeValue / 5000) * 5000) - 1000);
        }

        return max(0, (round($safeValue / 1000) * 1000) - 100);
    }

    private function defaultWarrantyPricingComponent(): array
    {
        return [
            'valueType' => 'percent',
            'value' => 0.0,
        ];
    }

    private function defaultWarrantyConfig(): array
    {
        return [
            'components' => [],
            'pricing' => [
                'cost' => $this->defaultWarrantyPricingComponent(),
                'profit' => $this->defaultWarrantyPricingComponent(),
            ],
        ];
    }

    private function parseWarrantyPricingComponent(mixed $payload, array $fallback = []): array
    {
        $fallbackType = strtolower(trim((string) ($fallback['valueType'] ?? 'percent')));
        $fallbackValueType = in_array($fallbackType, ['amount', 'rp', 'rupiah'], true) ? 'amount' : 'percent';
        $fallbackValue = $fallbackValueType === 'amount'
            ? $this->parseRupiahAmount($fallback['value'] ?? 0)
            : max(0, $this->toFloat($fallback['value'] ?? 0));

        if (! is_array($payload)) {
            return [
                'valueType' => $fallbackValueType,
                'value' => $fallbackValue,
            ];
        }

        $rawType = strtolower(trim((string) ($payload['valueType'] ?? $fallbackValueType)));
        $valueType = in_array($rawType, ['amount', 'rp', 'rupiah'], true) ? 'amount' : 'percent';

        return [
            'valueType' => $valueType,
            'value' => $valueType === 'amount'
                ? $this->parseRupiahAmount($payload['value'] ?? $fallbackValue)
                : max(0, $this->toFloat($payload['value'] ?? $fallbackValue)),
        ];
    }

    private function setWarrantyPricingFromRecord(array $source, array &$pricing): void
    {
        $read = function (array $aliases) use ($source): mixed {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $source)) {
                    return $source[$alias];
                }
            }

            return null;
        };

        $costPayload = $read(['cost', 'biaya', 'biaya_program_garansi', 'cost_component']);
        if (! is_null($costPayload)) {
            $pricing['cost'] = $this->parseWarrantyPricingComponent($costPayload, $pricing['cost'] ?? []);
        }

        $profitPayload = $read(['profit', 'keuntungan', 'keuntungan_program_garansi', 'profit_component']);
        if (! is_null($profitPayload)) {
            $pricing['profit'] = $this->parseWarrantyPricingComponent($profitPayload, $pricing['profit'] ?? []);
        }
    }

    private function setWarrantyPricingFromLabel(string $label, array $component, array &$pricing): bool
    {
        $normalizedLabel = $this->normalizeLabel($label);

        if ($normalizedLabel === self::WARRANTY_COST_LABEL) {
            $pricing['cost'] = $this->parseWarrantyPricingComponent($component, $pricing['cost'] ?? []);
            return true;
        }

        if ($normalizedLabel === self::WARRANTY_PROFIT_LABEL) {
            $pricing['profit'] = $this->parseWarrantyPricingComponent($component, $pricing['profit'] ?? []);
            return true;
        }

        return false;
    }

    private function appendWarrantyComponent(array &$components, array $component): void
    {
        $label = $this->normalizeLabel((string) ($component['label'] ?? ''));
        if ($label === '') {
            return;
        }

        $dedupeKey = strtolower($label);
        foreach ($components as $existing) {
            if (($existing['key'] ?? '') === $dedupeKey) {
                return;
            }
        }

        $rawType = strtolower(trim((string) ($component['valueType'] ?? 'percent')));
        $valueType = in_array($rawType, ['amount', 'rp', 'rupiah'], true) ? 'amount' : 'percent';
        $components[] = [
            'key' => $dedupeKey,
            'label' => $label,
            'valueType' => $valueType,
            'value' => $valueType === 'amount'
                ? $this->parseRupiahAmount($component['value'] ?? 0)
                : max(0, $this->toFloat($component['value'] ?? 0)),
        ];
    }

    private function extractWarrantyConfig(mixed $programGaransi): array
    {
        $config = $this->defaultWarrantyConfig();
        $components = &$config['components'];
        $pricing = &$config['pricing'];

        $parseComponent = function (mixed $row) use (&$components, &$pricing): void {
            if (is_string($row)) {
                $label = $this->normalizeLabel($row);
                if ($label !== '') {
                    $this->appendWarrantyComponent($components, [
                        'label' => $label,
                        'valueType' => 'percent',
                        'value' => 0,
                    ]);
                }
                return;
            }

            if (! is_array($row)) {
                return;
            }

            $component = [
                'label' => (string) ($row['label'] ?? $row['name'] ?? ''),
                'valueType' => (string) ($row['valueType'] ?? 'percent'),
                'value' => $row['value'] ?? 0,
            ];

            if ($this->setWarrantyPricingFromLabel((string) $component['label'], $component, $pricing)) {
                return;
            }

            $this->appendWarrantyComponent($components, $component);
        };

        if (is_array($programGaransi)) {
            if (array_is_list($programGaransi)) {
                foreach ($programGaransi as $row) {
                    $parseComponent($row);
                }
                return $config;
            }

            if (is_array($programGaransi['pricing'] ?? null)) {
                $this->setWarrantyPricingFromRecord($programGaransi['pricing'], $pricing);
            }
            $this->setWarrantyPricingFromRecord($programGaransi, $pricing);

            $nested = $programGaransi['components'] ?? null;
            if (is_array($nested)) {
                foreach ($nested as $row) {
                    $parseComponent($row);
                }
            } else {
                $parseComponent($programGaransi);
            }

            return $config;
        }

        if (! is_string($programGaransi) || trim($programGaransi) === '') {
            return $config;
        }

        $decoded = json_decode($programGaransi, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->extractWarrantyConfig($decoded);
        }

        $labels = preg_split('/[\r\n,]+/', $programGaransi) ?: [];
        foreach ($labels as $label) {
            $parseComponent($label);
        }

        return $config;
    }

    private function extractWarrantyOption(array $item): string
    {
        $options = $item['options'] ?? null;
        if (is_array($options)) {
            foreach ($options as $key => $value) {
                if ($this->normalizeLabel((string) $key) === 'garansi') {
                    return $this->normalizeLabel((string) $value);
                }
            }
        }

        $label = trim((string) ($item['label'] ?? ''));
        if ($label !== '' && preg_match('/garansi\s*:\s*([^\/|]+)/i', $label, $matches) === 1) {
            return $this->normalizeLabel((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function hasWarrantyPricing(array $pricing): bool
    {
        $costValue = max(0, $this->toFloat($pricing['cost']['value'] ?? 0));
        $profitValue = max(0, $this->toFloat($pricing['profit']['value'] ?? 0));

        if ($costValue > 0) {
            return true;
        }

        $profitValueType = strtolower(trim((string) ($pricing['profit']['valueType'] ?? 'percent')));
        return in_array($profitValueType, ['amount', 'rp', 'rupiah'], true) && $profitValue > 0;
    }

    private function calculateWarrantyValue(float $baseRecommended, array $component, ?float $percentBase = null): float
    {
        $valueType = strtolower(trim((string) ($component['valueType'] ?? 'percent')));
        if (in_array($valueType, ['amount', 'rp', 'rupiah'], true)) {
            return round($this->parseRupiahAmount($component['value'] ?? 0));
        }

        $percent = max(0, $this->toFloat($component['value'] ?? 0));
        $base = max(0, $percentBase ?? $baseRecommended);
        return round($base * ($percent / 100));
    }

    private function applyWarrantyMultiplier(string $warrantyOption, float $baseRecommended): float
    {
        if ($warrantyOption === '' || str_contains($warrantyOption, 'tanpa')) {
            return $baseRecommended;
        }

        if (preg_match('/(^|[^0-9])1\s*tahun/u', $warrantyOption) === 1 || str_contains($warrantyOption, '1th') || str_contains($warrantyOption, '1 th')) {
            return $baseRecommended * 1.06;
        }

        return $baseRecommended;
    }

    private function normalizeLabel(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    private function toFloat(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return 0.0;
        }

        $normalized = preg_replace('/[^0-9,.\-]/', '', trim($value)) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $lastComma = strrpos($normalized, ',');
            $lastDot = strrpos($normalized, '.');
            if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',') && ! str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        } elseif (substr_count($normalized, '.') > 1) {
            $normalized = str_replace('.', '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function normalizeWarehouseStock(mixed $warehouseStock, string $fallbackWarehouse, int $fallbackStock): array
    {
        $normalized = [];

        if (is_array($warehouseStock)) {
            foreach ($warehouseStock as $warehouse => $qty) {
                $warehouseName = trim(is_string($warehouse) ? $warehouse : '');
                if ($warehouseName === '') {
                    continue;
                }

                $normalized[$warehouseName] = max(0, (int) $qty);
            }
        }

        if ($normalized === []) {
            $normalized[$fallbackWarehouse] = max(0, $fallbackStock);
        }

        return $normalized;
    }

    private function calculateTotalStock(array $variantPricing, mixed $fallbackStock, mixed $inventoryStock, mixed $currentStock): int
    {
        $fromVariants = (int) collect($variantPricing)->sum(fn (array $item) => (int) ($item['stock'] ?? 0));
        if ($fromVariants > 0 || ($variantPricing !== [] && $fromVariants === 0)) {
            return $fromVariants;
        }

        if ($fallbackStock !== null) {
            return max(0, (int) $fallbackStock);
        }

        if ($inventoryStock !== null) {
            return max(0, (int) $inventoryStock);
        }

        return max(0, (int) ($currentStock ?? 0));
    }

    private function normalizeStatusFilter(string $status): ?string
    {
        return match ($status) {
            'active' => 'active',
            'inactive' => 'inactive',
            'draft', 'pending', 'pending_approval', 'archived' => 'draft',
            default => null,
        };
    }

    private function normalizeBooleanFilter(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private function mapLegacyProductStatusToStatus(string $legacyStatus): string
    {
        return match (strtolower(trim($legacyStatus))) {
            'active' => 'active',
            'inactive' => 'inactive',
            default => 'draft',
        };
    }

    private function mapStatusToLegacyProductStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'active' => 'active',
            'inactive' => 'inactive',
            default => 'pending_approval',
        };
    }

    private function resolveStatusValue(
        mixed $statusInput,
        mixed $legacyStatusInput,
        mixed $existingStatus,
        mixed $existingLegacyStatus
    ): string {
        $normalizedStatus = $this->normalizeStatusFilter(strtolower(trim((string) $statusInput)));
        if ($normalizedStatus !== null) {
            return $normalizedStatus;
        }

        $normalizedLegacy = strtolower(trim((string) $legacyStatusInput));
        if ($normalizedLegacy !== '') {
            return $this->mapLegacyProductStatusToStatus($normalizedLegacy);
        }

        $currentStatus = $this->normalizeStatusFilter(strtolower(trim((string) $existingStatus)));
        if ($currentStatus !== null) {
            return $currentStatus;
        }

        $legacyStatus = strtolower(trim((string) $existingLegacyStatus));
        if ($legacyStatus !== '') {
            return $this->mapLegacyProductStatusToStatus($legacyStatus);
        }

        return 'active';
    }

    private function resolveStockStatusValue(mixed $input, int $calculatedStock, mixed $existingStockStatus): string
    {
        $normalizedInput = strtolower(trim((string) $input));
        if (in_array($normalizedInput, ['in_stock', 'out_of_stock', 'preorder'], true)) {
            return $normalizedInput;
        }

        $normalizedExisting = strtolower(trim((string) $existingStockStatus));
        if ($normalizedInput === '' && in_array($normalizedExisting, ['in_stock', 'out_of_stock', 'preorder'], true)) {
            return $normalizedExisting;
        }

        return $calculatedStock <= 0 ? 'out_of_stock' : 'in_stock';
    }

    private function normalizeInventory(array $inventory): array
    {
        $dimensions = is_array($inventory['dimensions_cm'] ?? null) ? $inventory['dimensions_cm'] : [];

        $inventory['dimensions_cm'] = [
            'length' => max(0, (float) ($dimensions['length'] ?? 0)),
            'width' => max(0, (float) ($dimensions['width'] ?? 0)),
            'height' => max(0, (float) ($dimensions['height'] ?? 0)),
        ];

        $inventory['volume_m3'] = max(0, (float) ($inventory['volume_m3'] ?? 0));

        if (array_key_exists('weight', $inventory)) {
            $inventory['weight'] = max(0, (int) $inventory['weight']);
        }

        if (array_key_exists('total_stock', $inventory)) {
            $inventory['total_stock'] = max(0, (int) $inventory['total_stock']);
        }

        if (array_key_exists('price', $inventory)) {
            $inventory['price'] = max(0, (float) $inventory['price']);
        }

        return $inventory;
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedImages
     * @param  array<int|string, mixed>  $existingPhotos
     * @return array<int, array<string, mixed>>
     */
    private function resolvePhotos(mixed $photos, array $uploadedImages, array $existingPhotos): array
    {
        $uploadMarkerPrefix = '__UPLOAD__:';

        $isValidUrl = static function (string $value): bool {
            $trimmed = trim($value);
            if ($trimmed === '') return false;
            return !str_starts_with($trimmed, 'blob:') && !str_starts_with($trimmed, 'data:');
        };

        $normalizePhotoItem = static function (mixed $photo) use ($isValidUrl): ?array {
            if (is_string($photo)) {
                $trimmed = trim($photo);
                if (! $isValidUrl($trimmed)) return null;
                return [
                    'url' => $trimmed,
                    'alt' => null,
                    'is_primary' => false,
                ];
            }

            if (is_array($photo)) {
                $url = trim((string) ($photo['url'] ?? ''));
                if (! $isValidUrl($url)) return null;
                return [
                    'url' => $url,
                    'alt' => is_string($photo['alt'] ?? null) ? $photo['alt'] : null,
                    'is_primary' => false,
                ];
            }

            return null;
        };

        $sanitizePhotos = function (array $items): array {
            return array_values(array_filter($items, function (mixed $photo): bool {
                if (is_string($photo)) {
                    $trimmed = trim($photo);
                    if ($trimmed === '') return false;
                    return !str_starts_with($trimmed, 'blob:') && !str_starts_with($trimmed, 'data:');
                }

                if (is_array($photo)) {
                    $url = trim((string) ($photo['url'] ?? ''));
                    if ($url === '') return false;
                    return !str_starts_with($url, 'blob:') && !str_starts_with($url, 'data:');
                }

                return false;
            }));
        };

        $mappedUploads = [];
        foreach ($uploadedImages as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('products', 'public');
            $mappedUploads[] = [
                'url' => '/storage/' . ltrim($path, '/'),
                'alt' => null,
                'is_primary' => false,
            ];
        }

        // New synchronized flow: `photos` can contain ordered placeholders "__UPLOAD__:<n>".
        if (is_array($photos)) {
            $orderedPhotos = [];
            $uploadCursor = 0;

            foreach ($this->normalizeArray($photos) as $photo) {
                if (is_string($photo) && str_starts_with($photo, $uploadMarkerPrefix)) {
                    if (isset($mappedUploads[$uploadCursor])) {
                        $orderedPhotos[] = $mappedUploads[$uploadCursor];
                        $uploadCursor++;
                    }
                    continue;
                }

                $normalized = $normalizePhotoItem($photo);
                if ($normalized !== null) {
                    $orderedPhotos[] = $normalized;
                }
            }

            // Backward compatibility: append remaining uploaded files if marker not provided for all.
            while (isset($mappedUploads[$uploadCursor])) {
                $orderedPhotos[] = $mappedUploads[$uploadCursor];
                $uploadCursor++;
            }

            return $this->finalizePhotos($orderedPhotos);
        }

        $basePhotos = $sanitizePhotos($this->normalizeArray($existingPhotos));
        $normalizedBase = array_values(array_filter(array_map($normalizePhotoItem, $basePhotos)));
        $merged = array_values(array_merge($normalizedBase, $mappedUploads));

        return $this->finalizePhotos($merged);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function finalizePhotos(array $items): array
    {
        $result = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '' || str_starts_with($url, 'blob:') || str_starts_with($url, 'data:')) {
                continue;
            }

            $dedupeKey = strtolower($url);
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $result[] = [
                'url' => $url,
                'alt' => is_string($item['alt'] ?? null) ? $item['alt'] : null,
                'is_primary' => false,
            ];

            if (count($result) >= self::MAX_PRODUCT_PHOTOS) {
                break;
            }
        }

        return array_values(array_map(
            fn (array $photo, int $index) => [
                ...$photo,
                'is_primary' => $index === 0,
            ],
            $result,
            array_keys($result)
        ));
    }

    private function normalizeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $cleanKey = is_string($key) ? $this->cleanText($key) : $key;
            if (is_array($item)) {
                $result[$cleanKey] = $this->normalizeArray($item);
                continue;
            }

            $result[$cleanKey] = is_string($item) ? $this->cleanText($item) : $item;
        }

        return $result;
    }

    private function normalizeVariantsWithDefaults(mixed $value): array
    {
        $variants = $this->normalizeArray($value);
        $rows = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $name = $this->cleanText((string) ($variant['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $options = [];
            $rawOptions = is_array($variant['options'] ?? null) ? $variant['options'] : [];
            foreach ($rawOptions as $option) {
                $cleanOption = $this->cleanText((string) $option);
                if ($cleanOption !== '') {
                    $options[] = $cleanOption;
                }
            }

            $rows[] = [
                'name' => $name,
                'options' => array_values(array_unique($options)),
            ];
        }

        $warrantyIndex = null;
        foreach ($rows as $index => $variant) {
            if (strtolower((string) ($variant['name'] ?? '')) === strtolower(self::DEFAULT_WARRANTY_VARIANT_NAME)) {
                $warrantyIndex = $index;
                break;
            }
        }

        if ($warrantyIndex === null) {
            $rows[] = [
                'name' => self::DEFAULT_WARRANTY_VARIANT_NAME,
                'options' => self::DEFAULT_WARRANTY_OPTIONS,
            ];
        } else {
            $existingOptions = is_array($rows[$warrantyIndex]['options'] ?? null) ? $rows[$warrantyIndex]['options'] : [];
            $rows[$warrantyIndex]['name'] = self::DEFAULT_WARRANTY_VARIANT_NAME;
            $rows[$warrantyIndex]['options'] = array_values(array_unique([
                ...self::DEFAULT_WARRANTY_OPTIONS,
                ...$existingOptions,
            ]));
        }

        return $rows;
    }

    private function cleanText(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function cleanDescription(string $value): ?string
    {
        $normalizedInput = $this->normalizeDescriptionInput($value);
        if ($normalizedInput === '') {
            return null;
        }

        $withoutDangerousTags = preg_replace(
            '/<(script|style|iframe|object|embed|form|input|button|textarea|select|option|link|meta)[^>]*>.*?<\/\1>/is',
            '',
            $normalizedInput
        );

        $safeHtml = preg_replace(
            '/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i',
            '',
            $withoutDangerousTags ?? $normalizedInput
        );

        $safeHtml = preg_replace(
            '/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')/i',
            '',
            $safeHtml ?? ''
        );

        $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><blockquote>';
        $sanitized = strip_tags($safeHtml ?? '', $allowedTags);
        $sanitized = preg_replace('/<p>\s*<\/p>/i', '<p><br></p>', $sanitized ?? '');
        $sanitized = preg_replace('/<p>\s*&nbsp;\s*<\/p>/i', '<p><br></p>', $sanitized ?? '');
        $sanitized = preg_replace('/<p>\s*-\s*<\/p>/i', '<p><br></p>', $sanitized ?? '');
        $sanitized = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $sanitized ?? '');
        $sanitized = preg_replace('/<\/(p|li|blockquote|h[1-6])>\s*</i', '</$1>' . PHP_EOL . '<', $sanitized ?? '');
        $sanitized = trim((string) $sanitized);

        return $sanitized !== '' ? $sanitized : null;
    }

    private function normalizeDescriptionInput(string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($value));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/<\/?[a-z][\s\S]*>/i', $normalized) === 1) {
            return $normalized;
        }

        $lines = preg_split('/\n/', $normalized) ?: [];
        $result = [];
        $buffer = [];

        $flushBuffer = function () use (&$buffer, &$result): void {
            $chunk = trim(implode("\n", $buffer));
            if ($chunk !== '') {
                $escaped = htmlspecialchars($chunk, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $result[] = '<p>' . str_replace("\n", '<br>', $escaped) . '</p>';
            }

            $buffer = [];
        };

        foreach ($lines as $line) {
            $chunk = trim((string) $line);

            if ($this->isDescriptionSpacerMarker($chunk)) {
                $flushBuffer();
                $result[] = '<p><br></p>';
                continue;
            }

            if ($chunk === '') {
                $flushBuffer();
                continue;
            }

            $buffer[] = (string) $line;
        }

        $flushBuffer();
        return implode(PHP_EOL, $result);
    }

    private function isDescriptionSpacerMarker(string $value): bool
    {
        return preg_match('/^-$/', trim($value)) === 1;
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
