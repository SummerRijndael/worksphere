<?php

namespace Tests\Feature;

use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailPruningVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_clears_body_and_deletes_files()
    {
        Storage::fake('private');
        $user = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        
        $email = Email::create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'subject' => 'Test Pruning',
            'from_email' => 'sender@example.com',
            'body_html' => '<p>Sensitive data</p>',
            'body_plain' => 'Sensitive data',
            'folder' => 'inbox',
            'to' => [['email' => 'test@example.com']],
        ]);

        // Add attachment
        $file = UploadedFile::fake()->create('contract.pdf', 100);
        $media = $email->addMedia($file)->toMediaCollection('attachments');
        $filePath = $media->getPath();

        $this->assertTrue(file_exists($filePath));
        $this->assertNotNull($email->body_html);

        // Prune
        $email->prune();
        $email->refresh();

        $this->assertNull($email->body_html);
        $this->assertNull($email->body_plain);
        $this->assertFalse(file_exists($filePath));
        $this->assertCount(0, $email->getMedia('attachments'));
    }

    public function test_delete_from_trash_triggers_prune()
    {
        Storage::fake('private');
        $user = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        
        $email = Email::create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'subject' => 'Test Trash Pruning',
            'from_email' => 'sender@example.com',
            'body_html' => '<p>Trash data</p>',
            'folder' => 'trash',
            'to' => [['email' => 'test@example.com']],
        ]);

        $file = UploadedFile::fake()->create('junk.txt', 10);
        $media = $email->addMedia($file)->toMediaCollection('attachments');
        $filePath = $media->getPath();

        app(EmailService::class)->delete($email);

        // It's hard deleted because it was in trash
        $this->assertDatabaseMissing('emails', ['id' => $email->id]);
        $this->assertFalse(file_exists($filePath));
    }
}
