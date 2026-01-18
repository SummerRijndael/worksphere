@extends('emails.layouts.default')

@section('preheader')
    Confirm linking your {{ $provider }} account to your {{ config('app.name') }} account.
@endsection

@section('content')
    <h2 style="margin: 0 0 20px 0; font-size: 20px; color: #111827;">Hello {{ $user->name }},</h2>
    
    <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 24px; color: #374151;">
        We received a request to link your <strong>{{ ucfirst($provider) }}</strong> account to your existing account at <strong>{{ config('app.name') }}</strong>.
    </p>
    
    <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 24px; color: #374151;">
        If this was you, please click the button below to confirm and complete the linking process. This link will expire in 60 minutes.
    </p>

    <div align="center">
        <a href="{{ $actionUrl }}" class="button" target="_blank">Confirm Account Link</a>
    </div>
    
    <p style="margin: 0 0 0 0; font-size: 16px; line-height: 24px; color: #374151;">
        If you did not initiate this request, please ignore this email. Your account remains secure.
    </p>
    
    <p style="margin: 20px 0 0 0; font-size: 16px; line-height: 24px; color: #374151;">
        Best regards,<br>
        The {{ config('app.name') }} Team
    </p>
@endsection

@section('footer-link')
    <a href="{{ $actionUrl }}" style="word-break: break-all;">{{ $actionUrl }}</a>
@endsection
