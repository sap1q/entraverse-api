<?php

// app/Http/Controllers/Api/V1/CategoryController.php

namespace App\Http\Controllers\Api\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories.
     */
    public function index()
    {
        try {
            // CEK KONEKSI DATABASE DULU
            try {
                DB::connection()->getPdo();
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Database connection failed',
                    'error' => $e->getMessage()
                ], 500);
            }

            // CEK APAKAH TABEL categories ADA
            if (!DB::getSchemaBuilder()->hasTable('categories')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Table categories does not exist'
                ], 500);
            }

            // AMBIL DATA
            $categories = Category::all();
            
            return response()->json([
                'success' => true,
                'data' => $categories,
                'count' => $categories->count()
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('SQL Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Database query error',
                'error' => $e->getMessage()
            ], 500);
            
        } catch (\Exception $e) {
            Log::error('General Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of categories with pagination and filters.
     */
    public function list(Request $request)
    {
        try {
            $query = Category::query();

            // Search by name
            if ($request->has('search')) {
                $query->where('name', 'LIKE', '%' . $request->search . '%');
            }

            // Filter by min margin
            if ($request->has('min_margin')) {
                $query->where('min_margin', '>=', $request->min_margin);
            }

            // Include trashed
            if ($request->has('with_trashed') && $request->with_trashed) {
                $query->withTrashed();
            }

            // Only trashed
            if ($request->has('only_trashed') && $request->only_trashed) {
                $query->onlyTrashed();
            }

            // Sorting
            $sortField = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortField, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $categories = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $categories->items(),
                'pagination' => [
                    'total' => $categories->total(),
                    'per_page' => $categories->perPage(),
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                    'from' => $categories->firstItem(),
                    'to' => $categories->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Category list error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'margin_percent' => 'nullable|numeric|min:0|max:99.99',
                'min_margin' => 'nullable|numeric|min:0|max:99.99',
                'fees' => 'nullable',
                'program_garansi' => 'nullable',
                'icon' => 'nullable|file|mimes:svg,png,jpg,jpeg,webp|max:5120',
                'icon_svg' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $data = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'name' => trim((string) $request->input('name')),
            ];

            $marginPercent = $this->resolveMarginPercent($request, true);
            $data['margin_percent'] = $marginPercent;
            $data['min_margin'] = $marginPercent;
            $data['fees'] = $this->normalizeFeesPayload($this->decodeJsonInput($request->input('fees')));

            $programGaransi = $this->decodeJsonInput($request->input('program_garansi'));
            if (! is_null($programGaransi)) {
                $data['program_garansi'] = $programGaransi;
            }

            // Handle icon
            if ($request->hasFile('icon')) {
                $path = $request->file('icon')->store('categories/icons', 'public');
                $data['icon'] = '/storage/' . $path;
            } elseif ($request->filled('icon_svg')) {
                $data['icon'] = $request->icon_svg;
            }

            $category = Category::create($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => $category
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Store error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified category.
     */
    public function show($id)
    {
        try {
            $category = Category::withTrashed()->find($id);
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            Log::error('Show error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, $id)
    {
        try {
            $category = Category::find($id);
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'margin_percent' => 'sometimes|numeric|min:0|max:99.99',
                'min_margin' => 'sometimes|numeric|min:0|max:99.99',
                'fees' => 'nullable',
                'program_garansi' => 'nullable',
                'icon' => 'nullable|file|mimes:svg,png,jpg,jpeg,webp|max:5120',
                'icon_svg' => 'nullable|string',
                'remove_icon' => 'nullable|boolean',
            ]);

            DB::beginTransaction();

            $data = [];

            if ($request->has('name')) {
                $data['name'] = trim((string) $request->input('name'));
            }

            if ($request->has('margin_percent') || $request->has('min_margin')) {
                $marginPercent = $this->resolveMarginPercent($request, false);
                if (! is_null($marginPercent)) {
                    $data['margin_percent'] = $marginPercent;
                    $data['min_margin'] = $marginPercent;
                }
            }

            if ($request->has('fees')) {
                $data['fees'] = $this->normalizeFeesPayload($this->decodeJsonInput($request->input('fees')));
            }

            if ($request->has('program_garansi')) {
                $programGaransi = $this->decodeJsonInput($request->input('program_garansi'));
                $data['program_garansi'] = $programGaransi;
            }

            // Handle icon update
            if ($request->hasFile('icon')) {
                // Delete old icon
                if ($category->icon && str_starts_with($category->icon, '/storage/')) {
                    $oldPath = str_replace('/storage/', '', $category->icon);
                    if (\Storage::disk('public')->exists($oldPath)) {
                        \Storage::disk('public')->delete($oldPath);
                    }
                }
                
                $path = $request->file('icon')->store('categories/icons', 'public');
                $data['icon'] = '/storage/' . $path;
                
            } elseif ($request->filled('icon_svg')) {
                // Delete old icon file if exists
                if ($category->icon && str_starts_with($category->icon, '/storage/')) {
                    $oldPath = str_replace('/storage/', '', $category->icon);
                    if (\Storage::disk('public')->exists($oldPath)) {
                        \Storage::disk('public')->delete($oldPath);
                    }
                }
                
                $data['icon'] = $request->icon_svg;
                
            } elseif ($request->boolean('remove_icon')) {
                // Remove icon without replacing
                if ($category->icon && str_starts_with($category->icon, '/storage/')) {
                    $oldPath = str_replace('/storage/', '', $category->icon);
                    if (\Storage::disk('public')->exists($oldPath)) {
                        \Storage::disk('public')->delete($oldPath);
                    }
                }
                
                $data['icon'] = null;
            }

            $category->update($data);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => $category->fresh()
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified category (soft delete).
     */
    public function destroy($id)
    {
        try {
            $category = Category::find($id);
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'Category deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Delete error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore soft deleted category.
     */
    public function restore($id)
    {
        try {
            $category = Category::withTrashed()->find($id);
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $category->restore();

            return response()->json([
                'success' => true,
                'message' => 'Category restored successfully',
                'data' => $category
            ]);

        } catch (\Exception $e) {
            Log::error('Restore error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force delete category permanently.
     */
    public function forceDelete($id)
    {
        try {
            $category = Category::withTrashed()->find($id);
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            // Force delete akan menghapus icon juga via model boot
            $category->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'Category permanently deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Force delete error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get category statistics.
     */
    public function statistics()
    {
        try {
            $stats = [
                'total' => Category::count(),
                'active' => Category::whereNull('deleted_at')->count(),
                'deleted' => Category::onlyTrashed()->count(),
                'with_icon' => Category::whereNotNull('icon')->count(),
                'avg_margin' => round(Category::avg('min_margin'), 2),
                'max_margin' => Category::max('min_margin'),
                'min_margin' => Category::min('min_margin')
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Statistics error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete categories.
     */
    public function bulkDelete(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ids' => 'required|array',
                'ids.*' => 'string|exists:categories,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $count = Category::whereIn('id', $request->ids)->delete();

            return response()->json([
                'success' => true,
                'message' => "{$count} categories deleted successfully"
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk delete error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if category name exists.
     */
    public function checkName(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'exclude_id' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = Category::where('name', $request->name);
            
            if ($request->has('exclude_id')) {
                $query->where('id', '!=', $request->exclude_id);
            }

            $exists = $query->exists();

            return response()->json([
                'success' => true,
                'data' => [
                    'exists' => $exists,
                    'message' => $exists ? 'Name already taken' : 'Name available'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Check name error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to check name',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function resolveMarginPercent(Request $request, bool $required): ?float
    {
        $rawMargin = $request->input('margin_percent', $request->input('min_margin'));
        if (is_null($rawMargin) || $rawMargin === '') {
            if ($required) {
                return 0.0;
            }

            return null;
        }

        return max(0, round((float) $rawMargin, 2));
    }

    private function decodeJsonInput(mixed $value): mixed
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    private function normalizeFeeChannel(mixed $channel): array
    {
        $components = [];

        if (is_array($channel)) {
            $source = $channel['components'] ?? [];
            if (is_array($source)) {
                foreach ($source as $component) {
                    if (! is_array($component)) {
                        continue;
                    }

                    $valueType = $this->normalizeValueType($component['valueType'] ?? 'percent');

                    $components[] = [
                        'id' => isset($component['id']) ? (string) $component['id'] : null,
                        'label' => trim((string) ($component['label'] ?? '')),
                        'value' => $valueType === 'amount'
                            ? $this->parseRupiahAmount($component['value'] ?? 0)
                            : $this->parseDecimalNumber($component['value'] ?? 0),
                        'valueType' => $valueType,
                        'min' => $this->parseRupiahAmount($component['min'] ?? 0),
                        'max' => $this->parseRupiahAmount($component['max'] ?? 0),
                        'notes' => isset($component['notes']) ? (string) $component['notes'] : null,
                    ];
                }
            }
        }

        return ['components' => $components];
    }

    private function normalizeFeesPayload(mixed $rawFees): array
    {
        $fees = is_array($rawFees) ? $rawFees : [];
        $defaults = $this->defaultFees();

        $tokopediaSource = $this->pickFeeSource($fees, ['marketplace', 'tokopedia', 'tokopedia_tiktok']);

        $normalized = [
            'entraverse' => $this->normalizeFeeChannel($fees['entraverse'] ?? null),
            'tokopedia' => $this->normalizeFeeChannel($tokopediaSource),
            'shopee' => $this->normalizeFeeChannel($fees['shopee'] ?? null),
        ];

        // Backward compatibility for legacy consumers.
        $normalized['marketplace'] = $normalized['tokopedia'];
        $normalized['tokopedia_tiktok'] = $normalized['tokopedia'];

        return array_replace($defaults, $normalized);
    }

    private function defaultFees(): array
    {
        return [
            'entraverse' => ['components' => []],
            'tokopedia' => ['components' => []],
            'shopee' => ['components' => []],
            'marketplace' => ['components' => []],
            'tokopedia_tiktok' => ['components' => []],
        ];
    }

    private function pickFeeSource(array $fees, array $keys): mixed
    {
        $fallback = null;

        foreach ($keys as $key) {
            if (! array_key_exists($key, $fees)) {
                continue;
            }

            $candidate = $fees[$key];
            if (! is_array($candidate)) {
                continue;
            }

            $fallback ??= $candidate;

            if ($this->hasFeeComponents($candidate)) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private function hasFeeComponents(mixed $channel): bool
    {
        if (! is_array($channel)) {
            return false;
        }

        $components = $channel['components'] ?? null;
        if (! is_array($components)) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $label = trim((string) ($component['label'] ?? ''));
            $valueType = $this->normalizeValueType($component['valueType'] ?? 'percent');
            $value = $valueType === 'amount'
                ? $this->parseRupiahAmount($component['value'] ?? 0)
                : $this->parseDecimalNumber($component['value'] ?? 0);
            $min = $this->parseRupiahAmount($component['min'] ?? 0);
            $max = $this->parseRupiahAmount($component['max'] ?? 0);

            if ($label !== '' || $value > 0 || $min > 0 || $max > 0) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValueType(mixed $valueType): string
    {
        $normalized = strtolower(trim((string) $valueType));
        if (in_array($normalized, ['amount', 'rp', 'rupiah'], true)) {
            return 'amount';
        }

        return 'percent';
    }

    private function parseDecimalNumber(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) max(0, $value);
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

        return is_numeric($normalized) ? max(0, (float) $normalized) : 0.0;
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
}
