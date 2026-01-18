<?php

namespace App\Contracts;

use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Collection;

interface TaskTemplateServiceContract
{
    public function getAllForTeam(string $teamId): Collection;

    public function find(string $id): ?TaskTemplate;

    public function create(array $data): TaskTemplate;

    public function update(string $id, array $data): TaskTemplate;

    public function delete(string $id): bool;
}
