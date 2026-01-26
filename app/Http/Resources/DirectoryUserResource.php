<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DirectoryUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'user',
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            // Helper for frontend avatar
            'initials' => collect(explode(' ', $this->name))
                ->map(fn ($segment) => $segment[0] ?? '')
                ->take(2)
                ->implode(''),
        ];
    }
}
