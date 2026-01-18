<?php

namespace Database\Factories;

use App\Enums\EmailFolderType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EmailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'folder' => EmailFolderType::Inbox->value,
            'from_email' => $this->faker->email(),
            'from_name' => $this->faker->name(),
            'to' => [['email' => $this->faker->email(), 'name' => $this->faker->name()]],
            'subject' => $this->faker->sentence(),
            'preview' => $this->faker->text(100),
            'body_html' => '<p>'.$this->faker->paragraph().'</p>',
            'body_plain' => $this->faker->paragraph(),
            'is_read' => $this->faker->boolean(),
            'is_starred' => $this->faker->boolean(),
            'is_draft' => false,
            'has_attachments' => false,
            'sent_at' => now(),
            'received_at' => now(),
        ];
    }
}
