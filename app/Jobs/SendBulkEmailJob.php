<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

/**
 * Job to send bulk emails in batches with rate limiting using Laravel native Mail.
 */
class SendBulkEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public int $uniqueFor = 3600; // 1 hour uniqueness lock

    public function __construct(
        public int $userId,
        public int $accountId,
        public int $sourceEmailId,
        public array $recipients, // Chunk of recipients
        public array $emailData,
        public int $batchIndex,
        public int $totalBatches
    ) {
        $config = config('email.jobs.bulk_send');
        $this->tries = $config['tries'] ?? 3;
        $this->timeout = $config['timeout'] ?? 600;

        $this->onQueue($config['queue'] ?? 'emails');
    }

    /**
     * Unique ID for the job.
     */
    public function uniqueId(): string
    {
        return sprintf(
            'bulk-email:%d:%d:%d',
            $this->userId,
            $this->accountId,
            $this->batchIndex
        );
    }

    /**
     * Retry until this time.
     */
    public function retryUntil(): \DateTime
    {
        $hours = config('email.jobs.bulk_send.retry_until_hours', 8);

        return now()->addHours($hours);
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        $account = EmailAccount::find($this->accountId);

        if (! $user || ! $account) {
            Log::warning('[SendBulkEmailJob] User or account not found', [
                'user_id' => $this->userId,
                'account_id' => $this->accountId,
            ]);

            return;
        }

        Log::info('[SendBulkEmailJob] Processing batch', [
            'batch' => $this->batchIndex + 1,
            'total_batches' => $this->totalBatches,
            'recipients_count' => count($this->recipients),
        ]);

        $mailer = $this->createMailerForAccount($account);
        $successCount = 0;
        $failedRecipients = [];

        $sourceEmail = \App\Models\Email::find($this->sourceEmailId);

        foreach ($this->recipients as $recipient) {
            try {
                $this->sendToRecipient($mailer, $account, $recipient, $this->emailData, $sourceEmail);
                $successCount++;

                // Small delay between individual sends to avoid rate limiting
                usleep(100000); // 100ms
            } catch (\Throwable $e) {
                $failedRecipients[] = [
                    'email' => $recipient['email'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];

                Log::warning('[SendBulkEmailJob] Failed to send to recipient', [
                    'recipient' => $recipient['email'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);

                // Continue with next recipient, don't fail entire batch
            }
        }

        Log::info('[SendBulkEmailJob] Batch completed', [
            'batch' => $this->batchIndex + 1,
            'success_count' => $successCount,
            'failed_count' => count($failedRecipients),
        ]);

        // Mark account as used
        $account->markAsUsed();
    }

    /**
     * Send email to a single recipient using Laravel Mail.
     */
    protected function sendToRecipient(Mailer $mailer, EmailAccount $account, array $recipient, array $emailData, ?\App\Models\Email $sourceEmail = null): void
    {
        $mailer->send([], [], function (Message $message) use ($account, $recipient, $emailData, $sourceEmail) {
            $message->from($account->email, $account->name ?? '');
            $message->to($recipient['email'], $recipient['name'] ?? '');
            $message->subject($emailData['subject'] ?? '');
            $message->html($emailData['body'] ?? '');

            if (! empty($emailData['body'])) {
                $message->text(strip_tags($emailData['body']));
            }

            // Attachments
            if ($sourceEmail) {
                foreach ($sourceEmail->getMedia('attachments') as $media) {
                    $message->attach($media->getPath(), [
                        'as' => $media->file_name,
                        'mime' => $media->mime_type,
                    ]);
                }
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

        $dsn = new Dsn(
            $encryption === 'ssl' ? 'smtps' : 'smtp',
            $account->smtp_host,
            $account->username ?? $account->email,
            $account->password,
            $account->smtp_port
        );

        $factory = new EsmtpTransportFactory;
        $transport = $factory->create($dsn);

        return new \Illuminate\Mail\Mailer(
            'dynamic',
            app(\Illuminate\Contracts\View\Factory::class),
            $transport,
            app('events')
        );
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[SendBulkEmailJob] Batch failed', [
            'batch' => $this->batchIndex + 1,
            'total_batches' => $this->totalBatches,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get tags for Horizon.
     */
    public function tags(): array
    {
        return [
            'email',
            'bulk-send',
            'user:'.$this->userId,
            'batch:'.($this->batchIndex + 1).'/'.$this->totalBatches,
        ];
    }
}
