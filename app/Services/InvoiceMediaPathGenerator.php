<?php

namespace App\Services;

use App\Models\Invoice;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class InvoiceMediaPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        if ($media->model_type === Invoice::class) {
            /** @var Invoice $invoice */
            $invoice = $media->model;
            return "invoice/{$invoice->public_id}/{$media->id}/";
        }

        return "media/{$media->id}/";
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media) . 'conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media) . 'responsive-images/';
    }
}
