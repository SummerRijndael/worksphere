<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\Ticket;
use App\Models\User;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\AuditSeverity;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class DemoDataSeeder extends Seeder
{
    protected $faker;
    protected $adminRole;
    protected $itRole;
    protected $userRole;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->faker = Faker::create();
        
        $this->command->info('Starting Workspace Demo Data Seeding...');

        // 1. Create Users
        $this->seedUsers();

        // 2. Create Teams
        $this->seedTeams();

        // 3. Create Clients & Projects
        $this->seedClientsAndProjects();

        // 4. Create Tickets
        $this->seedTickets();

        // 5. Enhance Chat (Optional: call existing but modified)
        $this->command->call(ChatStabilitySeeder::class);

        $this->command->info('Demo Data Seeding Complete!');
    }

    protected function seedUsers(): void
    {
        $this->command->info('Seeding 250 users...');

        // 15 Admins
        for ($i = 0; $i < 15; $i++) {
            $user = User::updateOrCreate(
                ['email' => "admin{$i}@example.com"],
                [
                    'name' => "Admin User {$i}",
                    'username' => "admin{$i}",
                    'password' => Hash::make('Password123!'),
                    'email_verified_at' => now(),
                ]
            );
            $user->assignRole('administrator');
            $this->logAction($user, AuditAction::Created, AuditCategory::UserManagement, $user, ['role' => 'administrator']);
        }

        // 35 IT Support
        for ($i = 0; $i < 35; $i++) {
            $user = User::updateOrCreate(
                ['email' => "it{$i}@example.com"],
                [
                    'name' => "IT Support {$i}",
                    'username' => "it_support{$i}",
                    'password' => Hash::make('Password123!'),
                    'email_verified_at' => now(),
                ]
            );
            $user->assignRole('it_support');
            $this->logAction($user, AuditAction::Created, AuditCategory::UserManagement, $user, ['role' => 'it_support']);
        }

        // 200 regular Users
        for ($i = 0; $i < 200; $i++) {
            $user = User::updateOrCreate(
                ['email' => "user{$i}@example.com"],
                [
                    'name' => $this->faker->name,
                    'username' => "user{$i}",
                    'password' => Hash::make('Password123!'),
                    'email_verified_at' => now(),
                    'created_at' => now()->subDays(rand(1, 365)),
                ]
            );
            $user->assignRole('user');
        }
    }

    protected function seedTeams(): void
    {
        $teamNames = [
            'SkyNet Infrastructure', 'Quantum DevOps', 'Cyber Sentinel Security',
            'Blue Horizon Customer Success', 'Vanguard Product Team', 'Legacy Ops Maintenance',
            'Nova Growth Marketing', 'Titan Analytics', 'Phoenix Recovery Unit',
            'Apollo Creative Studio', 'Eagle Eye Quality Assurance', 'Hermes Logistics Tech',
            'Aura UX Design', 'Zenith Cloud Services', 'Pulse Realtime Systems'
        ];

        $this->command->info('Seeding teams and assigning roles...');

        $users = User::all();

        foreach ($teamNames as $name) {
            $team = Team::updateOrCreate(
                ['name' => $name],
                [
                    'slug' => Str::slug($name),
                    'description' => $this->faker->sentence(),
                    'owner_id' => $users->random()->id,
                    'status' => 'active',
                    'created_at' => now()->subMonths(12),
                ]
            );

            if ($team->roles()->count() === 0) {
                $team->createDefaultRoles();
            }

            // Assign members (avg 10-15 per team)
            if ($team->members()->count() < 5) {
                $memberCount = rand(10, 15);
                $teamMembers = $users->random($memberCount);

                foreach ($teamMembers as $index => $member) {
                    $roleSlug = 'operator';
                    if ($index === 0) $roleSlug = 'team_lead';
                    elseif ($index === 1) $roleSlug = 'subject_matter_expert';
                    elseif ($index === 2) $roleSlug = 'quality_assessor';

                    $team->addMember($member, $roleSlug);
                }
            }
        }
    }

    protected function seedClientsAndProjects(): void
    {
        $clientNames = [
            'Stark Industries', 'Wayne Enterprises', 'Umbrella Corp', 'Cyberdyne Systems',
            'Initech', 'Globex Corporation', 'Hooli', 'Pied Piper', 'E-corp', 'Massive Dynamic',
            'Weyland-Yutani', 'Tyrell Corporation', 'Oscorp', 'LexCorp', 'Blue Sun',
            'Veridian Dynamics', 'Aperture Science', 'Black Mesa', 'Abstergo', 'Anster',
            'Wonka Industries', 'Gringotts', 'Daily Planet', 'Vandelay Industries',
            'Dunder Mifflin', 'Saber', 'Pritchett\'s Closets', 'Acme Corp', 'Soylent Corp',
            'MomCorp', 'Zuckerberg Space', 'Nakatomi Plaza', 'Sherwin-Williams (Fake)'
        ];

        $this->command->info('Seeding 30+ clients, projects, and 100+ invoices...');

        $teams = Team::all();

        foreach ($clientNames as $clientName) {
            $client = Client::updateOrCreate(
                ['name' => $clientName],
                [
                    'email' => strtolower(str_replace([' ', '\''], '', $clientName)) . '@example.com',
                    'contact_person' => $this->faker->name,
                    'phone' => $this->faker->phoneNumber,
                    'address' => $this->faker->address,
                    'status' => 'active',
                    'created_at' => now()->subMonths(12),
                ]
            );

            // Projects per client
            $projectCount = rand(1, 3);
            for ($i = 0; $i < $projectCount; $i++) {
                $team = $teams->random();
                
                // Assign the first team found to the client
                if ($i === 0) {
                    $client->update(['team_id' => $team->id]);
                }

                $status = $this->faker->randomElement(ProjectStatus::cases());
                $projectName = $this->faker->catchPhrase();
                $project = Project::updateOrCreate(
                    ['team_id' => $team->id, 'name' => $projectName],
                    [
                        'client_id' => $client->id,
                        'description' => $this->faker->paragraph(),
                        'status' => $status,
                        'priority' => $this->faker->randomElement(ProjectPriority::cases()),
                        'start_date' => now()->subMonths(rand(1, 11)),
                        'due_date' => now()->addMonths(rand(1, 6)),
                        'created_by' => $team->owner_id,
                        'created_at' => now()->subMonths(12),
                    ]
                );

                // Assign members to project (from the team)
                $teamMembers = $team->members;
                if ($teamMembers->isNotEmpty()) {
                    $projectMemberCount = rand(min(3, $teamMembers->count()), min(8, $teamMembers->count()));
                    $projectMembers = $teamMembers->random($projectMemberCount);
                    
                    foreach ($projectMembers as $index => $member) {
                        $role = 'member';
                        if ($index === 0) $role = 'manager';
                        
                        // Use the relation to attach
                        if (!$project->members()->where('user_id', $member->id)->exists()) {
                            $project->members()->attach($member->id, [
                                'role' => $role,
                                'joined_at' => now()->subMonths(rand(1, 11)),
                            ]);
                        }
                    }
                }

                $this->seedTasks($project, $team);
                $this->seedInvoices($project, $client);
            }
        }
    }

    protected function seedTasks(Project $project, Team $team): void
    {
        // Use project members instead of team members for closer context
        $members = $project->members;
        if ($project->tasks()->count() >= 10) return;

        $taskCount = rand(10, 20);

        for ($i = 0; $i < $taskCount; $i++) {
            $assignee = $members->isEmpty() ? null : $members->random();
            $status = $this->faker->randomElement(TaskStatus::cases());
            
            $task = Task::create([
                'project_id' => $project->id,
                'title' => $this->faker->sentence(4),
                'description' => $this->faker->paragraph(),
                'status' => $status,
                'priority' => rand(1, 4),
                'assigned_to' => $assignee?->id,
                'created_by' => $team->owner_id,
                'due_date' => now()->addDays(rand(-30, 60)),
                'created_at' => now()->subMonths(rand(1, 10)),
            ]);

            // Status History
            if ($status !== TaskStatus::Open) {
                TaskStatusHistory::create([
                    'task_id' => $task->id,
                    'from_status' => TaskStatus::Open->value,
                    'to_status' => $status->value,
                    'changed_by' => $team->owner_id,
                    'created_at' => $task->created_at->addDays(rand(1, 5)),
                ]);
            }
        }
    }

    protected function seedInvoices(Project $project, Client $client): void
    {
        if ($project->invoices()->count() > 0) return;

        $invoiceCount = rand(1, 3);
        for ($i = 0; $i < $invoiceCount; $i++) {
            $status = $this->faker->randomElement([InvoiceStatus::Paid, InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Draft]);
            $issueDate = now()->subMonths(rand(1, 11));
            
            $invoice = Invoice::create([
                'team_id' => $project->team_id,
                'client_id' => $client->id,
                'project_id' => $project->id,
                'invoice_number' => 'INV-' . date('Ymd') . '-' . Str::upper(Str::random(6)),
                'status' => $status,
                'issue_date' => $issueDate,
                'due_date' => $issueDate->copy()->addDays(30),
                'currency' => 'USD',
                'subtotal' => 0,
                'tax_rate' => 10,
                'tax_amount' => 0,
                'total' => 0,
                'created_by' => $project->team->owner_id,
                'created_at' => $issueDate,
            ]);

            // Add Items
            $itemCount = rand(1, 5);
            for ($j = 0; $j < $itemCount; $j++) {
                $qty = rand(1, 10);
                $price = rand(50, 500);
                $total = $qty * $price;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $this->faker->words(3, true),
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'total' => $total,
                ]);
            }

            $invoice->calculateTotals();
            $invoice->save();

            if ($status === InvoiceStatus::Paid) {
                $invoice->update(['paid_at' => $invoice->issue_date->addDays(rand(1, 20))]);
            }
        }
    }

    protected function seedTickets(): void
    {
        if (Ticket::count() >= 100) return;

        $this->command->info('Seeding support tickets...');
        $users = User::all();
        $teams = Team::all();

        for ($i = 0; $i < 100; $i++) {
            $reporter = $users->random();
            $team = $teams->random();
            $assignee = $team->members->random();
            $status = $this->faker->randomElement(TicketStatus::cases());
            $type = $this->faker->randomElement(TicketType::cases());
            $createdAt = now()->subDays(rand(1, 365));

            Ticket::create([
                'title' => $this->faker->sentence(6),
                'description' => $this->faker->paragraph(),
                'status' => $status,
                'priority' => $this->faker->randomElement(TicketPriority::cases()),
                'type' => $type,
                'reporter_id' => $reporter->id,
                'assigned_to' => $assignee->id,
                'team_id' => $team->id,
                'created_at' => $createdAt,
                'resolved_at' => ($status === TicketStatus::Resolved) ? $createdAt->addDays(rand(1, 5)) : null,
                'closed_at' => ($status === TicketStatus::Closed) ? $createdAt->addDays(rand(6, 10)) : null,
            ]);
        }
    }

    protected function logAction(User $user, AuditAction $action, AuditCategory $category, Model $auditable, ?array $metadata = null, ?Carbon $createdAt = null): void
    {
        AuditLog::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'action' => $action,
            'category' => $category,
            'severity' => AuditSeverity::Info,
            'auditable_type' => get_class($auditable),
            'auditable_id' => $auditable->id,
            'metadata' => $metadata,
            'ip_address' => $this->faker->ipv4,
            'user_agent' => $this->faker->userAgent,
            'url' => '/api/v1/seeder',
            'method' => 'POST',
            'created_at' => $createdAt ?? now(),
        ]);
    }
}
