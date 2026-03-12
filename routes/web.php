<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rate-limited CSRF cookie endpoint to prevent abuse
Route::get('/sanctum/csrf-cookie', [\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show'])
    ->middleware(['web', 'throttle:10,1'])
    ->name('sanctum.csrf-cookie');

// Dev Login Helper (Session based, for E2E tests)
Route::get('/dev/login-as', [\App\Http\Controllers\Api\DevController::class, 'loginAs'])
    ->middleware(['web', \App\Http\Middleware\DevAccessMiddleware::class]);

// Serve the Vue SPA for all routes
Route::get('/setup-account/{id}', function () {
    return view('app');
})->name('setup-account');

// Social Auth Callback (Web)
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\Api\AuthController::class, 'webSocialCallback'])
    ->middleware(['web']);

// Email OAuth - Defined here to share 'web' session stack
Route::middleware(['web'])->group(function () {
    // Initiation: Generates state, saves to session
    // WARNING: Defined as /api/... to match Google Console whitelist without changing user configuration
    Route::get('/api/email-accounts/oauth/{provider}/redirect', [\App\Http\Controllers\Api\EmailOAuthController::class, 'redirect']);

    // Callback: Verifies state from session
    Route::get('/api/email-accounts/oauth/{provider}/callback', [\App\Http\Controllers\Api\EmailOAuthController::class, 'callback'])
        ->name('email-oauth.callback');
});

// Email Verification (clicked from email - must be web route with proper redirect)
Route::get('/email/verify/{id}/{hash}', function (Request $request, int $id, string $hash) {
    // Validate signature manually to allow graceful redirect instead of 403
    if (! $request->hasValidSignature()) {
        return redirect('/auth/login?verification=invalid&reason=expired_or_invalid_link');
    }

    $user = User::find($id);

    if (! $user) {
        return redirect('/auth/login?verification=invalid&reason=user_not_found');
    }

    // Check hash matches
    if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
        return redirect('/auth/login?verification=invalid&reason=hash_mismatch');
    }

    // Check if already verified
    if ($user->hasVerifiedEmail()) {
        // Redirect to verify-email page which will detect verified status and show success
        return redirect('/auth/verify-email?verified=1');
    }

    // Mark as verified
    if ($user->markEmailAsVerified()) {
        event(new Verified($user));
    }

    // Redirect to verify-email page which will detect verified status and show success with countdown
    return redirect('/auth/verify-email?verified=1');
})->middleware(['throttle:6,1'])->name('verification.verify');

// Chat Media Routes (must be before SPA catch-all)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/chat/media/{mediaId}/view', [\App\Http\Controllers\ChatMediaController::class, 'view'])
        ->name('chat.media.view');
    Route::get('/chat/media/{mediaId}/download', [\App\Http\Controllers\ChatMediaController::class, 'download'])
        ->name('chat.media.download');
    Route::get('/chat/media/{mediaId}/{conversion}', [\App\Http\Controllers\ChatMediaController::class, 'conversion'])
        ->name('chat.media.conversion')
        ->where('conversion', 'thumb|web|optimized|webp');
});

Route::middleware(['signed'])->group(function () {
    Route::get('/meeting-chat/media/{mediaId}/view', [\App\Http\Controllers\MeetingChatMediaController::class, 'view'])
        ->name('meeting.chat.media.view');
    Route::get('/meeting-chat/media/{mediaId}/download', [\App\Http\Controllers\MeetingChatMediaController::class, 'download'])
        ->name('meeting.chat.media.download');
    Route::get('/meeting-chat/media/{mediaId}/{conversion}', [\App\Http\Controllers\MeetingChatMediaController::class, 'conversion'])
        ->name('meeting.chat.media.conversion')
        ->where('conversion', 'thumb|web');
});

// Generic Secure Media Serving (Signed URLs bypass auth; middleware must be permissive)
// Place this OUTSIDE auth:sanctum to ensure requests without cookies (e.g. from img tags) can reach the controller
Route::get('/media/{media}/{conversion?}', [\App\Http\Controllers\Api\MediaController::class, 'show'])
    ->name('media.show');

// Standalone Call Page (must be before SPA catch-all)
Route::get('/call/{callId}', function () {
    return view('call');
})->middleware(['auth:sanctum'])->name('call.page');

// Public Profile Route (for SEO/social unfurling - must be before generic catch-all)
Route::get('/p/{slug}', function (string $slug) {
    // We lookup the user by username (slug)
    $user = User::where('username', $slug)->first();

    if (! $user) {
        // Fallback to standard view if user not found, SPA will handles 404 UI
        return view('app');
    }

    return view('app', [
        'ogTitle' => $user->name.' on WorkSphere',
        'ogDescription' => $user->bio ?? 'View profile on WorkSphere.',
        'ogImage' => $user->avatar_url ?? asset('static/images/worksphere_brand.png'),
    ]);
});

// Search Engine Robots
Route::get('/robots.txt', \App\Http\Controllers\RobotsController::class);

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!.*\\.(js|css|map|png|jpg|jpeg|gif|svg|ico|json|txt|xml|webmanifest|woff2?|ttf)$).*$');
