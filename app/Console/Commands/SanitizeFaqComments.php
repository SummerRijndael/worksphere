<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SanitizeFaqComments extends Command
{
    protected $signature = 'faq:sanitize-comments';

    protected $description = 'Sanitize existing FAQ comments by stripping HTML tags';

    public function handle()
    {
        $this->info('Sanitizing FAQ comments...');

        $comments = \App\Models\FaqComment::all();
        $count = 0;

        foreach ($comments as $comment) {
            $originalContent = $comment->content;
            $originalName = $comment->name;

            $sanitizedContent = strip_tags($originalContent);
            $sanitizedName = $originalName ? strip_tags($originalName) : null;

            if ($originalContent !== $sanitizedContent || $originalName !== $sanitizedName) {
                $comment->content = $sanitizedContent;
                $comment->name = $sanitizedName;
                $comment->save();
                $count++;
                $this->line("  Sanitized comment ID: {$comment->id}");
            }
        }

        $this->info("Done! Sanitized {$count} comments.");

        return Command::SUCCESS;
    }
}
