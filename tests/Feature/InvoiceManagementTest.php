<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected Team $team;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.async' => false]);

        // Create permissions
        $permissions = [
            'invoices.view',
            'invoices.create',
            'invoices.update',
            'invoices.delete',
            'invoices.send',
            'invoices.record_payment',
            'teams.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create admin role with all invoice permissions
        $adminRole = Role::firstOrCreate(['name' => 'invoice_admin']);
        $adminRole->givePermissionTo($permissions);

        // Create member role with view only
        $memberRole = Role::firstOrCreate(['name' => 'invoice_viewer']);
        $memberRole->givePermissionTo(['invoices.view', 'teams.view']);

        // Create team
        $this->team = Team::factory()->create();

        // Create admin user
        $this->admin = User::factory()->create();
        $this->admin->assignRole('invoice_admin');
        $this->team->addMember($this->admin, 'owner');

        // Create member user
        $this->member = User::factory()->create();
        $this->member->assignRole('invoice_viewer');
        $this->team->addMember($this->member, 'member');

        // Create client
        $this->client = Client::factory()->create();
    }

    public function test_admin_can_list_invoices(): void
    {
        Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->count(3)
            ->create();

        $response = $this->actingAs($this->admin)
            ->getJson("/api/teams/{$this->team->public_id}/invoices");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_create_invoice(): void
    {
        $data = [
            'client_id' => $this->client->public_id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_rate' => 10,
            'notes' => 'Test invoice notes',
            'items' => [
                [
                    'description' => 'Web Development',
                    'quantity' => 10,
                    'unit_price' => 100,
                ],
                [
                    'description' => 'Design Work',
                    'quantity' => 5,
                    'unit_price' => 80,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices", $data);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.notes', 'Test invoice notes');

        // Verify totals calculated correctly
        // Subtotal: (10*100) + (5*80) = 1000 + 400 = 1400
        // Tax: 1400 * 0.10 = 140
        // Total: 1540
        $this->assertDatabaseHas('invoices', [
            'team_id' => $this->team->id,
            'subtotal' => 1400.00,
            'tax_amount' => 140.00,
            'total' => 1540.00,
        ]);

        // Verify items created
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public function test_admin_can_view_invoice(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->create();

        InvoiceItem::factory()->count(2)->forInvoice($invoice)->create();

        $response = $this->actingAs($this->admin)
            ->getJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.public_id', $invoice->public_id)
            ->assertJsonPath('data.invoice_number', $invoice->invoice_number);
    }

    public function test_admin_can_update_draft_invoice(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->draft()
            ->create();

        InvoiceItem::factory()->forInvoice($invoice)->create([
            'description' => 'Original Item',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        $data = [
            'notes' => 'Updated notes',
            'items' => [
                [
                    'description' => 'New Item',
                    'quantity' => 2,
                    'unit_price' => 200,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->putJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}", $data);

        $response->assertStatus(200)
            ->assertJsonPath('data.notes', 'Updated notes');

        // Verify old item deleted, new item created
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoice->id,
            'description' => 'New Item',
        ]);
    }

    public function test_cannot_update_sent_invoice(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->sent()
            ->create();

        $data = [
            'notes' => 'Trying to update',
        ];

        $response = $this->actingAs($this->admin)
            ->putJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}", $data);

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_invoice(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
    }

    public function test_admin_can_send_invoice(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->draft()
            ->create();

        // Add at least one item (required to send)
        InvoiceItem::factory()->forInvoice($invoice)->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}/send");

        $response->assertStatus(200)
            ->assertJsonPath('invoice.status', 'sent');

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_cannot_send_invoice_without_items(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->draft()
            ->create();

        // No items added

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}/send");

        $response->assertStatus(403); // can_send returns false
    }

    public function test_admin_can_record_payment(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->sent()
            ->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}/record-payment");

        $response->assertStatus(200)
            ->assertJsonPath('invoice.status', 'paid');

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_cannot_record_payment_for_draft_invoice(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->draft()
            ->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}/record-payment");

        $response->assertStatus(403); // can_record_payment returns false
    }

    public function test_admin_can_cancel_invoice(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->sent()
            ->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('invoice.status', 'cancelled');
    }

    public function test_cannot_cancel_paid_invoice(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->paid()
            ->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}/cancel");

        $response->assertStatus(403);
    }

    public function test_member_can_view_invoices(): void
    {
        Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->count(2)
            ->create();

        $response = $this->actingAs($this->member)
            ->getJson("/api/teams/{$this->team->public_id}/invoices");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_member_cannot_create_invoice(): void
    {
        $data = [
            'client_id' => $this->client->public_id,
            'items' => [
                ['description' => 'Test', 'quantity' => 1, 'unit_price' => 100],
            ],
        ];

        $response = $this->actingAs($this->member)
            ->postJson("/api/teams/{$this->team->public_id}/invoices", $data);

        $response->assertStatus(403);
    }

    public function test_non_team_member_cannot_access_invoices(): void
    {
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)
            ->getJson("/api/teams/{$this->team->public_id}/invoices");

        $response->assertStatus(403);
    }

    public function test_invoice_stats_endpoint(): void
    {
        // Create various invoices
        Invoice::factory()->forTeam($this->team)->forClient($this->client)
            ->createdBy($this->admin)->draft()->count(2)->create();
        Invoice::factory()->forTeam($this->team)->forClient($this->client)
            ->createdBy($this->admin)->sent()->count(3)->create();
        Invoice::factory()->forTeam($this->team)->forClient($this->client)
            ->createdBy($this->admin)->paid()->count(1)->create();

        $response = $this->actingAs($this->admin)
            ->getJson("/api/teams/{$this->team->public_id}/invoices/stats");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'draft',
                'sent',
                'paid',
                'overdue',
                'total_outstanding',
                'total_paid_this_month',
            ])
            ->assertJsonPath('total', 6)
            ->assertJsonPath('draft', 2)
            ->assertJsonPath('sent', 3)
            ->assertJsonPath('paid', 1);
    }

    public function test_invoice_filters_work(): void
    {
        // Draft invoices
        Invoice::factory()->forTeam($this->team)->forClient($this->client)
            ->createdBy($this->admin)->draft()->count(2)->create();

        // Paid invoices
        Invoice::factory()->forTeam($this->team)->forClient($this->client)
            ->createdBy($this->admin)->paid()->count(3)->create();

        // Filter by status
        $response = $this->actingAs($this->admin)
            ->getJson("/api/teams/{$this->team->public_id}/invoices?status=draft");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Filter by paid status
        $response = $this->actingAs($this->admin)
            ->getJson("/api/teams/{$this->team->public_id}/invoices?status=paid");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_invoice_linked_to_project(): void
    {
        $project = Project::factory()->forTeam($this->team)->create([
            'client_id' => $this->client->id,
        ]);

        $data = [
            'client_id' => $this->client->public_id,
            'project_id' => $project->public_id,
            'items' => [
                ['description' => 'Project Work', 'quantity' => 1, 'unit_price' => 500],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices", $data);

        $response->assertStatus(201);

        $this->assertDatabaseHas('invoices', [
            'project_id' => $project->id,
            'client_id' => $this->client->id,
        ]);
    }

    public function test_invoice_number_auto_generated(): void
    {
        $data = [
            'client_id' => $this->client->public_id,
            'items' => [
                ['description' => 'Test', 'quantity' => 1, 'unit_price' => 100],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->postJson("/api/teams/{$this->team->public_id}/invoices", $data);

        $response->assertStatus(201);

        $invoiceNumber = $response->json('data.invoice_number');
        $this->assertNotEmpty($invoiceNumber);
        $this->assertStringStartsWith('INV-', $invoiceNumber);
    }

    public function test_invoice_totals_recalculated_on_item_update(): void
    {
        $invoice = Invoice::factory()
            ->forTeam($this->team)
            ->forClient($this->client)
            ->createdBy($this->admin)
            ->draft()
            ->create(['tax_rate' => 0, 'discount_amount' => 0]);

        InvoiceItem::factory()->forInvoice($invoice)->create([
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
        ]);

        $invoice->recalculateTotals();
        $this->assertEquals(100, $invoice->subtotal);
        $this->assertEquals(100, $invoice->total);

        // Update with new items
        $data = [
            'items' => [
                ['description' => 'New Item', 'quantity' => 2, 'unit_price' => 150],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->putJson("/api/teams/{$this->team->public_id}/invoices/{$invoice->public_id}", $data);

        $response->assertStatus(200);

        $invoice->refresh();
        $this->assertEquals(300, $invoice->subtotal);
        $this->assertEquals(300, $invoice->total);
    }
}
