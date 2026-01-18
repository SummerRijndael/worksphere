<?php

namespace App\Services;

use App\Contracts\InvoiceTemplateServiceContract;
use App\Models\InvoiceTemplate;
use Illuminate\Database\Eloquent\Collection;

class InvoiceTemplateService implements InvoiceTemplateServiceContract
{
    public function getAllForTeam(string $teamPublicId): Collection
    {
        return InvoiceTemplate::whereHas('team', function ($query) use ($teamPublicId) {
            $query->where('public_id', $teamPublicId);
        })->get();
    }

    public function find(string $id): ?InvoiceTemplate
    {
        return InvoiceTemplate::where('id', '=', $id)->orWhere('public_id', '=', $id)->first();
    }

    public function create(array $data): InvoiceTemplate
    {
        return InvoiceTemplate::create($data);
    }

    public function update(string $id, array $data): InvoiceTemplate
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
