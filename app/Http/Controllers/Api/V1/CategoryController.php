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
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'min_margin' => 'required|numeric|min:0|max:100',
                'fees' => 'nullable|json',
                'program_garansi' => 'nullable|json',
                'icon' => 'nullable|file|mimes:svg,png|max:2048',
                'icon_svg' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $data = [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'name' => $request->name,
                'min_margin' => $request->min_margin,
            ];

            // Handle fees
            if ($request->filled('fees')) {
                $data['fees'] = json_decode($request->fees, true);
            } else {
                $data['fees'] = [
                    'marketplace' => ['components' => []],
                    'shopee' => ['components' => []],
                    'entraverse' => ['components' => []],
                    'tokopedia_tiktok' => ['components' => []],
                ];
            }

            // Handle program_garansi
            if ($request->filled('program_garansi')) {
                $data['program_garansi'] = json_decode($request->program_garansi, true);
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

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'min_margin' => 'sometimes|numeric|min:0|max:100',
                'fees' => 'nullable|json',
                'program_garansi' => 'nullable|json',
                'icon' => 'nullable|file|mimes:svg,png|max:2048',
                'icon_svg' => 'nullable|string',
                'remove_icon' => 'nullable|boolean',
            ]);

            DB::beginTransaction();

            $data = [];

            if ($request->has('name')) {
                $data['name'] = $request->name;
            }

            if ($request->has('min_margin')) {
                $data['min_margin'] = $request->min_margin;
            }

            if ($request->filled('fees')) {
                $data['fees'] = json_decode($request->fees, true);
            }

            if ($request->filled('program_garansi')) {
                $data['program_garansi'] = json_decode($request->program_garansi, true);
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
}
