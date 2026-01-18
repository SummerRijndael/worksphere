@extends('emails.layouts.default')

@section('preheader')
    Welcome to {{ config('app.name') }}! We're excited to have you on board.
@endsection

@section('content')
    <h2 style="margin: 0 0 20px 0; font-size: 20px; color: #111827;">Hello {{ $user->name }},</h2>

    <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 24px; color: #374151;">
        Welcome to <strong>{{ config('app.name') }}</strong>! Your account has been successfully created.
    </p>

    @if($requiresPasswordSetup ?? false)
        <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 24px; color: #374151;">
            Since you signed up via a social provider, you need to set up a password to secure your account. Please click the
            button below to verify your email and set your password.
        </p>
    @else
        <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 24px; color: #374151;">
            To get started, please verify your email address by clicking the button below.
        </p>
    @endif

    <div align="center">
        <a href="{{ $actionUrl }}" class="button" target="_blank">{{ $actionText ?? 'Go to Dashboard' }}</a>
    </div>

    <p style="margin: 0 0 0 0; font-size: 16px; line-height: 24px; color: #374151;">
        If you have any questions or need assistance, feel free to contact our support team.
    </p>

    <p style="margin: 20px 0 0 0; font-size: 16px; line-height: 24px; color: #374151;">
        Best regards,<br>
        The {{ config('app.name') }} Team
    </p>
@endsection

@section('footer-link')
    <a href="{{ $actionUrl }}" style="word-break: break-all;">{{ $actionUrl }}</a>
@endsection