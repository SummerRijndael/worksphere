<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPublicRequest = $request->routeIs('api.public.profile') || str_contains($request->path(), 'public/profile');
        $isOwner = $request->user()?->is($this->resource);

        // Basic public data
        $data = [
            'id' => $this->public_id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'initials' => $this->initials,
            'avatar' => $this->avatar,
            'avatar_url' => $this->avatar_url,
            'avatar_thumb_url' => $this->avatar_thumb_url,
            'cover_photo_url' => $this->cover_photo_url,
            'cover_photo_offset' => $this->preferences['appearance']['cover_offset'] ?? 50,
            'title' => $this->title,
            'bio' => $this->bio,
            'location' => $this->location,
            'website' => $this->website,
            'skills' => $this->skills ?? [],
            'is_public' => $this->is_public,
            'created_at' => $this->created_at?->toISOString(), // Joined date
            'presence' => $this->last_login_at && $this->last_login_at->diffInMinutes(now()) < 5 ? 'online' : 'offline',
        ];

        // Sensitive/Private data - Only for authenticated views or owner
        if (! $isPublicRequest || $isOwner) {
            $data = array_merge($data, [
                'email' => $this->email,
                'status' => $this->status,
                'preferences' => $this->preferences ?? [],
                'email_verified' => $this->hasVerifiedEmail(),
                'email_verified_at' => $this->email_verified_at?->toISOString(),
                'roles' => $this->roles->map(fn ($role) => [
                    'name' => $role->name,
                    'label' => config("roles.roles.{$role->name}.label") ?? ucfirst(str_replace('_', ' ', $role->name)),
                ]),
                'permissions' => $this->when(
                    $request->routeIs('api.user') || $request->routeIs('users.show') || $isOwner,
                    fn () => $this->getAllPermissions()->pluck('name')
                ),
                'last_login_at' => $this->last_login_at?->toISOString(),
                'is_password_set' => $this->is_password_set,
                'password_last_updated_at' => $this->password_last_updated_at?->toISOString(),
                'two_factor_enforced' => $this->two_factor_enforced,
                'has_2fa_enabled' => $this->has2FAConfigured(),
                'requires_2fa_setup' => (bool) $this->requires2FASetup(), // Checks BOTH user and role-level enforcement
                'two_factor_confirmed_at' => $this->two_factor_confirmed_at?->toISOString(),
                'two_factor_allowed_methods' => $this->two_factor_allowed_methods,
                'social_accounts' => $this->provider ? [
                    [
                        'provider' => $this->provider,
                        'connected_at' => $this->created_at?->toISOString(),
                    ],
                ] : [],
                'teams' => $this->teams->map(function ($team) {
                    return [
                        'id' => $team->public_id,
                        'public_id' => $team->public_id,
                        'name' => $team->name,
                        'slug' => $team->slug,
                        'owner_id' => $team->owner_id,
                    ];
                }),
                'files' => $this->whenLoaded('media', function () {
                    return $this->getMedia('documents')->map(function ($media) {
                        return [
                            'id' => $media->id,
                            'name' => $media->name,
                            'file_name' => $media->file_name,
                            'mime_type' => $media->mime_type,
                            'size' => $media->size,
                            'download_url' => route('api.user.media.download', ['media' => $media->id]),
                            'created_at' => $media->created_at->diffForHumans(),
                        ];
                    });
                }),
            ]);
        }

        return $data;
    }
}
