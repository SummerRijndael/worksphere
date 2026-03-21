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

    protected function canAccessInternalFaqMedia(\App\Models\User $user): bool
    {
        try {
            if ($user->hasPermissionTo('faq.manage') || $user->hasPermissionTo('support.chats.view') || $user->hasPermissionTo('support.chats.reply')) {
                return true;
            }
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            // Ignore and fallback to role checks below.
        }

        return $user->hasRole('administrator')
            || $user->hasAnyRole((array) config('support_chat.agent_roles', ['administrator', 'it_support', 'support']));
    }

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
        $this->authorizeMediaAccess($media);

        // Use Spatie's built-in toResponse which handles path resolution correctly
        // This avoids manual path building issues with getPath()
        $response = $media->toResponse(request());

        // Set Content-Disposition header for download instead of inline view
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$media->file_name.'"');

        return $response;
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

        $disk = $media->disk;
        $driver = config("filesystems.disks.{$disk}.driver");

        // Local Driver: getPath() returns absolute path
        if ($driver === 'local') {
            if (! file_exists($path)) {
                \Illuminate\Support\Facades\Log::error('File not found at path (local)', ['path' => $path]);
                abort(404);
            }

            $cacheControl = $media->model_type === 'App\Models\User' ? 'public, max-age=86400' : 'private, max-age=3600';

            return response()->file($path, [
                'Cache-Control' => $cacheControl,
            ]);
        }

        // Remote Driver (S3, etc.): getPath() returns relative key
        if (! \Illuminate\Support\Facades\Storage::disk($disk)->exists($path)) {
            \Illuminate\Support\Facades\Log::error('File not found at path (remote)', ['path' => $path, 'disk' => $disk]);
            abort(404);
        }

        $cacheControl = $media->model_type === 'App\Models\User' ? 'public, max-age=86400' : 'private, max-age=3600';

        return \Illuminate\Support\Facades\Storage::disk($disk)->response($path, null, [
            'Cache-Control' => $cacheControl,
        ]);
    }

    /**
     * Check if the authenticated user can access this media.
     *
     * If the request is a valid Signed Route, we allow access without further checks,
     * assuming the signature generation was authorized.
     */
    protected function authorizeMediaAccess(Media $media): void
    {
        $hasValidSignature = request()->hasValidSignature();

        // 0. Check for Valid Signed Route
        if ($hasValidSignature && $media->model_type !== 'App\Models\FaqArticle') {
            return;
        }

        // 1. FAQ Articles (Check FIRST to allow public access)
        if ($media->model_type === 'App\Models\FaqArticle') {
            $article = \App\Models\FaqArticle::find($media->model_id);
            if ($article) {
                $isPubliclyAccessible = $article->is_published
                    && (bool) optional($article->category)->is_public
                    && ! $article->is_internal;

                // Published, non-internal FAQ assets are publicly accessible.
                if ($isPubliclyAccessible) {
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

        // 6. Email Signatures & Templates (User Private)
        if ($media->model_type === 'App\Models\EmailSignature' || $media->model_type === 'App\Models\EmailTemplate') {
            $model = $media->model_type::find($media->model_id);
            if ($model && $model->user_id === $user->id) {
                return;
            }
        }

        // 7. Draft FAQ Articles (Auth required)
        // 7. Draft FAQ Articles (Auth required)
        if ($media->model_type === 'App\Models\FaqArticle') {
            $article = \App\Models\FaqArticle::find($media->model_id);
            if ($article) {
                if ($article->is_internal && $article->is_published && optional($article->category)->is_public && $this->canAccessInternalFaqMedia($user)) {
                    return;
                }

                if (! $article->is_published && $this->permissionService->hasPermission($user, 'faq.manage')) {
                    return;
                }
            }
        }

        // 8. Email Media (User Private)
        if ($media->model_type === 'App\Models\Email') {
            $model = \App\Models\Email::find($media->model_id);
            if ($model && $model->user_id === $user->id) {
                return;
            }
        }

        // 9. Invoice Assets (Proofs, Receipts)
        if ($media->model_type === 'App\Models\Invoice') {
            $invoice = \App\Models\Invoice::find($media->model_id);
            if ($invoice) {
                // Clients can view their own invoice assets
                if ($user->hasRole('client')) {
                    $client = $user->linkedClient;
                    if ($client && $invoice->client_id === $client->id) {
                        return;
                    }
                }

                // Team members with view permission
                if ($this->permissionService->hasTeamPermission($user, $invoice->team, 'invoices.view') ||
                    $this->permissionService->hasTeamPermission($user, $invoice->team, 'invoices.manage')) {
                    return;
                }
            }
        }

        // 10. Meeting Recordings
        if ($media->model_type === 'App\Models\MeetingRecording') {
            $recording = \App\Models\MeetingRecording::with('meeting')->find($media->model_id);
            if ($recording && $recording->meeting && \Illuminate\Support\Facades\Gate::forUser($user)->allows('view', $recording->meeting)) {
                return;
            }
        }

        abort(403, 'Unauthorized access to this file.');
    }
}
