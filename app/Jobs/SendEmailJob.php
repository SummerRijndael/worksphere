<?php

namespace App\Jobs;

use App\Models\Email;
use App\Models\EmailAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

/**
 * Job to send a single email via SMTP using Laravel's native Mail.
 */
class SendEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public int $backoff;

    public function __construct(
        public int $emailId,
        public int $accountId
    ) {
        $config = config('email.jobs.send');
        $this->tries = $config['tries'] ?? 5;
        $this->timeout = $config['timeout'] ?? 120;
        $this->backoff = $config['backoff'] ?? 60;

        $this->onQueue($config['queue'] ?? 'emails');
    }

    /**
     * Retry until this time.
     */
    public function retryUntil(): \DateTime
    {
        $hours = config('email.jobs.send.retry_until_hours', 4);

        return now()->addHours($hours);
    }

    public function handle(): void
    {
        $email = Email::find($this->emailId);
        if (! $email) {
            Log::warning('[SendEmailJob] Email not found', ['email_id' => $this->emailId]);

            return;
        }

        $account = EmailAccount::find($this->accountId);
        if (! $account) {
            Log::warning('[SendEmailJob] Account not found', ['account_id' => $this->accountId]);

            return;
        }

        try {
            $this->sendEmail($email, $account);

            // Update email as sent
            $email->update([
                'sent_at' => now(),
                'is_draft' => false,
            ]);

            // Mark account as used
            $account->markAsUsed();

            Log::info('[SendEmailJob] Email sent successfully', [
                'email_id' => $email->id,
                'to' => collect($email->to)->pluck('email')->toArray(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[SendEmailJob] Failed to send email', [
                'email_id' => $email->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw for retry
        }
    }

    /**
     * Send the email via Laravel's Mail with dynamic SMTP config.
     */
    protected function sendEmail(Email $email, EmailAccount $account): void
    {
        // Create dynamic mailer with account's SMTP settings
        $mailer = $this->createMailerForAccount($account);

        $mailer->send([], [], function (Message $message) use ($email, $account) {
            // From
            $message->from($account->email, $account->name ?? $email->from_name);

            // To
            foreach ($email->to ?? [] as $recipient) {
                $message->to($recipient['email'], $recipient['name'] ?? '');
            }

            // CC
            foreach ($email->cc ?? [] as $recipient) {
                $message->cc($recipient['email'], $recipient['name'] ?? '');
            }

            // BCC
            foreach ($email->bcc ?? [] as $recipient) {
                $message->bcc($recipient['email'], $recipient['name'] ?? '');
            }

            // Subject and Body
            $message->subject($email->subject);
            $message->html($email->body_html);

            if ($email->body_plain) {
                $message->text($email->body_plain);
            }

            // Attachments
            foreach ($email->getMedia('attachments') as $media) {
                $message->attach($media->getPath(), [
                    'as' => $media->file_name,
                    'mime' => $media->mime_type,
                ]);
            }
        });
    }

    /**
     * Create a mailer instance with account-specific SMTP settings.
     */
    protected function createMailerForAccount(EmailAccount $account): Mailer
    {
        $encryption = match ($account->smtp_encryption) {
            'tls' => 'tls',
            'ssl' => 'ssl',
            default => null,
        };

        // Check for OAuth and refresh token if needed
        if ($account->isOAuth()) {
            if ($account->needsTokenRefresh()) {
                // We need the service to refresh.
                // Since we are in a job, we can resolve the service.
                app(\App\Services\EmailAccountService::class)->refreshToken($account);
                $account->refresh(); // Reload to get new token
            }
        }

        $password = $account->password;
        if ($account->isOAuth()) {
            $password = $account->access_token;
        }

        $dsn = new Dsn(
            $encryption === 'ssl' ? 'smtps' : 'smtp',
            $account->smtp_host,
            $account->username ?? $account->email,
            $password,
            $account->smtp_port
        );

        $factory = new EsmtpTransportFactory;
        $transport = $factory->create($dsn);

        // Create a new mailer with the transport
        $mailer = new \Illuminate\Mail\Mailer(
            'dynamic',
            app(\Illuminate\Contracts\View\Factory::class),
            $transport,
            app('events')
        );

        return $mailer;
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[SendEmailJob] Job failed permanently', [
            'email_id' => $this->emailId,
            'account_id' => $this->accountId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get tags for Horizon.
     */
    public function tags(): array
    {
        return ['email', 'send', 'email:'.$this->emailId];
    }
}
