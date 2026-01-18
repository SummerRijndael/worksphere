<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rate-limited CSRF cookie endpoint to prevent abuse
Route::get('/sanctum/csrf-cookie', [\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show'])
    ->middleware(['web', 'throttle:10,1'])
    ->name('sanctum.csrf-cookie');

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

// Generic Secure Media Serving (Signed URLs bypass auth; middleware must be permissive)
// Place this OUTSIDE auth:sanctum to ensure requests without cookies (e.g. from img tags) can reach the controller
Route::get('/media/{media}/{conversion?}', [\App\Http\Controllers\Api\MediaController::class, 'show'])
    ->name('media.show');

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
