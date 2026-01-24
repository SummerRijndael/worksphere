<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $title }}</title>
    <!--[if mso]>
    <style>
        table {border-collapse: collapse; border-spacing: 0; border: none; margin: 0;}
        div, td {padding: 0;}
        div {margin: 0 !important;}
    </style>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
            <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        /* Base Reset */
        body,
        td,
        div,
        p,
        a,
        input,
        button {
            font-family: 'Segoe UI', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            word-break: break-word;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: #f3f4f6;
            color: #1f2937;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        img {
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
            display: block;
        }

        /* Helpers */
        .wrapper {
            background-color: #f3f4f6;
            padding: 40px 20px;
        }

        .container {
            background-color: #ffffff;
            margin: 0 auto;
            max-width: 600px;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        /* Header */
        .header {
            background-color: #ffffff;
            padding: 32px 40px;
            text-align: center;
            border-bottom: 1px solid #f3f4f6;
        }

        .app-brand {
            font-size: 24px;
            font-weight: 700;
            color: #4f46e5;
            /* Indigo 600 */
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .app-logo {
            height: 40px;
            margin: 0 auto;
        }

        /* Content */
        .content {
            padding: 40px;
        }

        .greeting {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 16px;
            margin-top: 0;
        }

        .message {
            font-size: 16px;
            line-height: 1.6;
            color: #4b5563;
            margin-bottom: 32px;
            margin-top: 0;
        }

        /* Info Card */
        .info-card {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px;
        }

        .ticket-header {
            margin-bottom: 16px;
            display: block;
            /* Fallback */
        }

        .ticket-id {
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            display: block;
        }

        .ticket-title {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            text-decoration: none;
            line-height: 1.4;
            display: block;
            margin: 0;
        }

        /* Badges */
        .badge-container {
            margin-top: 16px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 8px;
            white-space: nowrap;
        }

        /* Priority Colors */
        .priority-low {
            background-color: #ecfdf5;
            color: #047857;
        }

        .priority-medium {
            background-color: #fffbeb;
            color: #b45309;
        }

        .priority-high {
            background-color: #fff1f2;
            color: #be123c;
        }

        .priority-critical {
            background-color: #7f1d1d;
            color: #fef2f2;
        }

        /* Status Colors */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 8px;
        }

        /* Open/Warning */
        .status-bg-warning {
            background-color: #eff6ff;
            color: #1d4ed8;
        }

        /* Kept Blue for Open as per common ticket systems */
        /* In Progress */
        .status-bg-primary {
            background-color: #f5f3ff;
            color: #6d28d9;
        }

        /* Resolved */
        .status-bg-success {
            background-color: #f0fdf4;
            color: #15803d;
        }

        /* Closed */
        .status-bg-secondary {
            background-color: #f3f4f6;
            color: #4b5563;
        }

        /* Details Grid */
        .details-table {
            width: 100%;
            margin-top: 16px;
        }

        .details-label {
            font-size: 12px;
            text-transform: uppercase;
            color: #9ca3af;
            font-weight: 600;
            padding-bottom: 4px;
            width: 100px;
        }

        .details-value {
            font-size: 14px;
            color: #374151;
            padding-bottom: 4px;
            font-weight: 500;
        }

        /* Action Button */
        .button-container {
            text-align: center;
            margin-bottom: 10px;
        }

        .button {
            display: inline-block;
            background-color: #4f46e5;
            /* Indigo 600 */
            color: #ffffff !important;
            font-size: 16px;
            font-weight: 600;
            padding: 14px 32px;
            border-radius: 8px;
            text-decoration: none;
            transition: background-color 0.2s;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
        }

        .button:hover {
            background-color: #4338ca;
        }

        /* Alerts */
        .alert {
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
        }

        .alert-error {
            background-color: #fef2f2;
            color: #991b1b;
            border: 1px solid #fee2e2;
        }

        /* Footer */
        .footer {
            background-color: #f9fafb;
            padding: 32px 40px;
            text-align: center;
            border-top: 1px solid #f3f4f6;
        }

        .footer-text {
            font-size: 12px;
            color: #9ca3af;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .footer-link {
            color: #6b7280;
            text-decoration: underline;
        }

        .footer-link:hover {
            color: #4b5563;
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <center>
            <div class="container">
                <!-- Header -->
                <div class="header">
                    @if($appLogo)
                        <img src="{{ $appLogo }}" alt="{{ $appName }}" class="app-logo">
                    @else
                        <a href="{{ config('app.url') }}" class="app-brand">{{ $appName }}</a>
                    @endif
                </div>

                <!-- Main Content -->
                <div class="content">
                    <h1 class="greeting">Hello {{ $recipient->name }},</h1>

                    <p class="message">{{ $notificationMessage }}</p>

                    @if($type === 'sla_breach')
                        <div class="alert alert-error">
                            ⚠️ <strong>SLA Breach Alert</strong><br>
                            This ticket has exceeded its SLA threshold. Please take immediate action.
                        </div>
                    @endif

                    <!-- Ticket Card -->
                    <div class="info-card">
                        <div class="ticket-header">
                            <span class="ticket-id">{{ $ticket->ticket_number }}</span>
                            <div class="ticket-title">{{ $ticket->title }}</div>
                        </div>

                        <table class="details-table">
                            <tr>
                                <td class="details-label">Status</td>
                                <td class="details-value">
                                    <span class="status-badge status-bg-{{ $ticket->status->color() }}">
                                        {{ $ticket->status->label() }}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="details-label">Priority</td>
                                <td class="details-value">
                                    <span class="badge priority-{{ strtolower($ticket->priority->value) }}">
                                        {{ ucfirst($ticket->priority->value) }}
                                    </span>
                                </td>
                            </tr>
                            @if($ticket->assignee)
                                <tr>
                                    <td class="details-label">Assignee</td>
                                    <td class="details-value">
                                        {{ $ticket->assignee->name }}
                                    </td>
                                </tr>
                            @endif
                            @if($ticket->due_at)
                                <tr>
                                    <td class="details-label">Due Date</td>
                                    <td class="details-value">
                                        {{ $ticket->due_at->format('M j, Y') }}
                                    </td>
                                </tr>
                            @endif
                        </table>
                    </div>

                    <!-- CTA -->
                    <div class="button-container">
                        <a href="{{ $actionUrl }}" class="button">{{ $actionText }}</a>
                    </div>
                </div>

                <!-- Footer -->
                <div class="footer">
                    <p class="footer-text">
                        © {{ date('Y') }} {{ $appName }}. All rights reserved.<br>
                        This email was sent to {{ $recipient->email }} regarding ticket #{{ $ticket->ticket_number }}.
                    </p>
                    <p class="footer-text">
                        <a href="{{ $unsubscribeUrl }}" class="footer-link">Manage Notifications</a>
                    </p>
                </div>
            </div>
        </center>
    </div>
</body>

</html>