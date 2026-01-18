<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DebugTwilio extends Command
{
    protected $signature = 'debug:twilio';

    protected $description = 'Debug Twilio configuration and connectivity';

    public function handle()
    {
        $this->info('Debugging Twilio Configuration...');

        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $verifySid = config('services.twilio.verify_sid');

        $this->table(
            ['Config Key', 'Value (Masked)', 'Status'],
            [
                ['SID', $this->mask($sid), $sid ? 'Set' : 'Missing'],
                ['Token', $this->mask($token), $token ? 'Set' : 'Missing'],
                ['Verify SID', $this->mask($verifySid), $verifySid ? 'Set' : 'Missing'],
            ]
        );

        if (! $sid || ! $token) {
            $this->error('Twilio credentials are missing in config/services.php or .env');

            return 1;
        }

        $this->info('Attempting to connect to Twilio API...');

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");

            if ($response->successful()) {
                $data = $response->json();
                $this->info('✅ Connection Successful!');
                $this->line('Account Status: '.($data['status'] ?? 'Unknown'));
                $this->line('Account Type: '.($data['type'] ?? 'Unknown'));
            } else {
                $this->error('❌ Connection Failed');
                $this->error('HTTP Status: '.$response->status());
                $this->error('Response: '.$response->body());
            }

        } catch (\Exception $e) {
            $this->error('❌ Exception: '.$e->getMessage());
        }

        if ($verifySid) {
            $this->info("\nChecking Verify Service...");
            try {
                $response = Http::withBasicAuth($sid, $token)
                    ->get("https://verify.twilio.com/v2/Services/{$verifySid}");

                if ($response->successful()) {
                    $data = $response->json();
                    $this->info('✅ Verify Service Found!');
                    $this->line('Friendly Name: '.($data['friendly_name'] ?? 'Unknown'));
                } else {
                    $this->error('❌ Verify Service Check Failed');
                    $this->error('HTTP Status: '.$response->status());
                    $this->error('Response: '.$response->body());
                }
            } catch (\Exception $e) {
                $this->error('❌ Exception: '.$e->getMessage());
            }
        }

        return 0;
    }

    protected function mask($string)
    {
        if (! $string) {
            return 'IGNORE';
        }
        if (strlen($string) < 8) {
            return str_repeat('*', strlen($string));
        }

        return substr($string, 0, 4).'...'.substr($string, -4);
    }
}
