<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Resources\UserProfileResource;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $user->refresh();

        return $this->success(new UserProfileResource($user), 'Profil user berhasil diambil.');
    }

    public function update(UpdateUserProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->error('Unauthenticated.', 401);
        }

        $payload = $request->safe()->except(['avatar']);
        $previousAvatarPath = $user->avatar_path;
        $newAvatarPath = null;

        DB::transaction(function () use ($request, $user, $payload, &$newAvatarPath): void {
            if ($request->hasFile('avatar')) {
                $newAvatarPath = $this->storeAvatar($request->file('avatar'));
                $payload['avatar_path'] = $newAvatarPath;
            }

            $user->fill($payload);
            $user->save();
        });

        if (is_string($newAvatarPath) && $newAvatarPath !== '' && is_string($previousAvatarPath) && $previousAvatarPath !== '') {
            $this->deleteAvatar($previousAvatarPath);
        }

        $user->refresh();

        return $this->success(new UserProfileResource($user), 'Profil user berhasil diperbarui.');
    }

    public function avatar(User $user): Response
    {
        $avatarPath = is_string($user->avatar_path) ? trim($user->avatar_path) : '';
        if ($avatarPath === '' || ! Storage::disk('public')->exists($avatarPath)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($avatarPath), [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function storeAvatar(?UploadedFile $file): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg'));
        $filename = Str::uuid()->toString() . '.' . $extension;

        return $file->storeAs('avatars', $filename, 'public');
    }

    private function deleteAvatar(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
