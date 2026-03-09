<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetMainUserAddressRequest;
use App\Http\Resources\UserAddressResource;
use App\Models\UserAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserAddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $addresses = $user->addresses()
            ->orderByDesc('is_main')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => UserAddressResource::collection($addresses)->resolve(),
            'count' => $addresses->count(),
        ]);
    }

    public function setMain(SetMainUserAddressRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $address = UserAddress::query()
            ->where('user_id', $user->id)
            ->whereKey((string) $request->input('address_id'))
            ->first();

        if (! $address) {
            return response()->json([
                'success' => false,
                'message' => 'Alamat tidak ditemukan.',
            ], 404);
        }

        DB::transaction(function () use ($user, $address): void {
            UserAddress::query()
                ->where('user_id', $user->id)
                ->where('is_main', true)
                ->update(['is_main' => false]);

            $address->update(['is_main' => true]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Alamat utama berhasil diperbarui.',
            'data' => (new UserAddressResource($address->fresh()))->resolve(),
        ]);
    }
}

