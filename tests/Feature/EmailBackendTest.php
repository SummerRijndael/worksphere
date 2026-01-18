<?php

namespace Tests\Feature;

use App\Enums\EmailFolderType;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailBackendTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected EmailAccount $emailAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->emailAccount = EmailAccount::factory()->create([
            'user_id' => $this->user->id,
            'is_active' => true,
            'is_verified' => true,
        ]);

        // Mock email config to use array driver or similar for testing?
        // Actually, we use 'dynamic' mailer in production, but tests usually use 'array' driver.
        // Our SendEmailJob uses a dynamically created mailer, so queue/job testing is better.
    }

    public function test_can_list_emails()
    {
        Email::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'email_account_id' => $this->emailAccount->id,
            'folder' => EmailFolderType::Inbox->value,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/emails');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_send_email()
    {
        \Illuminate\Support\Facades\Queue::fake();

        $response = $this->actingAs($this->user)
            ->postJson('/api/emails', [
                'account_id' => $this->emailAccount->id,
                'to' => [['email' => 'test@example.com', 'name' => 'Test']],
                'subject' => 'Test Subject',
                'body' => '<p>Test Body</p>',
            ]);

        $response->assertCreated();

        // Assert email created in DB
        $this->assertDatabaseHas('emails', [
            'subject' => 'Test Subject',
            'user_id' => $this->user->id,
            'folder' => EmailFolderType::Sent->value,
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendEmailJob::class);
    }

    public function test_can_create_custom_folder()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/emails/folders', [
                'name' => 'My Custom Folder',
                'color' => '#ff0000',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('email_folders', [
            'name' => 'My Custom Folder',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_manage_signatures()
    {
        // specific test logic for signatures
        $response = $this->actingAs($this->user)
            ->postJson('/api/emails/signatures', [
                'name' => 'Work',
                'content' => 'My Signature',
                'is_default' => true,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('email_signatures', [
            'name' => 'Work',
            'is_default' => true,
        ]);
    }
}
