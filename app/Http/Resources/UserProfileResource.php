<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class UserProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $avatarUrl = null;
        if (is_string($this->avatar_path) && trim($this->avatar_path) !== '') {
            $avatarUrl = route('user.avatar.show', [
                'user' => (string) $this->id,
                'v' => $this->updated_at?->timestamp,
            ], false);
        }

        return [
            'id' => (string) $this->id,
            'name' => (string) $this->name,
            'email' => (string) $this->email,
            'phone' => $this->phone ? (string) $this->phone : null,
            'gender' => $this->gender ? (string) $this->gender : null,
            'address' => $this->address ? (string) $this->address : null,
            'country' => $this->country ? (string) $this->country : null,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'avatar' => $avatarUrl,
            'avatar_path' => $this->avatar_path ? (string) $this->avatar_path : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
