<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'public_id' => $this->public_id,

            // Actor information
            'user' => $this->when($this->user, fn () => [
                'id' => $this->user->public_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'avatar_url' => $this->user->avatar_url,
            ]),
            'user_name' => $this->user_name,
            'user_email' => $this->user_email,

            // Team context
            'team' => $this->when($this->team, fn () => [
                'id' => $this->team->public_id,
                'name' => $this->team->name,
            ]),

            // Audit details
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'action_icon' => $this->action->icon(),
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'category_color' => $this->category->color(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'severity_color' => $this->severity->color(),

            // Description
            'description' => $this->description,

            // Auditable entity
            'entity' => $this->when($this->auditable_type, fn () => [
                'type' => class_basename($this->auditable_type),
                'id' => $this->auditable_id,
                'model' => $this->when($this->auditable, fn () => $this->formatAuditable()),
            ]),

            // Changes
            'changes' => $this->when(
                $this->old_values || $this->new_values,
                fn () => [
                    'old' => $this->old_values,
                    'new' => $this->new_values,
                ]
            ),

            // Metadata
            'metadata' => $this->metadata,

            // Request context
            'context' => [
                'ip_address' => $this->ip_address,
                'user_agent' => $this->when(
                    $request->query('include_user_agent'),
                    $this->user_agent
                ),
                'device' => $this->getDeviceContext(),
                'location' => $this->getLocationContext(),
                'url' => $this->url,
                'method' => $this->method,
            ],

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'time_ago' => $this->time_ago,
            'formatted_date' => $this->formatted_date,
        ];
    }

    /**
     * Format the auditable model for display.
     *
     * @return array<string, mixed>|null
     */
    protected function formatAuditable(): ?array
    {
        if (! $this->auditable) {
            return null;
        }

        $auditable = $this->auditable;

        // Return common fields if available
        $data = [];

        if (method_exists($auditable, 'getRouteKeyName')) {
            $routeKey = $auditable->getRouteKeyName();
            $data['id'] = $auditable->{$routeKey} ?? $auditable->getKey();
        } else {
            $data['id'] = $auditable->getKey();
        }

        if (isset($auditable->name)) {
            $data['name'] = $auditable->name;
        }

        if (isset($auditable->email)) {
            $data['email'] = $auditable->email;
        }

        if (isset($auditable->title)) {
            $data['title'] = $auditable->title;
        }

        return $data;
    }

    protected function getDeviceContext(): array
    {
        if (! $this->user_agent) {
            return [];
        }

        $agent = new \Jenssegers\Agent\Agent;
        $agent->setUserAgent($this->user_agent);

        return [
            'browser' => $agent->browser(),
            'browser_version' => $agent->version($agent->browser()),
            'platform' => $agent->platform(),
            'device' => $agent->device(),
            'is_desktop' => $agent->isDesktop(),
            'is_phone' => $agent->isPhone(),
            'is_robot' => $agent->isRobot(),
        ];
    }

    protected function getLocationContext(): ?array
    {
        if (! $this->ip_address) {
            return null;
        }

        try {
            $location = geoip($this->ip_address);

            return [
                'city' => $location->city,
                'state' => $location->state_name,
                'country' => $location->country,
                'iso_code' => $location->iso_code,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
