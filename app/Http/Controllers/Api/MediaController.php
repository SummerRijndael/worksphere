<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaController extends Controller
{
    /**
     * Download a private media file.
     */
    public function __construct(
        protected \App\Services\PermissionService $permissionService
    ) {}

    /**
     * Download a private media file.
     */
    public function download(Media $media)
    {
        $this->authorizeMediaAccess($media);

        return $media;
    }

    public function secureDownload(Media $media)
    {
        // This method is protected by 'signed' middleware in routes/api.php
        // but we double check valid signature in authorizeMediaAccess anyway
        // or we can skip authorizeMediaAccess if middleware 'signed' is trusted.
        // However, authorizeMediaAccess has logic: if(request()->hasValidSignature()) return;
        // So safe to call it.

        $this->authorizeMediaAccess($media);

        // Force download with correct filename and headers
        $disk = $media->disk;
        $path = $media->getPath();

        // Ensure we are getting the absolute path for the download response
        if (config("filesystems.disks.$disk.driver") === 'local') {
            $fullPath = \Illuminate\Support\Facades\Storage::disk($disk)->path($path);
        } else {
            // Fallback/Support for cloud if ever changed, though download() handles urls poorly usually need redirect or stream
            $fullPath = $media->getPath();
        }

        return response()->download($fullPath, $media->file_name, [
            'Content-Type' => $media->mime_type,
        ]);
    }

    /**
     * Display a media file inline (e.g. for <img> tags).
     */
    public function show(Media $media, ?string $conversion = null)
    {
        \Illuminate\Support\Facades\Log::info('MediaController@show hit', [
            'media_id' => $media->id,
            'user_id' => auth()->id(),
            'conversion' => $conversion,
        ]);

        try {
            $this->authorizeMediaAccess($media);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Media Access Denied', ['message' => $e->getMessage()]);
            abort(403, $e->getMessage());
        }

        // If a conversion is requested (e.g., 'thumb'), return that path
        if ($conversion && $media->hasGeneratedConversion($conversion)) {
            $path = $media->getPath($conversion);
        } else {
            $path = $media->getPath();
        }

        if (! file_exists($path)) {
            \Illuminate\Support\Facades\Log::error('File not found at path', ['path' => $path]);
            abort(404);
        }

        return response()->file($path);
    }

    /**
     * Check if the authenticated user can access this media.
     *
     * If the request is a valid Signed Route, we allow access without further checks,
     * assuming the signature generation was authorized.
     */
    protected function authorizeMediaAccess(Media $media): void
    {
        // 0. Check for Valid Signed Route
        if (request()->hasValidSignature()) {
            return;
        }

        // 1. FAQ Articles (Check FIRST to allow public access)
        if ($media->model_type === 'App\Models\FaqArticle') {
            $article = \App\Models\FaqArticle::find($media->model_id);
            if ($article) {
                // If published, ANYONE (even guests) can view
                if ($article->is_published) {
                    return;
                }
            }
        }

        // --- AUTH REQUIRED FOR EVERYTHING ELSE ---
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        // 2. User Personal Files
        if ($media->model_type === 'App\Models\User' && $media->model_id === $user->id) {
            return;
        }

        // 3. Project Files
        if ($media->model_type === 'App\Models\Project') {
            $project = \App\Models\Project::find($media->model_id);
            if ($project && $this->permissionService->hasTeamPermission($user, $project->team, 'projects.view')) {
                return;
            }
        }

        // 4. Ticket Attachments
        if ($media->model_type === 'App\Models\Ticket') {
            $ticket = \App\Models\Ticket::find($media->model_id);
            if ($ticket) {
                // Access if: Creator OR Assigned OR has Team Permission
                if ($ticket->created_by === $user->id || $ticket->assigned_to === $user->id) {
                    return;
                }

                // If ticket belongs to a team, check permission
                // Assuming Ticket has a 'team_id' or belongs to a project which belongs to a team
                // Let's check direct team relationship first or via project
                $team = null;
                if ($ticket->project) {
                    $team = $ticket->project->team;
                } elseif ($ticket->team_id) { // Fallback if direct team link exists
                    $team = \App\Models\Team::find($ticket->team_id);
                }

                if ($team && $this->permissionService->hasTeamPermission($user, $team, 'tickets.view')) {
                    return;
                }
            }
        }

        // 5. Team Files (Directly attached to Team)
        if ($media->model_type === 'App\Models\Team') {
            $team = \App\Models\Team::find($media->model_id);
            if ($team && $team->hasMember($user)) {
                return;
            }
        }

        // 6. Draft FAQ Articles (Auth required)
        if ($media->model_type === 'App\Models\FaqArticle') {
            // We already loaded article above, can reuse if we refactor, but for clarity:
            $article = \App\Models\FaqArticle::find($media->model_id);
            if ($article && ! $article->is_published) {
                if ($this->permissionService->hasPermission($user, 'faq.manage')) {
                    return;
                }
            }
        }

        abort(403, 'Unauthorized access to this file.');
    }
}
