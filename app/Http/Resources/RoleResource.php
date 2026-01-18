<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Spatie\Permission\Models\Role
 */
class RoleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roleConfig = config('roles.roles', []);
        $config = $roleConfig[$this->name] ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $config['label'] ?? ucfirst(str_replace('_', ' ', $this->name)),
            'description' => $config['description'] ?? null,
            'level' => $config['level'] ?? 0,
            'color' => $config['color'] ?? 'gray',
            'guard_name' => $this->guard_name,
            'users_count' => $this->when(isset($this->users_count), $this->users_count),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')
            ),
            'permissions_count' => $this->whenLoaded('permissions', fn () => $this->permissions->count()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
