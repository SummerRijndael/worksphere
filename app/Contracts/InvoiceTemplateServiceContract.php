<?php

namespace App\Contracts;

use App\Models\InvoiceTemplate;
use Illuminate\Database\Eloquent\Collection;

interface InvoiceTemplateServiceContract
{
    public function getAllForTeam(string $teamId): Collection;

    public function find(string $id): ?InvoiceTemplate;

    public function create(array $data): InvoiceTemplate;

    public function update(string $id, array $data): InvoiceTemplate;

    public function delete(string $id): bool;
}
