<?php

namespace App\Helpers;

use Illuminate\Support\Carbon;

class MockEmailData
{
    public static function getEmails()
    {
        $now = Carbon::now();

        return [
            [
                'id' => 101,
                'public_id' => 'mock-101',
                'message_id' => 'mock-msg-1',
                'subject' => 'Welcome to the New CoreSync Design',
                'from_name' => 'Design Team',
                'from_email' => 'design@coresync.app',
                'to' => [['name' => 'User', 'email' => 'user@example.com']],
                'cc' => [],
                'bcc' => [],
                'preview' => 'Check out the new dashboard mockups...',
                'body_html' => '
                    <div style="font-family: sans-serif; color: #333;">
                        <h1>Welcome Aboard!</h1>
                        <p>We are excited to share the new designs with you.</p>
                        <p>Here is a preview of the dashboard:</p>
                        <img src="https://picsum.photos/600/300" alt="Dashboard Preview" style="max-width: 100%; border-radius: 8px; margin: 10px 0;">
                        <p>Let us know what you think!</p>
                    </div>
                ',
                'body_plain' => 'Welcome Aboard! We are excited to share...',
                'is_read' => false,
                'is_starred' => true,
                'is_draft' => false,
                'has_attachments' => true,
                'date' => $now->subMinutes(5)->toIso8601String(),
                'received_at' => $now->subMinutes(5)->toIso8601String(),
                'headers' => ['x-mock' => 'true'],
                'folder' => 'inbox',
                'attachments' => [
                    [
                        'id' => 'att-1',
                        'name' => 'design-specs.pdf',
                        'size' => '2.5 MB',
                        'type' => 'application/pdf',
                        'url' => '#',
                    ],
                    [
                        'id' => 'att-2',
                        'name' => 'logo-assets.zip',
                        'size' => '15 MB',
                        'type' => 'application/zip',
                        'url' => '#',
                    ],
                ],
                'labels' => [],
                'email_account' => ['id' => 1, 'email' => 'user@example.com', 'name' => 'Main Account'],
            ],
            [
                'id' => 102,
                'public_id' => 'mock-102',
                'message_id' => 'mock-msg-2',
                'subject' => 'Security Alert: XSS Test',
                'from_name' => 'Security Bot',
                'from_email' => 'security@test.com',
                'to' => [['name' => 'User', 'email' => 'user@example.com']],
                'cc' => [],
                'bcc' => [],
                'preview' => 'This email contains a simulated XSS attack script...',
                'body_html' => '
                    <div style="font-family: sans-serif;">
                        <h2 style="color: red;">XSS Sanitization Test</h2>
                        <p>The following script should be stripped by the frontend:</p>
                        <pre>&lt;script&gt;alert("XSS Attack!");&lt;/script&gt;</pre>
                        
                        <!-- Actual script tag to test sanitization -->
                        <script>alert("If you see this, sanitization FAILED");</script>
                        
                        <p>And here is an image with an invalid onerror handler:</p>
                        <img src="invalid.jpg" onerror="alert(\'XSS via img onerror\')" alt="Broken Image">
                        
                        <p>Safe content should remain.</p>
                    </div>
                ',
                'body_plain' => 'XSS Sanitization Test...',
                'is_read' => true,
                'is_starred' => false,
                'is_draft' => false,
                'has_attachments' => false,
                'date' => $now->subHours(2)->toIso8601String(),
                'received_at' => $now->subHours(2)->toIso8601String(),
                'headers' => ['x-mock' => 'true'],
                'folder' => 'inbox',
                'attachments' => [],
                'labels' => [],
                'email_account' => ['id' => 1, 'email' => 'user@example.com', 'name' => 'Main Account'],
            ],
            [
                'id' => 103,
                'public_id' => 'mock-103',
                'message_id' => 'mock-msg-3',
                'subject' => 'Inline Image Test (CID Simulation)',
                'from_name' => 'Ryann Olaso',
                'from_email' => 'ryann@example.com',
                'to' => [['name' => 'User', 'email' => 'user@example.com']],
                'cc' => [],
                'bcc' => [],
                'preview' => 'This email simulates inline images using data URIs...',
                'body_html' => '
                    <div>
                        <p>Verified purchase:</p>
                        <img src="https://loremflickr.com/320/240" alt="Product 1">
                        <br>
                        <p>Another product:</p>
                        <img src="https://loremflickr.com/320/240?lock=1" alt="Product 2">
                    </div>
                ',
                'body_plain' => 'Verified purchase...',
                'is_read' => false,
                'is_starred' => false,
                'is_draft' => false,
                'has_attachments' => true,
                'date' => $now->subDay()->toIso8601String(),
                'received_at' => $now->subDay()->toIso8601String(),
                'headers' => ['x-mock' => 'true'],
                'folder' => 'inbox',
                'attachments' => [
                    [
                        'id' => 'att-3',
                        'name' => 'receipt.pdf',
                        'size' => '120 KB',
                        'type' => 'application/pdf',
                        'url' => '#',
                    ],
                ],
                'labels' => [],
                'email_account' => ['id' => 1, 'email' => 'user@example.com', 'name' => 'Main Account'],
            ],
            [
                'id' => 104,
                'public_id' => 'mock-104',
                'message_id' => 'mock-msg-4',
                'subject' => 'Undelivered Mail Returned to Sender',
                'from_name' => 'Mail Delivery Subsystem',
                'from_email' => 'mailer-daemon@googlemail.com',
                'to' => [['name' => 'User', 'email' => 'user@example.com']],
                'cc' => [],
                'bcc' => [],
                'preview' => 'Delivery to the following recipient failed permanently...',
                'body_html' => '
                    <div style="font-family: monospace;">
                        <p>Delivery to the following recipient failed permanently:</p>
                        <p>     bob@nowhere.com</p>
                        <p>Technical details of permanent failure:</p>
                        <p>DNS Error: 500 Domain Not Found</p>
                    </div>
                ',
                'body_plain' => 'Delivery failed...',
                'is_read' => true,
                'is_starred' => false,
                'is_draft' => false,
                'has_attachments' => false,
                'date' => $now->subDays(3)->toIso8601String(),
                'received_at' => $now->subDays(3)->toIso8601String(),
                'headers' => ['x-mock' => 'true'],
                'folder' => 'inbox',
                'attachments' => [],
                'labels' => [],
                'email_account' => ['id' => 1, 'email' => 'user@example.com', 'name' => 'Main Account'],
            ],
        ];
    }
}
