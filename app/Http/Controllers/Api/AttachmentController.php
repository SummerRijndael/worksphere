<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class AttachmentController extends Controller
{
    /**
     * Download a single attachment.
     */
    public function download(int $mediaId): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $media = Media::findOrFail($mediaId);

        // TODO: Add authorization check (verify user owns the email)

        return response()->download($media->getPath(), $media->file_name);
    }

    /**
     * Download multiple attachments as a ZIP stream.
     */
    public function downloadBatch(Request $request): StreamedResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:media,id'],
        ]);

        $mediaItems = Media::whereIn('id', $request->input('ids'))->get();

        if ($mediaItems->isEmpty()) {
            abort(404, 'No attachments found');
        }

        // TODO: Add authorization check

        $zipFileName = 'attachments_'.now()->format('Y-m-d_His').'.zip';

        return response()->streamDownload(function () use ($mediaItems) {
            $tempFile = tempnam(sys_get_temp_dir(), 'zip');
            $zip = new ZipArchive;

            if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create ZIP file');
            }

            foreach ($mediaItems as $media) {
                $zip->addFile($media->getPath(), $media->file_name);
            }

            $zip->close();

            readfile($tempFile);
            unlink($tempFile);
        }, $zipFileName, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
