<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetMainUserAddressRequest;
use App\Http\Requests\StoreUserAddressRequest;
use App\Http\Requests\UpdateUserAddressRequest;
use App\Http\Resources\UserAddressResource;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\StorefrontUserResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserAddressController extends Controller
{
    private const MAX_ADDRESSES = 5;

    public function __construct(private readonly StorefrontUserResolver $storefrontUserResolver)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveAddressOwner($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $addresses = UserAddress::query()
            ->where('user_id', $user->id)
            ->with(['province', 'city', 'district'])
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => UserAddressResource::collection($addresses)->resolve(),
            'count' => $addresses->count(),
        ]);
    }

    public function store(StoreUserAddressRequest $request): JsonResponse
    {
        $user = $this->resolveAddressOwner($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $activeAddressCount = UserAddress::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->count();

        if ($activeAddressCount >= self::MAX_ADDRESSES) {
            return response()->json([
                'success' => false,
                'message' => 'Maksimal 5 alamat tersimpan.',
            ], 422);
        }

        $payload = $request->validated();
        $payload['user_id'] = $user->id;
        $payload['is_active'] = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
        $payload['is_default'] = (bool) ($payload['is_default'] ?? ($activeAddressCount === 0));

        $address = DB::transaction(function () use ($payload, $user): UserAddress {
            if ((bool) $payload['is_default']) {
                UserAddress::query()
                    ->where('user_id', $user->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return UserAddress::query()->create($payload);
        });

        $address->load(['province', 'city', 'district']);

        return response()->json([
            'success' => true,
            'message' => 'Alamat berhasil ditambahkan.',
            'data' => (new UserAddressResource($address))->resolve(),
        ], 201);
    }

    public function update(UpdateUserAddressRequest $request, string $addressId): JsonResponse
    {
        $user = $this->resolveAddressOwner($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $address = UserAddress::query()
            ->where('user_id', $user->id)
            ->whereKey($addressId)
            ->first();

        if (! $address) {
            return response()->json([
                'success' => false,
                'message' => 'Alamat tidak ditemukan.',
            ], 404);
        }

        $payload = $request->validated();
        $shouldBeDefault = array_key_exists('is_default', $payload)
            ? (bool) $payload['is_default']
            : (bool) $address->is_default;

        $updated = DB::transaction(function () use ($address, $payload, $shouldBeDefault, $user): UserAddress {
            if ($shouldBeDefault) {
                UserAddress::query()
                    ->where('user_id', $user->id)
                    ->where('is_default', true)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->fill($payload);
            $address->is_default = $shouldBeDefault;
            $address->save();

            return $address->fresh();
        });

        $updated?->load(['province', 'city', 'district']);

        return response()->json([
            'success' => true,
            'message' => 'Alamat berhasil diperbarui.',
            'data' => (new UserAddressResource($updated))->resolve(),
        ]);
    }

    public function destroy(Request $request, string $addressId): JsonResponse
    {
        $user = $this->resolveAddressOwner($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $address = UserAddress::query()
            ->where('user_id', $user->id)
            ->whereKey($addressId)
            ->first();

        if (! $address) {
            return response()->json([
                'success' => false,
                'message' => 'Alamat tidak ditemukan.',
            ], 404);
        }

        $wasDefault = (bool) $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $nextDefault = UserAddress::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->orderByDesc('updated_at')
                ->first();

            if ($nextDefault) {
                UserAddress::query()
                    ->where('user_id', $user->id)
                    ->update(['is_default' => false]);

                $nextDefault->update(['is_default' => true]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Alamat berhasil dihapus.',
        ]);
    }

    public function setMain(SetMainUserAddressRequest $request, ?string $addressId = null): JsonResponse
    {
        $user = $this->resolveAddressOwner($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $resolvedAddressId = trim($addressId ?? (string) $request->input('address_id'));
        if ($resolvedAddressId === '') {
            return response()->json([
                'success' => false,
                'message' => 'address_id wajib diisi.',
            ], 422);
        }

        $address = UserAddress::query()
            ->where('user_id', $user->id)
            ->whereKey($resolvedAddressId)
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
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $address->update(['is_default' => true]);
        });

        $address->load(['province', 'city', 'district']);

        return response()->json([
            'success' => true,
            'message' => 'Alamat utama berhasil diperbarui.',
            'data' => (new UserAddressResource($address))->resolve(),
        ]);
    }

    private function resolveAddressOwner(Request $request): User|JsonResponse
    {
        $user = $this->storefrontUserResolver->resolve($request->user());
        if ($user instanceof User) {
            return $user;
        }

        if (! $request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized.',
        ], 401);
    }
}
