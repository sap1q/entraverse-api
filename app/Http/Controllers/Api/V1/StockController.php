<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $inventory = $this->stockService->listInventory($request->query());

        return response()->json([
            'success' => true,
            'message' => 'Inventory berhasil diambil.',
            'data' => $inventory['rows'],
            'stats' => $inventory['stats'],
            'filters' => [
                'warehouses' => $inventory['warehouses'],
            ],
            'pagination' => $inventory['pagination'],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function mutations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'variant_sku' => ['required', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 422);
        }

        $validated = $validator->validated();
        $mutations = $this->stockService->getMutations(
            (string) $validated['product_id'],
            (string) $validated['variant_sku'],
            (int) ($validated['limit'] ?? 5),
        );

        return response()->json([
            'success' => true,
            'message' => 'Riwayat mutasi berhasil diambil.',
            'data' => $mutations->map(function ($mutation): array {
                return [
                    'id' => (string) $mutation->id,
                    'product_id' => (string) $mutation->product_id,
                    'variant_sku' => (string) $mutation->variant_sku,
                    'type' => (string) $mutation->type,
                    'quantity' => (int) $mutation->quantity,
                    'reference' => $mutation->reference,
                    'note' => $mutation->note,
                    'user' => $mutation->admin ? [
                        'id' => (string) $mutation->admin->id,
                        'name' => (string) $mutation->admin->name,
                        'email' => (string) $mutation->admin->email,
                    ] : null,
                    'created_at' => optional($mutation->created_at)?->toISOString(),
                ];
            })->values()->all(),
            'meta' => [
                'timestamp' => now()->toISOString(),
                'version' => 'v1',
            ],
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'variant_sku' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['in', 'out', 'adjustment'])],
            'quantity' => ['required', 'integer', 'min:1'],
            'warehouse' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', Rule::in(['restock', 'damage', 'stock_opname', 'sale'])],
            'direction' => ['nullable', Rule::in(['increment', 'decrement'])],
            'allow_negative' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $validator->errors(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 422);
        }

        try {
            /** @var Admin $admin */
            $admin = $request->user();
            $result = $this->stockService->adjust($admin, $validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Stok berhasil disesuaikan.',
                'data' => [
                    'row' => $result['row'],
                    'mutation' => [
                        'id' => (string) $result['mutation']->id,
                        'product_id' => (string) $result['mutation']->product_id,
                        'variant_sku' => (string) $result['mutation']->variant_sku,
                        'type' => (string) $result['mutation']->type,
                        'quantity' => (int) $result['mutation']->quantity,
                        'reference' => $result['mutation']->reference,
                        'note' => $result['mutation']->note,
                        'created_at' => optional($result['mutation']->created_at)?->toISOString(),
                    ],
                ],
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi stok gagal.',
                'errors' => $exception->errors(),
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'version' => 'v1',
                ],
            ], 422);
        }
    }
}
