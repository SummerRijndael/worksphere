<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Social Login</title>
</head>
<body>
    <div
        id="social-auth-result"
        data-type="worksphere-social-auth"
        data-status="{{ $status }}"
        data-redirect-url="{{ $redirectUrl }}"
        data-message="{{ $message ?? '' }}"
    >
        Completing sign-in...
    </div>
    <script src="{{ asset('js/social-auth-callback.js') }}"></script>
</body>
</html>
