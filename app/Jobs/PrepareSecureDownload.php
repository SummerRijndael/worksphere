<?php

namespace App\Jobs;

use App\Mail\BackupDownloadPassword;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage; // Correct facade
use Illuminate\Support\Str;

class PrepareSecureDownload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600; // 10 minutes should be enough for zipping

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected User $user,
        protected array $paths,
        protected string $reason
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Preparing secure download', ['user_id' => $this->user->id, 'file_count' => count($this->paths)]);

        $diskName = config('backup.backup.destination.disks')[0] ?? 'local';
        $disk = Storage::disk($diskName);

        $tempDir = storage_path('app/temp/secure-downloads');
        if (! File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $password = Str::password(16, true, true, false, false);
        $zipName = 'secure-backup-'.time().'-'.Str::random(6).'.zip';
        $zipPath = $tempDir.'/'.$zipName;

        $filesToZip = [];
        foreach ($this->paths as $path) {
            if (! $disk->exists($path)) {
                continue;
            }

            // Copy to temp
            $tempFile = $tempDir.'/'.basename($path);
            file_put_contents($tempFile, $disk->get($path));
            $filesToZip[] = basename($path);
        }

        if (empty($filesToZip)) {
            Log::warning('No valid files found for secure download', ['paths' => $this->paths]);

            return;
        }

        // Zip Logic
        $fileList = array_map('escapeshellarg', $filesToZip);
        $fileListStr = implode(' ', $fileList);

        // Using exec zip (assuming Linux environment as per user info)
        $cmd = 'cd '.escapeshellarg($tempDir).' && zip -P '.escapeshellarg($password).' -j '.escapeshellarg($zipName).' '.$fileListStr;

        exec($cmd, $output, $returnVar);

        // Cleanup temp files immediately
        foreach ($filesToZip as $f) {
            @unlink($tempDir.'/'.$f);
        }

        if ($returnVar !== 0) {
            Log::error('Zip creation failed', ['output' => $output]);
            throw new \Exception('Failed to create secure zip archive.');
        }

        // 1. Send Email to requester
        Mail::to($this->user)->send(new BackupDownloadPassword($password, $this->reason, count($this->paths)));

        // 2. Notify Admins
        $admins = User::role('administrator')->get();
        if ($admins->count() > 0) {
            Notification::send($admins, new SystemNotification(
                'security',
                'Backup Downloaded',
                "User {$this->user->name} downloaded a secure backup with ".count($this->filesToZip ?? $this->paths)." files.\nReason: {$this->reason}",
                null,
                null,
                ['user_id' => $this->user->id, 'reason' => $this->reason]
            ));
        }

        Log::info('Secure download prepared and notifications sent.');
    }
}
