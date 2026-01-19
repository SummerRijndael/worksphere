<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PermissionOverrideController;
use App\Http\Controllers\Api\RoleChangeRequestController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TeamRoleController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\TwoFactorEnforcementController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// Public routes (rate limited)
Route::middleware(['throttle:guest'])->group(function () {
    // Authentication
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/auth/config', [AuthController::class, 'config']);
    Route::get('/auth/hint/{public_id}', [AuthController::class, 'userHint']);

    // Social Authentication
    Route::get('/auth/{provider}/redirect', [AuthController::class, 'socialRedirect']);
    Route::get('/auth/{provider}/callback', [AuthController::class, 'socialCallback']);
    Route::post('/auth/social/verify-link', [AuthController::class, 'verifySocialLink'])->name('social.verify-link');

    // Passkey Authentication (passwordless login)
    Route::post('/auth/passkey/login/options', [\App\Http\Controllers\Api\PasskeyController::class, 'loginOptions']);
    Route::post('/auth/passkey/login', [\App\Http\Controllers\Api\PasskeyController::class, 'login']);

    // Email Verification API endpoint (for programmatic verification)
    // Note: The primary verification route is in web.php for browser-based clicks
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('api.verification.verify');

    // Account Setup (Password Creation)
    Route::post('/auth/setup-password/{id}', [\App\Http\Controllers\Auth\SetupPasswordController::class, 'store'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.setup-password');

    // Public Profile
    Route::get('/public/profile/{slug}', [\App\Http\Controllers\Api\PublicProfileController::class, 'show'])->name('api.public.profile');
    // Public FAQ routes moved to high-capacity public group below

    // Contact Form
    Route::post('/contact', [\App\Http\Controllers\Api\ContactController::class, 'store'])
        ->middleware('throttle:sensitive');

    // Support (Public Ticket)
    Route::post('/public/tickets', [\App\Http\Controllers\Api\PublicTicketController::class, 'store'])
        ->middleware('throttle:sensitive');

    // Public Announcements
    Route::get('/public/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'public']);

    // DEV DATA TOOLS (Local/Testing environment only - SECURITY CRITICAL)
    // These routes are completely disabled in production
    if (app()->environment('local', 'testing')) {
        Route::prefix('dev')->middleware(['throttle:10,1'])->group(function () {
            Route::get('/users', [\App\Http\Controllers\Api\DevController::class, 'getUsers']);
            Route::get('/chats', [\App\Http\Controllers\Api\DevController::class, 'getChats']);
            Route::post('/login-as', [\App\Http\Controllers\Api\DevController::class, 'loginAs']);
            Route::post('/broadcast-test', [\App\Http\Controllers\Api\DevController::class, 'broadcastTest']);

            // Chat simulation endpoints
            Route::post('/chat/send-message', [\App\Http\Controllers\Api\DevController::class, 'sendMessage']);
            Route::post('/chat/typing', [\App\Http\Controllers\Api\DevController::class, 'triggerTyping']);
            Route::post('/chat/mark-seen', [\App\Http\Controllers\Api\DevController::class, 'markSeen']);
        });
    }

    // Webhooks
    Route::post('/webhooks/twilio/debugger', [\App\Http\Controllers\Api\TwilioWebhookController::class, 'handleDebugger']);
});

// Public Content Routes (Higher Rate Limit for Browsing)
Route::middleware(['throttle:60,1'])->group(function () {
    Route::get('/public/faq', [\App\Http\Controllers\Api\FaqController::class, 'index']);
    Route::get('/public/faq/search', [\App\Http\Controllers\Api\FaqController::class, 'search']);
    Route::get('/public/faq/{slug}', [\App\Http\Controllers\Api\FaqController::class, 'show']);
    Route::post('/public/faq/{article}/vote', [\App\Http\Controllers\Api\FaqController::class, 'vote'])->middleware('throttle:10,1'); // Strict on actions
    Route::post('/public/faq/{article}/comment', [\App\Http\Controllers\Api\FaqController::class, 'comment'])->middleware('throttle:5,1'); // Strict on actions
    Route::get('/public/faq/{article}/comments', [\App\Http\Controllers\Api\FaqController::class, 'getComments']);
});

// Protected routes (requires authentication)
Route::get('/media/secure/{media}', [\App\Http\Controllers\Api\MediaController::class, 'secureDownload'])
    ->name('api.media.secure-download')
    ->middleware('signed');

Route::middleware(['auth:sanctum', 'throttle:api', '2fa.enforce'])->group(function () {
    // Current User
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/user/details', [UserController::class, 'details']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/user/setup-password', [\App\Http\Controllers\Api\SetPasswordController::class, 'store']);
    Route::put('/user/profile', [UserController::class, 'updateProfile']);
    Route::put('/user/password', [UserController::class, 'updatePassword']);
    Route::put('/user/preferences', [UserController::class, 'updatePreferences']);
    Route::put('/user/profile/visibility', [\App\Http\Controllers\Api\PublicProfileController::class, 'updateStatus']);
    Route::post('/user/avatar', [UserController::class, 'uploadAvatar']);
    Route::post('/user/cover', [UserController::class, 'uploadCover']);
    Route::post('/user/documents', [UserController::class, 'uploadDocument']);
    Route::delete('/user/media/{media}', [UserController::class, 'deleteMedia']);
    Route::get('/user/media/{media}/download', [\App\Http\Controllers\Api\MediaController::class, 'download'])->name('api.user.media.download');
    Route::delete('/user/avatar', [UserController::class, 'deleteAvatar']);

    // Generic Secure Media Delivery
    Route::get('/media/{media}/{conversion?}', [\App\Http\Controllers\Api\MediaController::class, 'show'])
        ->name('api.media.show');

    // Global Task List
    Route::get('/user/tasks', [\App\Http\Controllers\Api\UserTaskController::class, 'index']);

    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\DashboardController::class, 'index']);
        Route::get('/stats', [\App\Http\Controllers\Api\DashboardController::class, 'stats']);
        Route::get('/activity', [\App\Http\Controllers\Api\DashboardController::class, 'activity']);
        Route::get('/charts', [\App\Http\Controllers\Api\DashboardController::class, 'charts']);
    });

    // User self-service session management
    Route::get('/user/sessions', [UserController::class, 'ownSessions']);
    Route::delete('/user/sessions', [UserController::class, 'revokeOwnSessions']);
    Route::delete('/user/sessions/{sessionId}', [UserController::class, 'revokeOwnSession']);

    // User social accounts management
    Route::get('/user/social-accounts', [UserController::class, 'socialAccounts']);
    Route::delete('/user/social-accounts/{provider}', [UserController::class, 'disconnectSocial']);

    // Passkeys (WebAuthn)
    Route::get('/user/passkeys', [\App\Http\Controllers\Api\PasskeyController::class, 'index']);
    Route::post('/user/passkeys/register/options', [\App\Http\Controllers\Api\PasskeyController::class, 'registerOptions']);
    Route::post('/user/passkeys', [\App\Http\Controllers\Api\PasskeyController::class, 'store']);
    Route::put('/user/passkeys/{id}', [\App\Http\Controllers\Api\PasskeyController::class, 'update']);
    Route::delete('/user/passkeys/{id}', [\App\Http\Controllers\Api\PasskeyController::class, 'destroy']);

    // Email Verification - resend notification
    Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])
        ->middleware('throttle:6,1');

    // Notifications
    Route::get('/notifications', [App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
    Route::put('/notifications/mark-all-read', [App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
    Route::put('/notifications/{id}/read', [App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
    Route::delete('/notifications/{id}', [App\Http\Controllers\Api\NotificationController::class, 'destroy']);
    Route::delete('/notifications', [App\Http\Controllers\Api\NotificationController::class, 'destroyAll']);

    // Team Member Profiles (Shared Team Access)
    Route::get('/users/{user}/profile', [\App\Http\Controllers\Api\UserProfileController::class, 'show']);

    // Ticket Bulk Actions

    // Ticket Actions
    Route::prefix('tickets/{ticket}')->group(function () {
        Route::post('/assign', [\App\Http\Controllers\Api\TicketController::class, 'assign']);
        Route::put('/status', [\App\Http\Controllers\Api\TicketController::class, 'updateStatus']);
        Route::post('/link', [\App\Http\Controllers\Api\TicketController::class, 'link']);
        Route::post('/unlink', [\App\Http\Controllers\Api\TicketController::class, 'unlink']);
        Route::post('/archive', [\App\Http\Controllers\Api\TicketController::class, 'archive']);

        // Interaction
        Route::post('/comments', [\App\Http\Controllers\Api\TicketController::class, 'storeComment']);
        Route::post('/notes', [\App\Http\Controllers\Api\TicketController::class, 'storeInternalNote']);
        Route::post('/follow', [\App\Http\Controllers\Api\TicketController::class, 'follow']);
        Route::delete('/follow', [\App\Http\Controllers\Api\TicketController::class, 'unfollow']);
        Route::post('/media', [\App\Http\Controllers\Api\TicketController::class, 'uploadMedia']);
        Route::delete('/media/{media}', [\App\Http\Controllers\Api\TicketController::class, 'deleteMedia']);
    });

    Route::get('tickets/stats', [\App\Http\Controllers\Api\TicketController::class, 'stats']);
    Route::get('tickets/search-linkable', [\App\Http\Controllers\Api\TicketController::class, 'searchLinkable']);
    Route::post('tickets/archive', [\App\Http\Controllers\Api\TicketController::class, 'bulkArchive']); // Add bulk archive route explicitly if not present
    Route::apiResource('tickets', \App\Http\Controllers\Api\TicketController::class);

    // User Management
    Route::apiResource('teams', \App\Http\Controllers\Api\TeamController::class);
    Route::prefix('teams/{team}')->group(function () {
        Route::get('/members', [\App\Http\Controllers\Api\TeamController::class, 'members']);
        Route::get('/participants', [\App\Http\Controllers\Api\TeamController::class, 'participants']);
        Route::post('/invite', [\App\Http\Controllers\Api\TeamController::class, 'invite']);
        Route::put('/members/{user}/role', [\App\Http\Controllers\Api\TeamController::class, 'updateMemberRole']);
        Route::delete('/members/{user}', [\App\Http\Controllers\Api\TeamController::class, 'removeMember']);
        Route::get('/files', [\App\Http\Controllers\Api\TeamController::class, 'files']);
        Route::post('/files', [\App\Http\Controllers\Api\TeamController::class, 'uploadFile']);
        Route::post('/files/bulk-download', [\App\Http\Controllers\Api\TeamController::class, 'bulkDownload']);
        Route::post('/files/bulk-delete', [\App\Http\Controllers\Api\TeamController::class, 'bulkDelete']);
        Route::delete('/files/{mediaId}', [\App\Http\Controllers\Api\TeamController::class, 'deleteFile']);
        Route::get('/invites', [\App\Http\Controllers\Api\TeamController::class, 'pendingInvites']);
        Route::delete('/invites/{notificationId}', [\App\Http\Controllers\Api\TeamController::class, 'cancelInvite']);

        // Activity / Audit Trail
        Route::get('/activity', [\App\Http\Controllers\Api\TeamController::class, 'activity']);

        // Calendar
        Route::get('/calendar', [\App\Http\Controllers\Api\TeamController::class, 'calendar']);
        Route::get('/events/export', [\App\Http\Controllers\Api\TeamController::class, 'exportEvents']);
        Route::post('/events', [\App\Http\Controllers\Api\TeamController::class, 'storeEvent']);
        Route::post('/events/{event}/invite', [\App\Http\Controllers\Api\TeamController::class, 'inviteEvent']);
        Route::post('/avatar', [\App\Http\Controllers\Api\TeamController::class, 'uploadAvatar']);
        Route::delete('/avatar', [\App\Http\Controllers\Api\TeamController::class, 'deleteAvatar']);
        Route::get('/events/{event}/ics', [\App\Http\Controllers\Api\TeamController::class, 'downloadEventIcs']);
        Route::put('/events/{event}', [\App\Http\Controllers\Api\TeamController::class, 'updateEvent']);
        Route::delete('/events/{event}', [\App\Http\Controllers\Api\TeamController::class, 'destroyEvent']);

        // Team Roles Management
        Route::prefix('roles')->group(function () {
            Route::get('/', [TeamRoleController::class, 'index']);
            Route::post('/', [TeamRoleController::class, 'store']);
            Route::get('/permissions', [TeamRoleController::class, 'availablePermissions']);
            Route::get('/{role}', [TeamRoleController::class, 'show']);
            Route::put('/{role}', [TeamRoleController::class, 'update']);
            Route::delete('/{role}', [TeamRoleController::class, 'destroy']);
            Route::get('/{role}/members', [TeamRoleController::class, 'members']);
            Route::post('/{role}/assign/{member}', [TeamRoleController::class, 'assignToMember']);
        });

        // Projects Management
        Route::prefix('projects')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\ProjectController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\ProjectController::class, 'store']);
            Route::get('/{project}', [\App\Http\Controllers\Api\ProjectController::class, 'show']);
            Route::put('/{project}', [\App\Http\Controllers\Api\ProjectController::class, 'update']);
            Route::delete('/{project}', [\App\Http\Controllers\Api\ProjectController::class, 'destroy']);
            Route::post('/{project}/archive', [\App\Http\Controllers\Api\ProjectController::class, 'archive']);
            Route::post('/{project}/unarchive', [\App\Http\Controllers\Api\ProjectController::class, 'unarchive']);
            Route::get('/{project}/stats', [\App\Http\Controllers\Api\ProjectController::class, 'stats']);
            Route::get('/{project}/calendar', [\App\Http\Controllers\Api\ProjectController::class, 'calendar']);

            // Project Members
            Route::post('/{project}/members/{user}', [\App\Http\Controllers\Api\ProjectController::class, 'addMember']);
            Route::put('/{project}/members/{user}', [\App\Http\Controllers\Api\ProjectController::class, 'updateMemberRole']);
            Route::delete('/{project}/members/{user}', [\App\Http\Controllers\Api\ProjectController::class, 'removeMember']);

            // Project Files
            Route::get('/{project}/files', [\App\Http\Controllers\Api\ProjectController::class, 'files']);
            Route::post('/{project}/files', [\App\Http\Controllers\Api\ProjectController::class, 'uploadFile']);
            Route::delete('/{project}/files/{mediaId}', [\App\Http\Controllers\Api\ProjectController::class, 'deleteFile']);

            // Tasks Management
            Route::prefix('/{project}/tasks')->group(function () {
                // CRUD
                Route::get('/', [\App\Http\Controllers\Api\TaskController::class, 'index']);
                Route::post('/', [\App\Http\Controllers\Api\TaskController::class, 'store']);
                Route::get('/{task}', [\App\Http\Controllers\Api\TaskController::class, 'show']);
                Route::put('/{task}', [\App\Http\Controllers\Api\TaskController::class, 'update']);
                Route::delete('/{task}', [\App\Http\Controllers\Api\TaskController::class, 'destroy']);

                // Task Assignment
                Route::post('/{task}/assign', [\App\Http\Controllers\Api\TaskController::class, 'assign']);

                // Task Workflow
                Route::post('/{task}/start', [\App\Http\Controllers\Api\TaskController::class, 'start']);
                Route::post('/{task}/submit-qa', [\App\Http\Controllers\Api\TaskController::class, 'submitForQa']);
                Route::post('/{task}/start-qa-review', [\App\Http\Controllers\Api\TaskController::class, 'startQaReview']);
                Route::post('/{task}/complete-qa-review', [\App\Http\Controllers\Api\TaskController::class, 'completeQaReview']);
                Route::post('/{task}/send-to-pm', [\App\Http\Controllers\Api\TaskController::class, 'sendToPm']);
                Route::post('/{task}/send-to-client', [\App\Http\Controllers\Api\TaskController::class, 'sendToClient']);
                Route::post('/{task}/client-approve', [\App\Http\Controllers\Api\TaskController::class, 'clientApprove']);
                Route::post('/{task}/client-reject', [\App\Http\Controllers\Api\TaskController::class, 'clientReject']);
                Route::post('/{task}/toggle-hold', [\App\Http\Controllers\Api\TaskController::class, 'toggleHold']);
                Route::post('/{task}/return-to-progress', [\App\Http\Controllers\Api\TaskController::class, 'returnToProgress']);
                Route::post('/{task}/complete', [\App\Http\Controllers\Api\TaskController::class, 'complete']);
                Route::post('/{task}/archive', [\App\Http\Controllers\Api\TaskController::class, 'archive']);

                // Task Comments
                Route::get('/{task}/comments', [\App\Http\Controllers\Api\TaskController::class, 'comments']);
                Route::post('/{task}/comments', [\App\Http\Controllers\Api\TaskController::class, 'addComment']);

                // Task Status History
                Route::get('/{task}/status-history', [\App\Http\Controllers\Api\TaskController::class, 'statusHistory']);

                // Task Files
                Route::get('/{task}/files', [\App\Http\Controllers\Api\TaskController::class, 'getFiles']);
                Route::post('/{task}/files', [\App\Http\Controllers\Api\TaskController::class, 'uploadFile']);
                Route::delete('/{task}/files/{mediaId}', [\App\Http\Controllers\Api\TaskController::class, 'deleteFile']);
                Route::post('/{task}/files/download', [\App\Http\Controllers\Api\TaskController::class, 'downloadFiles']);

                // Task Checklist Items
                Route::get('/{task}/checklist', [\App\Http\Controllers\Api\TaskChecklistItemController::class, 'index']);
                Route::post('/{task}/checklist', [\App\Http\Controllers\Api\TaskChecklistItemController::class, 'store']);
                Route::put('/{task}/checklist/{checklistItem}', [\App\Http\Controllers\Api\TaskChecklistItemController::class, 'update']);
                Route::delete('/{task}/checklist/{checklistItem}', [\App\Http\Controllers\Api\TaskChecklistItemController::class, 'destroy']);
                Route::post('/{task}/checklist/reorder', [\App\Http\Controllers\Api\TaskChecklistItemController::class, 'reorder']);
            });
        });

        // Clients
        Route::get('/clients', [\App\Http\Controllers\Api\ClientController::class, 'index']);

        // Invoices Management
        Route::prefix('invoices')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\InvoiceController::class, 'index']);
            Route::get('/stats', [\App\Http\Controllers\Api\InvoiceController::class, 'stats']);
            Route::post('/', [\App\Http\Controllers\Api\InvoiceController::class, 'store']);
            Route::get('/{invoice}', [\App\Http\Controllers\Api\InvoiceController::class, 'show']);
            Route::put('/{invoice}', [\App\Http\Controllers\Api\InvoiceController::class, 'update']);
            Route::delete('/{invoice}', [\App\Http\Controllers\Api\InvoiceController::class, 'destroy']);
            Route::post('/{invoice}/send', [\App\Http\Controllers\Api\InvoiceController::class, 'send']);
            Route::post('/{invoice}/record-payment', [\App\Http\Controllers\Api\InvoiceController::class, 'recordPayment']);
            Route::post('/{invoice}/cancel', [\App\Http\Controllers\Api\InvoiceController::class, 'cancel']);
            Route::get('/{invoice}/download-pdf', [\App\Http\Controllers\Api\InvoiceController::class, 'downloadPdf']);
            Route::post('/{invoice}/regenerate-pdf', [\App\Http\Controllers\Api\InvoiceController::class, 'regeneratePdf']);
            Route::post('/{invoice}/regenerate-pdf', [\App\Http\Controllers\Api\InvoiceController::class, 'regeneratePdf']);
        });

        // Template Builder
        Route::apiResource('task-templates', \App\Http\Controllers\Api\TaskTemplateController::class);
        Route::apiResource('invoice-templates', \App\Http\Controllers\Api\InvoiceTemplateController::class);
    });

    // Team Invitations
    Route::post('/invitations/{id}/accept', [\App\Http\Controllers\Api\TeamController::class, 'acceptInvitation']);
    Route::post('/invitations/{id}/decline', [\App\Http\Controllers\Api\TeamController::class, 'declineInvitation']);
    Route::apiResource('clients', \App\Http\Controllers\Api\ClientController::class);

    // Reports - Projects
    Route::prefix('reports/projects')->group(function () {
        Route::get('/overview', [\App\Http\Controllers\Api\Reports\ProjectReportController::class, 'overview']);
        Route::get('/list', [\App\Http\Controllers\Api\Reports\ProjectReportController::class, 'index']);
        Route::get('/selector', [\App\Http\Controllers\Api\Reports\ProjectReportController::class, 'selector']);
    });

    // Client Portal Routes
    Route::prefix('client-portal')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Api\ClientPortalController::class, 'dashboard']);
        Route::get('/projects', [\App\Http\Controllers\Api\ClientPortalController::class, 'projects']);
        Route::get('/projects/{project}', [\App\Http\Controllers\Api\ClientPortalController::class, 'projectDetail']);
        Route::get('/invoices', [\App\Http\Controllers\Api\ClientPortalController::class, 'invoices']);
        Route::get('/invoices/{invoice}', [\App\Http\Controllers\Api\ClientPortalController::class, 'invoiceDetail']);
        Route::get('/tickets', [\App\Http\Controllers\Api\ClientPortalController::class, 'tickets']);
        Route::post('/request-update', [\App\Http\Controllers\Api\ClientPortalController::class, 'requestInfoUpdate']);
    });

    // Navigation
    Route::get('navigation', [\App\Http\Controllers\Api\NavigationController::class, 'index']);
    Route::post('navigation/preferences', [\App\Http\Controllers\Api\NavigationController::class, 'updatePreferences']);

    // Global Search
    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->middleware('throttle:60,1');

    // Email Accounts
    Route::prefix('email-accounts')->middleware('throttle:30,1')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\EmailAccountController::class, 'index']);
        Route::get('/providers', [\App\Http\Controllers\Api\EmailAccountController::class, 'providers']);
        Route::post('/', [\App\Http\Controllers\Api\EmailAccountController::class, 'store']);
        Route::get('/{emailAccount}', [\App\Http\Controllers\Api\EmailAccountController::class, 'show']);
        Route::put('/{emailAccount}', [\App\Http\Controllers\Api\EmailAccountController::class, 'update']);
        Route::delete('/{emailAccount}', [\App\Http\Controllers\Api\EmailAccountController::class, 'destroy']);
        Route::post('/test-configuration', [\App\Http\Controllers\Api\EmailAccountController::class, 'testConfiguration']);
        Route::post('/{emailAccount}/test', [\App\Http\Controllers\Api\EmailAccountController::class, 'testConnection']);
        Route::post('/{emailAccount}/sync', [\App\Http\Controllers\Api\EmailAccountController::class, 'sync']);
        // OAuth
        Route::get('/oauth/{provider}/redirect', [\App\Http\Controllers\Api\EmailOAuthController::class, 'redirect']);
        Route::post('/{emailAccount}/oauth/refresh', [\App\Http\Controllers\Api\EmailOAuthController::class, 'refresh']);
    });

    // Email System
    Route::prefix('emails')->middleware('throttle:60,1')->group(function () {
        // Folders
        Route::apiResource('folders', \App\Http\Controllers\Api\EmailFolderController::class);
        Route::apiResource('labels', \App\Http\Controllers\Api\EmailLabelController::class);
        Route::get('folder-counts', [\App\Http\Controllers\Api\EmailController::class, 'folderCounts']);

        // Signatures
        Route::apiResource('signatures', \App\Http\Controllers\Api\EmailSignatureController::class);

        // Templates
        Route::apiResource('templates', \App\Http\Controllers\Api\EmailTemplateController::class);

        // Attachments
        Route::get('attachments/{media}/download', [\App\Http\Controllers\Api\AttachmentController::class, 'download']);
        Route::post('attachments/download-batch', [\App\Http\Controllers\Api\AttachmentController::class, 'downloadBatch']);

        // Emails
        Route::get('/', [\App\Http\Controllers\Api\EmailController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\EmailController::class, 'store']);
        Route::post('/bulk', [\App\Http\Controllers\Api\EmailController::class, 'storeBulk']); // Not implemented in controller yet, adding placeholder or omit
        Route::post('/bulk-delete', [\App\Http\Controllers\Api\EmailController::class, 'bulkDelete']);
        Route::get('/{email}', [\App\Http\Controllers\Api\EmailController::class, 'show']);
        Route::get('/{email}/export', [\App\Http\Controllers\Api\EmailController::class, 'exportEml']);
        Route::match(['put', 'patch'], '/{email}', [\App\Http\Controllers\Api\EmailController::class, 'update']);
        Route::delete('/{email}', [\App\Http\Controllers\Api\EmailController::class, 'destroy']);
    });

    // Users Management (admin routes)
    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users/stats', [UserController::class, 'stats'])->name('users.stats');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    });

    Route::middleware('permission:users.create')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
    });

    Route::middleware('permission:users.update')->group(function () {
        Route::put('/users/{user}', [UserController::class, 'update']);
    });

    Route::middleware('permission:users.delete')->group(function () {
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });

    // Enhanced User Status Management (with audit trail and broadcasting)
    Route::middleware('permission:users.manage_status')->group(function () {
        Route::put('/users/{user}/status', [UserStatusController::class, 'updateStatus']);
        Route::get('/users/{user}/status-history', [UserStatusController::class, 'statusHistory']);
    });

    // Enhanced Role Management (with password confirmation and audit trail)
    Route::middleware('permission:users.manage_roles')->group(function () {
        Route::put('/users/{user}/role', [UserStatusController::class, 'updateRole']);
        Route::get('/users/{user}/role-history', [UserStatusController::class, 'roleHistory']);
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles']); // Legacy
    });

    Route::middleware('permission:users.update')->group(function () {
        Route::post('/users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])
            ->middleware('throttle:5,60'); // 5 requests per hour
        Route::post('/users/{user}/email-verification', [UserController::class, 'resendVerification'])
            ->middleware('throttle:5,60'); // 5 requests per hour
        Route::delete('/users/{user}/sessions', [UserController::class, 'revokeSessions'])
            ->middleware('throttle:10,60'); // 10 requests per hour
    });

    Route::middleware('permission:users.view')->group(function () {
        Route::get('/users/{user}/audit-logs', [AuditLogController::class, 'forUser']);
        Route::get('/users/{user}/sessions', [UserController::class, 'sessions']);
    });

    // Permission Overrides Management
    Route::middleware('permission:users.manage_permissions')->group(function () {
        Route::get('/users/{user}/permission-overrides', [PermissionOverrideController::class, 'index']);
        Route::post('/users/{user}/permission-overrides', [PermissionOverrideController::class, 'store']);
        Route::get('/users/{user}/effective-permissions', [PermissionOverrideController::class, 'effective']);
    });

    Route::middleware('permission:users.manage_permissions')->prefix('permission-overrides')->group(function () {
        Route::get('/expiring', [PermissionOverrideController::class, 'expiring']);
        Route::put('/{override}', [PermissionOverrideController::class, 'update']);
        Route::delete('/{override}', [PermissionOverrideController::class, 'destroy']);
        Route::post('/{override}/renew', [PermissionOverrideController::class, 'renew']);
    });

    // Role Change Requests Management
    Route::middleware('permission:roles.view')->prefix('role-change-requests')->group(function () {
        Route::get('/', [RoleChangeRequestController::class, 'index']);
        Route::get('/pending', [RoleChangeRequestController::class, 'pending']);
        Route::get('/config', [RoleChangeRequestController::class, 'config']);
        Route::get('/{roleChangeRequest}', [RoleChangeRequestController::class, 'show']);
    });

    Route::middleware('permission:roles.manage')->prefix('role-change-requests')->group(function () {
        Route::post('/', [RoleChangeRequestController::class, 'store']);
        Route::post('/{roleChangeRequest}/approve', [RoleChangeRequestController::class, 'approve']);
        Route::post('/{roleChangeRequest}/reject', [RoleChangeRequestController::class, 'reject']);
    });

    // Roles & Permissions Management
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('roles/statistics', [RoleController::class, 'statistics']);
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/{role}', [RoleController::class, 'show']);
        Route::get('roles/{role}/users', [RoleController::class, 'users']);
        Route::get('roles/{role}/permissions', [RoleController::class, 'rolePermissions']);
        Route::get('permissions', [RoleController::class, 'permissions']);
    });

    Route::middleware('permission:roles.manage')->prefix('roles')->group(function () {
        Route::put('/{role}', [RoleController::class, 'update']);
    });

    Route::middleware('permission:roles.view')->group(function () {
        Route::get('/permissions', [RoleController::class, 'permissions']);
    });

    // Audit Logs Management
    Route::prefix('audit-logs')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
        Route::get('/filters', [AuditLogController::class, 'filters'])->middleware('permission:audit.view');
        Route::get('/statistics', [AuditLogController::class, 'statistics'])->middleware('permission:audit.view');
        Route::get('/export', [AuditLogController::class, 'export'])->middleware('permission:audit.export');
        Route::get('/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:audit.view');
    });

    // Application Logs (File-based)
    Route::middleware('permission:system.maintenance')->prefix('system-logs')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\LogViewerController::class, 'index']);
        Route::get('/file', [\App\Http\Controllers\Api\LogViewerController::class, 'show']);
        Route::get('/download', [\App\Http\Controllers\Api\LogViewerController::class, 'download']);
    });

    // System Maintenance
    Route::middleware('permission:system.maintenance')->prefix('maintenance')->group(function () {
        Route::get('/system-info', [\App\Http\Controllers\Api\MaintenanceController::class, 'systemInfo']);
        Route::get('/storage', [\App\Http\Controllers\Api\MaintenanceController::class, 'storageStats']);
        Route::get('/status', [\App\Http\Controllers\Api\MaintenanceController::class, 'status']);
        Route::post('/enable', [\App\Http\Controllers\Api\MaintenanceController::class, 'enable']);
        Route::post('/disable', [\App\Http\Controllers\Api\MaintenanceController::class, 'disable']);
        Route::post('/cache/clear', [\App\Http\Controllers\Api\MaintenanceController::class, 'clearCache']);
        Route::post('/views/clear', [\App\Http\Controllers\Api\MaintenanceController::class, 'clearViews']);
        Route::post('/sessions/clear', [\App\Http\Controllers\Api\MaintenanceController::class, 'clearSessions']);
        Route::post('/logs/clear', [\App\Http\Controllers\Api\MaintenanceController::class, 'clearLogs']);
        Route::get('/scheduled-tasks', [\App\Http\Controllers\Api\MaintenanceController::class, 'scheduledTasks']);
        Route::post('/scheduled-tasks/{task}/run', [\App\Http\Controllers\Api\MaintenanceController::class, 'runTask']);
        Route::get('/php-info', [\App\Http\Controllers\Api\MaintenanceController::class, 'phpInfo']);
        Route::get('/database-health', [\App\Http\Controllers\Api\MaintenanceController::class, 'databaseHealth']);
        Route::get('/logs', [\App\Http\Controllers\Api\MaintenanceController::class, 'logs']);
        Route::get('/backups', [\App\Http\Controllers\Api\MaintenanceController::class, 'backups']);
        Route::post('/backups/create', [\App\Http\Controllers\Api\MaintenanceController::class, 'createBackup']);
        Route::post('/backups/delete', [\App\Http\Controllers\Api\MaintenanceController::class, 'deleteBackup']);
        Route::get('/backups/download', [\App\Http\Controllers\Api\MaintenanceController::class, 'downloadBackup']);
        Route::post('/backups/secure-download', [\App\Http\Controllers\Api\MaintenanceController::class, 'secureDownload']);
        Route::post('/backups/bulk-delete', [\App\Http\Controllers\Api\MaintenanceController::class, 'bulkDelete']);
        Route::get('/external-services', [\App\Http\Controllers\Api\MaintenanceController::class, 'externalServices']);

        // Queue Management
        Route::prefix('queue')->group(function () {
            Route::get('/stats', [\App\Http\Controllers\Api\QueueController::class, 'stats']);
            Route::get('/pending', [\App\Http\Controllers\Api\QueueController::class, 'pending']);
            Route::get('/failed', [\App\Http\Controllers\Api\QueueController::class, 'failed']);
            Route::get('/completed', [\App\Http\Controllers\Api\MaintenanceController::class, 'queueCompleted']);
            Route::post('/retry/all', [\App\Http\Controllers\Api\QueueController::class, 'retryAll']);
            Route::post('/flush', [\App\Http\Controllers\Api\QueueController::class, 'flush']);
            Route::post('/retry/{id}', [\App\Http\Controllers\Api\QueueController::class, 'retry']);
            Route::post('/forget/{id}', [\App\Http\Controllers\Api\QueueController::class, 'forget']);
            Route::post('/forget/{id}', [\App\Http\Controllers\Api\QueueController::class, 'forget']);
        });

        // Analytics
        Route::middleware('permission:dashboard.analytics')->prefix('analytics')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Api\AnalyticsController::class, 'overview']);
            Route::get('/chart', [\App\Http\Controllers\Api\AnalyticsController::class, 'chart']);
            Route::get('/pages', [\App\Http\Controllers\Api\AnalyticsController::class, 'topPages']);
            Route::get('/sources', [\App\Http\Controllers\Api\AnalyticsController::class, 'sources']);
        });
    });

    // App Settings
    Route::middleware(['permission:settings.view', 'throttle:30,1'])->prefix('settings')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SettingsController::class, 'index']);
        Route::get('/{key}', [\App\Http\Controllers\Api\SettingsController::class, 'show']);
    });

    Route::middleware(['permission:settings.update', 'throttle:10,1'])->prefix('settings')->group(function () {
        Route::put('/', [\App\Http\Controllers\Api\SettingsController::class, 'update']);
        Route::put('/{key}', [\App\Http\Controllers\Api\SettingsController::class, 'updateSingle']);
        Route::put('/{key}', [\App\Http\Controllers\Api\SettingsController::class, 'updateSingle']);
        Route::post('/cache/clear', [\App\Http\Controllers\Api\SettingsController::class, 'clearCache']);
        Route::post('/logo', [\App\Http\Controllers\Api\SettingsController::class, 'uploadLogo']);
        Route::post('/favicon', [\App\Http\Controllers\Api\SettingsController::class, 'uploadFavicon']);
    });

    // Announcements - Public (for viewing/dismissing)
    Route::middleware('throttle:60,1')->prefix('announcements')->group(function () {
        Route::get('/active', [\App\Http\Controllers\Api\AnnouncementController::class, 'active']);
        Route::post('/{announcement}/dismiss', [\App\Http\Controllers\Api\AnnouncementController::class, 'dismiss']);
    });

    // Announcements - Admin Management
    Route::middleware(['permission:announcements.view', 'throttle:30,1'])->prefix('admin/announcements')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\AnnouncementController::class, 'index']);
        Route::get('/types', [\App\Http\Controllers\Api\AnnouncementController::class, 'types']);
        Route::get('/{announcement}', [\App\Http\Controllers\Api\AnnouncementController::class, 'show']);
    });

    Route::middleware(['permission:announcements.create', 'throttle:10,1'])->prefix('admin/announcements')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\AnnouncementController::class, 'store']);
    });

    Route::middleware(['permission:announcements.update', 'throttle:10,1'])->prefix('admin/announcements')->group(function () {
        Route::put('/{announcement}', [\App\Http\Controllers\Api\AnnouncementController::class, 'update']);
    });

    Route::middleware(['permission:announcements.delete', 'throttle:10,1'])->prefix('admin/announcements')->group(function () {
        Route::delete('/{announcement}', [\App\Http\Controllers\Api\AnnouncementController::class, 'destroy']);
    });

    // 2FA Enforcement Management (Admin)
    Route::middleware('permission:users.manage_status')->prefix('admin/2fa-enforcement')->group(function () {
        Route::post('/', [TwoFactorEnforcementController::class, 'enforce']);
        Route::get('/roles', [TwoFactorEnforcementController::class, 'roleEnforcements']);
    });

    // 2FA Enforcement Status (for current user)
    Route::get('/user/2fa-enforcement-status', [TwoFactorEnforcementController::class, 'getEnforcementStatus']);

    // Two-Factor Authentication Setup
    Route::prefix('user')->group(function () {
        Route::get('/two-factor-status', [TwoFactorController::class, 'status']);
        Route::post('/two-factor-authentication', [TwoFactorController::class, 'enableTotp']);
        Route::post('/confirmed-two-factor-authentication', [TwoFactorController::class, 'confirmTotp']);
        Route::delete('/two-factor-authentication', [TwoFactorController::class, 'disable']);
        Route::get('/two-factor-recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::post('/two-factor-recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::post('/two-factor-sms', [TwoFactorController::class, 'enableSms']);
        Route::delete('/two-factor-sms', [TwoFactorController::class, 'disableSms']);
        Route::post('/two-factor-sms/verify/send', [TwoFactorController::class, 'sendSmsCode']);
        Route::post('/two-factor-sms/verify', [TwoFactorController::class, 'verifySmsCode']);
        Route::post('/two-factor-email', [TwoFactorController::class, 'enableEmail']);
        Route::delete('/two-factor-email', [TwoFactorController::class, 'disableEmail']);
        Route::post('/confirm-password', [TwoFactorController::class, 'confirmPassword']);
    });

    // FAQ Management (Admin)
    Route::middleware('permission:faq.manage')->prefix('admin/faq')->group(function () {
        // Stats
        Route::get('/stats', [\App\Http\Controllers\Api\FaqManageController::class, 'stats']);

        // Categories
        Route::get('/categories', [\App\Http\Controllers\Api\FaqManageController::class, 'indexCategories']);
        Route::post('/categories', [\App\Http\Controllers\Api\FaqManageController::class, 'storeCategory']);
        Route::put('/categories/{category}', [\App\Http\Controllers\Api\FaqManageController::class, 'updateCategory']);
        Route::delete('/categories/{category}', [\App\Http\Controllers\Api\FaqManageController::class, 'destroyCategory']);
        Route::post('/categories/bulk-publish', [\App\Http\Controllers\Api\FaqManageController::class, 'bulkPublishCategories']);

        // Articles
        Route::get('/articles', [\App\Http\Controllers\Api\FaqManageController::class, 'indexArticles']);
        Route::post('/articles', [\App\Http\Controllers\Api\FaqManageController::class, 'storeArticle']);
        Route::get('/articles/{article}', [\App\Http\Controllers\Api\FaqManageController::class, 'showArticle']);
        Route::put('/articles/{article}', [\App\Http\Controllers\Api\FaqManageController::class, 'updateArticle']);
        Route::delete('/articles/{article}', [\App\Http\Controllers\Api\FaqManageController::class, 'destroyArticle']);
        Route::post('/articles/bulk-publish', [\App\Http\Controllers\Api\FaqManageController::class, 'bulkPublish']);

        // Article Media
        Route::get('/articles/{article}/media', [\App\Http\Controllers\Api\FaqArticleMediaController::class, 'index']);
        Route::post('/articles/{article}/media', [\App\Http\Controllers\Api\FaqArticleMediaController::class, 'store']);
        Route::delete('/articles/{article}/media/{media}', [\App\Http\Controllers\Api\FaqArticleMediaController::class, 'destroy']);

        // Article Versioning
        Route::get('/articles/{article}/versions', [\App\Http\Controllers\Api\FaqArticleVersionController::class, 'index']);
        Route::get('/versions/{version}', [\App\Http\Controllers\Api\FaqArticleVersionController::class, 'show']);
        Route::post('/versions/{version}/restore', [\App\Http\Controllers\Api\FaqArticleVersionController::class, 'restore']);
    });

    // Calendar
    Route::post('calendar/debug/reminder', [\App\Http\Controllers\CalendarController::class, 'debugReminder']);
    Route::post('calendar/events/{event}/invite', [\App\Http\Controllers\CalendarController::class, 'invite']);
    Route::get('calendar/events/{event}/ics', [\App\Http\Controllers\CalendarController::class, 'downloadIcs']);
    Route::post('calendar/export/bulk', [\App\Http\Controllers\CalendarController::class, 'bulkExport']);
    Route::get('calendar/events/export', [\App\Http\Controllers\CalendarController::class, 'export']);
    Route::apiResource('calendar/events', \App\Http\Controllers\CalendarController::class);

    // Calendar Sharing
    Route::get('calendar/shares', [\App\Http\Controllers\Api\CalendarShareController::class, 'index']);
    Route::post('calendar/shares', [\App\Http\Controllers\Api\CalendarShareController::class, 'store']);
    Route::put('calendar/shares/{id}', [\App\Http\Controllers\Api\CalendarShareController::class, 'update']);
    Route::delete('calendar/shares/{id}', [\App\Http\Controllers\Api\CalendarShareController::class, 'destroy']);

    // Calendar Google Sync
    Route::middleware('throttle:120,1')->group(function () {
        Route::get('calendar/oauth/connect', [\App\Http\Controllers\Api\CalendarOAuthController::class, 'redirect']);
        Route::post('calendar/oauth/connect', [\App\Http\Controllers\Api\CalendarOAuthController::class, 'connect']);
        Route::delete('calendar/oauth/disconnect', [\App\Http\Controllers\Api\CalendarOAuthController::class, 'disconnect']);
        Route::post('calendar/webhook', [\App\Http\Controllers\Api\CalendarOAuthController::class, 'webhook']);
    });

    // User Settings
    Route::get('user/social-accounts', function (\Illuminate\Http\Request $request) {
        return $request->user()->socialAccounts;
    });

    // Holidays
    Route::get('holidays', [\App\Http\Controllers\Api\HolidayController::class, 'index']);
    Route::get('holidays/countries', [\App\Http\Controllers\Api\HolidayController::class, 'countries']);

    // Ticket Reports
    Route::prefix('reports/tickets')->group(function () {
        Route::get('/stats', [\App\Http\Controllers\Api\TicketReportController::class, 'stats']);
        Route::get('/workload', [\App\Http\Controllers\Api\TicketReportController::class, 'workload']);
        Route::post('/export', [\App\Http\Controllers\Api\TicketReportController::class, 'export']);
    });

    // Tickets Management
    Route::middleware('throttle:60,1')->prefix('tickets')->group(function () {
        // View routes (tickets.view or tickets.view_own)
        Route::get('/', [\App\Http\Controllers\Api\TicketController::class, 'index']);
        Route::get('/stats', [\App\Http\Controllers\Api\TicketController::class, 'stats']);
        Route::get('/search-linkable', [\App\Http\Controllers\Api\TicketController::class, 'searchLinkable']);
        Route::get('/{ticket}', [\App\Http\Controllers\Api\TicketController::class, 'show']);
        Route::get('/{ticket}/comments', [\App\Http\Controllers\Api\TicketController::class, 'comments']);

        // Create
        Route::middleware('permission:tickets.create')->group(function () {
            Route::post('/', [\App\Http\Controllers\Api\TicketController::class, 'store']);
        });

        // Update (policy handles own vs all)
        Route::put('/{ticket}', [\App\Http\Controllers\Api\TicketController::class, 'update']);
        Route::post('/{ticket}/comments', [\App\Http\Controllers\Api\TicketController::class, 'addComment']);

        // Follow/Unfollow
        Route::post('/{ticket}/follow', [\App\Http\Controllers\Api\TicketController::class, 'follow']);
        Route::delete('/{ticket}/follow', [\App\Http\Controllers\Api\TicketController::class, 'unfollow']);

        // Assign (requires tickets.assign)
        Route::middleware('permission:tickets.assign')->group(function () {
            Route::put('/{ticket}/assign', [\App\Http\Controllers\Api\TicketController::class, 'assign']);
        });

        // Status change (requires tickets.close)
        Route::middleware('permission:tickets.close')->group(function () {
            Route::put('/{ticket}/status', [\App\Http\Controllers\Api\TicketController::class, 'changeStatus']);
        });

        // Internal notes (requires tickets.internal_notes)
        Route::middleware('permission:tickets.internal_notes')->group(function () {
            Route::get('/{ticket}/internal-notes', [\App\Http\Controllers\Api\TicketController::class, 'internalNotes']);
            Route::post('/{ticket}/internal-notes', [\App\Http\Controllers\Api\TicketController::class, 'addInternalNote']);
        });

        // Activity/Audit trail
        Route::get('/{ticket}/activity', [\App\Http\Controllers\Api\TicketController::class, 'activity']);

        // Attachments
        Route::get('/{ticket}/attachments', [\App\Http\Controllers\Api\TicketController::class, 'attachments']);
        Route::post('/{ticket}/attachments', [\App\Http\Controllers\Api\TicketController::class, 'uploadAttachment']);
        Route::delete('/{ticket}/attachments/{mediaId}', [\App\Http\Controllers\Api\TicketController::class, 'deleteAttachment']);

        // Delete (requires tickets.delete)
        Route::middleware('permission:tickets.delete')->group(function () {
            Route::delete('/{ticket}', [\App\Http\Controllers\Api\TicketController::class, 'destroy']);
        });
    });

    // User Presence
    Route::prefix('presence')->group(function () {
        Route::post('/heartbeat', [\App\Http\Controllers\PresenceController::class, 'heartbeat']);
        Route::put('/status', [\App\Http\Controllers\PresenceController::class, 'updateStatus']);
        Route::get('/me', [\App\Http\Controllers\PresenceController::class, 'me']);
        Route::get('/users', [\App\Http\Controllers\PresenceController::class, 'users']);
        Route::post('/connect', [\App\Http\Controllers\PresenceController::class, 'checkConnection']);
        Route::post('/offline', [\App\Http\Controllers\PresenceController::class, 'offline']);
        // Debug endpoint (only works in local/development)
        Route::post('/debug/broadcast', [\App\Http\Controllers\PresenceController::class, 'debugBroadcast']);
    });

    // Chat System
    Route::prefix('chat')->group(function () {
        // Chat List (root)
        Route::get('/', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'index']);

        // Static routes MUST come before {chatId} wildcard
        // Groups
        Route::post('/groups', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'createGroup']);

        // People Discovery & DM
        Route::get('/people/search', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'people']);
        Route::post('/dm/ensure', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'ensureDm']);

        // Invites
        Route::get('/invites', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'invites']);
        Route::post('/invites', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'sendInvite']);
        Route::post('/invites/{inviteId}/accept', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'acceptInvite']);
        Route::post('/invites/{inviteId}/decline', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'declineInvite']);

        // Chat-specific routes (with {chat} parameter)
        Route::get('/{chat}', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'show']);

        // Heartbeat
        Route::post('/{chat}/heartbeat', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'heartbeat']);

        // Messages
        Route::get('/{chat}/messages', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'messages']);
        Route::get('/{chat}/messages/search', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'searchMessages']);
        Route::get('/{chat}/messages/around/{messagePublicId}', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'messagesAround']);
        Route::post('/{chat}/send', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'send']);
        Route::post('/{chat}/upload', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'upload']);
        Route::post('/{chat}/typing', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'typing']);
        Route::post('/{chat}/read', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'markRead']);

        // Media
        Route::get('/{chat}/media', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'media']);
        Route::get('/{chat}/storage-stats', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'storageStats']);
        Route::delete('/{chat}/media/{mediaId}', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'deleteMedia']);

        // Group management
        Route::match(['put', 'patch'], '/{chat}', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'update']);
        Route::post('/{chat}/members', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'addMember']);
        Route::delete('/{chat}/members/{memberId}', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'removeMember']);

        // Giphy
        Route::get('/giphy/search', [\App\Http\Controllers\Api\GiphyController::class, 'search']);
        Route::get('/giphy/trending', [\App\Http\Controllers\Api\GiphyController::class, 'trending']);

        // Advanced Group Management
        Route::post('/{chat}/leave', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'leave']);
        Route::post('/{chat}/rejoin', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'rejoin']);
        Route::post('/{chat}/kick/{userPublicId}', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'kick']);
        Route::delete('/{chat}', [\App\Http\Controllers\Api\Chat\ChatApiController::class, 'delete']);
    });

    // Personal Notes
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('notes/reorder', [\App\Http\Controllers\Api\NoteController::class, 'reorder']);
        Route::post('notes/bulk-delete', [\App\Http\Controllers\Api\NoteController::class, 'bulkDelete']);
        Route::post('notes/bulk-update', [\App\Http\Controllers\Api\NoteController::class, 'bulkUpdate']);
        Route::apiResource('notes', \App\Http\Controllers\Api\NoteController::class);
    });

    // Admin Chat Management
    Route::middleware('permission:chats.manage')->prefix('admin/chats')->group(function () {
        Route::get('/flagged', [\App\Http\Controllers\Api\Chat\AdminChatController::class, 'storedFlaggedChats']);
        Route::post('/{chat}/restore', [\App\Http\Controllers\Api\Chat\AdminChatController::class, 'restore']);
    });
    // Link Unfurling
    Route::post('/link/unfurl', [\App\Http\Controllers\Api\LinkUnfurlController::class, 'unfurl']);

    // Blocked URLs
    Route::apiResource('blocked-urls', \App\Http\Controllers\Api\BlockedUrlController::class);
});

// Two-Factor Challenge routes (no auth required, but rate limited)
Route::middleware(['throttle:sensitive'])->group(function () {
    Route::get('/two-factor-challenge/methods', [TwoFactorController::class, 'challengeMethods']);
    Route::post('/two-factor-challenge/send', [TwoFactorController::class, 'sendChallengeCode']);
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verifyChallenge']);
});
