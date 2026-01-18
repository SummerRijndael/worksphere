<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RestoreFlaggedChat extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:restore {chat_id : The ID of the chat to restore}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore a group chat marked for deletion';

    /**
     * Execute the console command.
     */
    public function handle(\App\Services\Chat\GroupChatService $service)
    {
        $chatId = $this->argument('chat_id');

        $chat = \App\Models\Chat\Chat::find($chatId);

        if (! $chat) {
            $this->error("Chat with ID {$chatId} not found.");

            return 1;
        }

        if (! $chat->marked_for_deletion_at) {
            $this->info("Chat '{$chat->name}' is not marked for deletion.");

            return 0;
        }

        if ($service->restoreMarkedGroup($chat)) {
            $this->info("Chat '{$chat->name}' ({$chat->public_id}) restored successfully.");
        } else {
            // In case void method but we want to confirm
            // Service method is void.
            $service->restoreMarkedGroup($chat);
            $this->info("Chat '{$chat->name}' ({$chat->public_id}) restored successfully.");
        }

        return 0;
    }
}
