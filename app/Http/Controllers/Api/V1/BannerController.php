<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BannerRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BannerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Banner::query()->orderBy('order', 'asc')->orderBy('created_at', 'desc');

        if ($request->boolean('only_trashed')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $banners = $query->get();

        return response()->json([
            'success' => true,
            'data' => BannerResource::collection($banners)->resolve(),
            'count' => $banners->count(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $query = Banner::query();
        if ($request->boolean('with_trashed', true)) {
            $query->withTrashed();
        }

        $banner = $query->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => (new BannerResource($banner))->resolve(),
        ]);
    }

    public function store(BannerRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $image = $request->file('image');
            if (! $image) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gambar banner wajib diunggah.',
                ], 422);
            }

            $storedPath = $this->storeImage($image);
            $nextOrder = (int) (Banner::withTrashed()->max('order') ?? 0) + 1;

            $banner = Banner::create([
                'id' => (string) Str::uuid(),
                'title' => $request->input('title'),
                'alt_text' => $request->input('alt_text'),
                'link_url' => $request->input('link_url'),
                'image_path' => $storedPath,
                'image_url' => $this->buildImageUrl($storedPath),
                'order' => $nextOrder,
                'is_active' => $request->has('is_active') ? (bool) $request->boolean('is_active') : true,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Banner berhasil ditambahkan.',
                'data' => (new BannerResource($banner))->resolve(),
            ], 201);
        } catch (\Throwable $error) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan banner.',
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    public function update(BannerRequest $request, string $id): JsonResponse
    {
        $banner = Banner::withTrashed()->findOrFail($id);

        DB::beginTransaction();

        try {
            $payload = [
                'title' => $request->input('title', $banner->title),
                'alt_text' => $request->input('alt_text', $banner->alt_text),
                'link_url' => $request->input('link_url', $banner->link_url),
            ];

            if ($request->has('is_active')) {
                $payload['is_active'] = (bool) $request->boolean('is_active');
            }

            if ($request->has('order')) {
                $payload['order'] = max(0, (int) $request->input('order'));
            }

            if ($request->hasFile('image')) {
                $newPath = $this->storeImage($request->file('image'));
                $this->deleteImageIfExists($banner->image_path);
                $payload['image_path'] = $newPath;
                $payload['image_url'] = $this->buildImageUrl($newPath);
            }

            $banner->update($payload);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Banner berhasil diperbarui.',
                'data' => (new BannerResource($banner->fresh()))->resolve(),
            ]);
        } catch (\Throwable $error) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui banner.',
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'banners' => ['required', 'array', 'min:1'],
            'banners.*.id' => ['required', 'string', 'exists:banners,id'],
            'banners.*.order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated): void {
            $rows = collect($validated['banners'])
                ->sortBy('order')
                ->values();

            $rows->each(function (array $row, int $index): void {
                Banner::query()
                    ->whereKey($row['id'])
                    ->update(['order' => $index + 1]);
            });
        });

        return response()->json([
            'success' => true,
            'message' => 'Urutan banner diperbarui.',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $banner = Banner::query()->findOrFail($id);
        $banner->delete();

        return response()->json([
            'success' => true,
            'message' => 'Banner dihapus (soft delete).',
        ]);
    }

    public function restore(string $id): JsonResponse
    {
        $banner = Banner::withTrashed()->findOrFail($id);

        if (! $banner->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Banner tidak dalam status terhapus.',
            ], 422);
        }

        $banner->restore();

        return response()->json([
            'success' => true,
            'message' => 'Banner berhasil dipulihkan.',
            'data' => (new BannerResource($banner->fresh()))->resolve(),
        ]);
    }

    public function forceDelete(string $id): JsonResponse
    {
        $banner = Banner::withTrashed()->findOrFail($id);
        $this->deleteImageIfExists($banner->image_path);
        $banner->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Banner dihapus permanen.',
        ]);
    }

    public function getActiveBanners(): JsonResponse
    {
        $banners = Banner::query()->active()->get();

        return response()->json([
            'success' => true,
            'data' => BannerResource::collection($banners)->resolve(),
            'count' => $banners->count(),
        ]);
    }

    private function storeImage($file): string
    {
        $filename = 'banner_' . now()->format('YmdHis') . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
        return $file->storeAs('banners', $filename, 'public');
    }

    private function buildImageUrl(string $path): string
    {
        return url('/storage/' . ltrim($path, '/'));
    }

    private function deleteImageIfExists(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
