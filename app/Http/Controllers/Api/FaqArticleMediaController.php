<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FaqArticle;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class FaqArticleMediaController extends Controller
{
    /**
     * List all media for an article.
     */
    /**
     * List all media for an article.
     */
    public function index(Request $request, FaqArticle $article)
    {
        $collection = $request->input('collection', 'images');

        $mediaItems = $article->getMedia($collection)->map(function (Media $media) {
            $url = route('api.media.show', ['media' => $media->id]);

            // For attachments (private), we might want to return a temp URL even for admin preview,
            // or rely on api.media.show enforcing auth.
            // Let's stick to the resource pattern:

            return [
                'id' => $media->id,
                'name' => $media->getCustomProperty('original_filename', $media->name), // Use preserved name
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => $url, // Admin view uses this
                'thumbnail_url' => route('api.media.show', ['media' => $media->id, 'conversion' => 'preview']),
                'created_at' => $media->created_at,
                'extension' => pathinfo($media->file_name, PATHINFO_EXTENSION),
            ];
        });

        return response()->json(['data' => $mediaItems]);
    }

    /**
     * Upload a file to the article.
     */
    public function store(Request $request, FaqArticle $article)
    {
        $collection = $request->input('collection', 'images');

        // 1. Validation: Check Storage Limit (300MB Total)
        $maxStorage = 300 * 1024 * 1024; // 300MB
        $currentUsage = $article->media()->sum('size'); // Sum all media
        $newFileSize = $request->file('file')->getSize();

        if (($currentUsage + $newFileSize) > $maxStorage) {
            return response()->json([
                'message' => 'Storage limit of 300MB per article reached. Please delete some files.',
            ], 422);
        }

        // Validation Rules
        $rules = [
            'file' => $collection === 'attachments'
                ? 'required|file|max:51200|mimes:pdf,xls,xlsx,doc,docx,ppt,pptx,zip,txt,csv,json' // 50MB, documents
                : 'required|file|image|max:10240', // 10MB images
        ];

        $request->validate($rules);

        // 2. Obfuscation & Meta
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $obfuscatedName = md5(time().$originalName).'.'.$extension;

        $media = $article->addMediaFromRequest('file')
            ->usingFileName($obfuscatedName)
            ->withCustomProperties(['original_filename' => $originalName])
            ->toMediaCollection($collection);

        return response()->json([
            'message' => 'File uploaded successfully',
            'data' => [
                'id' => $media->id,
                'name' => $originalName,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => route('api.media.show', ['media' => $media->id]),
                'created_at' => $media->created_at,
                'extension' => $extension,
            ],
        ], 201);
    }

    /**
     * Delete a media file.
     */
    public function destroy(FaqArticle $article, Media $media)
    {
        // Ensure the media belongs to the article
        if ($media->model_id !== $article->id || $media->model_type !== FaqArticle::class) {
            return response()->json(['message' => 'Media not found in this article'], 404);
        }

        $media->delete();

        return response()->json(['message' => 'File deleted successfully']);
    }
}
