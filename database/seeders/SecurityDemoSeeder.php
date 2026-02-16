<?php

namespace Database\Seeders;

use App\Models\SuspiciousActivity;
use App\Models\User;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use Akaunting\Firewall\Models\Log as FirewallLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\DB;

class SecurityDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        $users = User::all();

        if ($users->isEmpty()) {
            $this->command->error('No users found. Please seed users first.');
            return;
        }
        
        $this->command->info('Cleaning existing security logs...');
        DB::table('suspicious_activities')->truncate();
        DB::table('firewall_logs')->truncate();

        $this->command->info('Generating global security threat data...');

        $countries = [
            ['code' => 'US', 'name' => 'United States', 'city' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060],
            ['code' => 'GB', 'name' => 'United Kingdom', 'city' => 'London', 'lat' => 51.5074, 'lng' => -0.1278],
            ['code' => 'DE', 'name' => 'Germany', 'city' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050],
            ['code' => 'CN', 'name' => 'China', 'city' => 'Beijing', 'lat' => 39.9042, 'lng' => 116.4074],
            ['code' => 'BR', 'name' => 'Brazil', 'city' => 'São Paulo', 'lat' => -23.5505, 'lng' => -46.6333],
            ['code' => 'RU', 'name' => 'Russia', 'city' => 'Moscow', 'lat' => 55.7558, 'lng' => 37.6173],
            ['code' => 'AU', 'name' => 'Australia', 'city' => 'Sydney', 'lat' => -33.8688, 'lng' => 151.2093],
            ['code' => 'IN', 'name' => 'India', 'city' => 'Mumbai', 'lat' => 19.0760, 'lng' => 72.8777],
            ['code' => 'FR', 'name' => 'France', 'city' => 'Paris', 'lat' => 48.8566, 'lng' => 2.3522],
            ['code' => 'JP', 'name' => 'Japan', 'city' => 'Tokyo', 'lat' => 35.6762, 'lng' => 139.6503],
            ['code' => 'SG', 'name' => 'Singapore', 'city' => 'Singapore', 'lat' => 1.3521, 'lng' => 103.8198],
            ['code' => 'CA', 'name' => 'Canada', 'city' => 'Toronto', 'lat' => 43.6532, 'lng' => -79.3832],
        ];

        $middlewares = ['sql', 'xss', 'lfi', 'rfi', 'php', 'geo', 'agent'];

        // Helper to get a random date within a specific period
        $getRandomDate = function($period) {
            return match($period) {
                '24h' => now()->subHours(rand(0, 23))->subMinutes(rand(0, 59)),
                '1w' => now()->subDays(rand(1, 6))->subHours(rand(0, 23)),
                '1m' => now()->subWeeks(rand(1, 3))->subDays(rand(0, 6)),
                '3m' => now()->subMonths(rand(1, 2))->subWeeks(rand(0, 3)),
                '6m' => now()->subMonths(rand(3, 5))->subWeeks(rand(0, 3)),
                '1y' => now()->subMonths(rand(6, 11))->subWeeks(rand(0, 3)),
            };
        };

        $periods = ['24h', '1w', '1m', '3m', '6m', '1y'];

        foreach ($periods as $period) {
            $this->command->info("Seeding data for period: $period");
            
            for ($i = 0; $i < 30; $i++) {
                try {
                    $country = $faker->randomElement($countries);
                    $createdAt = $getRandomDate($period);
                    $ip = $faker->ipv4;

                    // 1. Firewall Logs (Map markers & Intensity)
                    FirewallLog::create([
                        'ip' => $ip,
                        'level' => 'warning',
                        'middleware' => $faker->randomElement($middlewares),
                        'user_id' => $faker->boolean(10) ? $users->random()->id : null,
                        'url' => '/api/v1/resource/' . Str::random(8),
                        'referrer' => $faker->url,
                        'request' => json_encode(['payload' => Str::random(100)]),
                        'created_at' => $createdAt,
                    ]);

                    // 2. Suspicious Activity (Top Offenders)
                    SuspiciousActivity::updateOrCreate(
                        [
                            'ip_address' => $ip,
                            'type' => $faker->randomElement(['Brute Force', 'SQL Injection', 'XSS Attempt', 'Port Scan']),
                        ],
                        [
                            'count' => rand(5, 50),
                            'country_code' => $country['code'],
                            'country_name' => $country['name'],
                            'city' => $country['city'],
                            'latitude' => $country['lat'],
                            'longitude' => $country['lng'],
                            'last_observed_at' => $createdAt,
                            'status' => 'active',
                        ]
                    );

                    // 3. Security Audit Logs (Activity Feed)
                    $user = $faker->boolean(70) ? $users->random() : null;
                    AuditLog::create([
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $user?->id,
                        'user_name' => $user?->name ?? 'Guest',
                        'user_email' => $user?->email ?? $faker->email,
                        'action' => $faker->randomElement([AuditAction::LoginFailed, AuditAction::RateLimitExceeded, AuditAction::AccountBanned]),
                        'category' => AuditCategory::Security,
                        'severity' => AuditSeverity::Warning,
                        'metadata' => [
                            'reason' => $faker->randomElement(['Incorrect Password', 'User Not Found', 'Blocked Location', 'Too Many Requests']),
                            'ip' => $ip,
                            'country' => $country['name'],
                        ],
                        'ip_address' => $ip,
                        'user_agent' => $faker->userAgent,
                        'url' => '/login',
                        'method' => 'POST',
                        'created_at' => $createdAt,
                    ]);
                } catch (\Exception $e) {
                    $this->command->error("Failed at $period iteration $i: " . $e->getMessage());
                    throw $e;
                }
            }
        }

        $this->command->info('Security map and dashboard seeded with realistic threat data!');
    }
}
