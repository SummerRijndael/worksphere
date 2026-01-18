<?php

namespace Tests\Feature\Email;

use App\Enums\EmailSyncStatus;
use App\Events\Email\SyncStatusChanged;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\EmailSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SyncStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_status_changed_event_broadcasts_on_completion()
    {
        Event::fake([SyncStatusChanged::class]);

        $user = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);

        $service = app(EmailSyncService::class);
        $service->markSyncCompleted($account);

        Event::assertDispatched(SyncStatusChanged::class, function ($e) use ($account) {
            $channels = $e->broadcastOn();

            return $channels[0]->name === "private-email-account.{$account->public_id}"
                && $e->account->id === $account->id
                && $e->status === EmailSyncStatus::Completed->value;
        });
    }

    public function test_sync_status_changed_event_broadcasts_on_failure()
    {
        Event::fake([SyncStatusChanged::class]);

        $user = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);

        $service = app(EmailSyncService::class);
        $service->markSyncFailed($account, 'Connection timed out');

        Event::assertDispatched(SyncStatusChanged::class, function ($e) use ($account) {
            $channels = $e->broadcastOn();

            return $channels[0]->name === "private-email-account.{$account->public_id}"
                && $e->account->id === $account->id
                && $e->status === EmailSyncStatus::Failed->value
                && $e->error === 'Connection timed out';
        });
    }
}
