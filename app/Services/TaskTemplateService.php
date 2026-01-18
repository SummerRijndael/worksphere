<?php

namespace App\Services;

use App\Contracts\TaskTemplateServiceContract;
use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Collection;

class TaskTemplateService implements TaskTemplateServiceContract
{
    public function getAllForTeam(string $teamPublicId): Collection
    {
        return TaskTemplate::whereHas('team', function ($query) use ($teamPublicId) {
            $query->where('public_id', $teamPublicId);
        })->get();
    }

    public function find(string $id): ?TaskTemplate
    {
        return TaskTemplate::where('id', '=', $id)->orWhere('public_id', '=', $id)->first();
    }

    public function create(array $data): TaskTemplate
    {
        return TaskTemplate::create($data);
    }

    public function update(string $id, array $data): TaskTemplate
    {
        $template = $this->find($id);
        $template->update($data);

        return $template;
    }

    public function delete(string $id): bool
    {
        $template = $this->find($id);

        return $template ? $template->delete() : false;
    }
}
