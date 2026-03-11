<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('meetings')
            ->select(['id', 'password'])
            ->whereNotNull('password')
            ->orderBy('id')
            ->chunkById(100, function ($meetings): void {
                foreach ($meetings as $meeting) {
                    $password = (string) $meeting->password;

                    if ($password === '' || Str::startsWith($password, ['$2y$', '$2b$', '$argon2i$', '$argon2id$'])) {
                        continue;
                    }

                    try {
                        Crypt::decryptString($password);
                        continue;
                    } catch (\Throwable) {
                        // Continue and encrypt legacy plaintext values.
                    }

                    DB::table('meetings')
                        ->where('id', $meeting->id)
                        ->update(['password' => Crypt::encryptString($password)]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible: encrypted values cannot be converted back to known plaintext safely.
    }
};
