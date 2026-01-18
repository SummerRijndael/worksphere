<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Team;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected \App\Services\PermissionService $permissionService
    ) {}

    /**
     * List invoices for a team.
     */
    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        $this->authorizeTeamPermission($team, 'invoices.view');

        $query = Invoice::query()
            ->forTeam($team)
            ->with(['client', 'project', 'creator'])
            ->withCount('items');

        // Filter by status
        if ($request->has('status')) {
            $statuses = explode(',', $request->input('status'));
            $query->whereIn('status', $statuses);
        }

        // Filter by client
        if ($request->filled('client_id')) {
            $client = Client::where('public_id', $request->input('client_id'))->first();
            if ($client) {
                $query->where('client_id', $client->id);
            }
        }

        // Filter by project
        if ($request->filled('project_id')) {
            $project = Project::where('public_id', $request->input('project_id'))->first();
            if ($project) {
                $query->where('project_id', $project->id);
            }
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('issue_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('issue_date', '<=', $request->input('date_to'));
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $invoices = $query->paginate($request->input('per_page', 15));

        return InvoiceResource::collection($invoices);
    }

    /**
     * Get invoice stats for a team.
     */
    public function stats(Request $request, Team $team): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'invoices.view');

        $baseQuery = Invoice::query()->forTeam($team);

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'draft' => (clone $baseQuery)->draft()->count(),
            'sent' => (clone $baseQuery)->where('status', 'sent')->count(),
            'viewed' => (clone $baseQuery)->where('status', 'viewed')->count(),
            'paid' => (clone $baseQuery)->paid()->count(),
            'overdue' => (clone $baseQuery)->overdue()->count(),
            'total_outstanding' => (clone $baseQuery)->pending()->sum('total'),
            'total_paid_this_month' => (clone $baseQuery)
                ->paid()
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('total'),
        ];

        return response()->json($stats);
    }

    /**
     * Create a new invoice.
     */
    public function store(StoreInvoiceRequest $request, Team $team): InvoiceResource
    {
        $this->authorizeTeamPermission($team, 'invoices.create');

        $client = Client::where('public_id', $request->input('client_id'))->firstOrFail();

        $project = null;
        if ($request->filled('project_id')) {
            $project = Project::where('public_id', $request->input('project_id'))->first();
        }

        $invoice = $this->invoiceService->createInvoice(
            team: $team,
            client: $client,
            creator: $request->user(),
            data: $request->validated(),
            items: $request->input('items'),
            project: $project
        );

        $invoice->load(['client', 'project', 'creator', 'items']);

        return new InvoiceResource($invoice);
    }

    /**
     * Show a single invoice.
     */
    public function show(Team $team, Invoice $invoice): InvoiceResource
    {
        $this->authorizeTeamPermission($team, 'invoices.view');

        // Verify invoice belongs to team
        if ($invoice->team_id !== $team->id) {
            abort(404);
        }

        $invoice->load(['client', 'project', 'team', 'creator', 'items']);

        return new InvoiceResource($invoice);
    }

    /**
     * Update an invoice.
     */
    public function update(UpdateInvoiceRequest $request, Team $team, Invoice $invoice): InvoiceResource
    {
        $this->authorizeTeamPermission($team, 'invoices.update');

        // Verify invoice belongs to team
        if ($invoice->team_id !== $team->id) {
            abort(404);
        }

        $client = null;
        if ($request->filled('client_id')) {
            $client = Client::where('public_id', $request->input('client_id'))->first();
        }

        $project = null;
        if ($request->has('project_id')) {
            if ($request->filled('project_id')) {
                $project = Project::where('public_id', $request->input('project_id'))->first();
            }
        }

        $invoice = $this->invoiceService->updateInvoice(
            invoice: $invoice,
            data: $request->validated(),
            items: $request->input('items'),
            updatedBy: $request->user(),
            client: $client,
            project: $project
        );

        $invoice->load(['client', 'project', 'creator', 'items']);

        return new InvoiceResource($invoice);
    }

    /**
     * Delete an invoice.
     */
    public function destroy(Request $request, Team $team, Invoice $invoice): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'invoices.delete');

        // Verify invoice belongs to team
        if ($invoice->team_id !== $team->id) {
            abort(404);
        }

        $this->invoiceService->deleteInvoice($invoice, $request->user());

        return response()->json(['message' => 'Invoice deleted successfully.']);
    }

    /**
     * Send invoice to client.
     */
    public function send(Request $request, Team $team, Invoice $invoice): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'invoices.send');

        // Verify invoice belongs to team
        if ($invoice->team_id !== $team->id) {
            abort(404);
        }

        $request->validate([
            'email' => ['nullable', 'email'],
        ]);

        $success = $this->invoiceService->sendInvoice(
            invoice: $invoice,
            sentBy: $request->user(),
            email: $request->input('email')
        );

        if (! $success) {
            return response()->json([
                'message' => 'Invoice cannot be sent in its current status.',
            ], 422);
        }

        return response()->json([
            'message' => 'Invoice sent successfully.',
            'invoice' => new InvoiceResource($invoice->fresh(['client', 'project', 'creator', 'items'])),
        ]);
    }

    /**
     * Record a payment for the invoice.
     */
    public function recordPayment(Request $request, Team $team, Invoice $invoice): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'invoices.record_payment');

        // Verify invoice belongs to team
        if ($invoice->team_id !== $team->id) {
            abort(404);
        }

        $success = $this->invoiceService->recordPayment(
            invoice: $invoice,
            recordedBy: $request->user()
        );

        if (! $success) {
            return response()->json([
                'message' => 'Payment cannot be recorded for this invoice.',
            ], 422);
        }

        return response()->json([
            'message' => 'Payment recorded successfully.',
            'invoice' => new InvoiceResource($invoice->fresh(['client', 'project', 'creator', 'items'])),
        ]);
    }

    /**
     * Cancel an invoice.
     */
    public function cancel(Request $request, Team $team, Invoice $invoice): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'invoices.update');

        // Verify invoice belongs to team
        if ($invoice->team_id !== $team->id) {
            abort(404);
        }

        $success = $this->invoiceService->cancelInvoice(
            invoice: $invoice,
            cancelledBy: $request->user()
        );

        if (! $success) {
            return response()->json([
                'message' => 'Invoice cannot be cancelled.',
            ], 422);
        }

        return response()->json([
            'message' => 'Invoice cancelled successfully.',
            'invoice' => new InvoiceResource($invoice->fresh(['client', 'project', 'creator', 'items'])),
        ]);
    }

    /**
     * Download invoice PDF.
     */
    public function downloadPdf(Request $request, Team $team, Invoice $invoice)
    {
        $this->authorizeTeamPermission($team, 'invoices.view');

        // Verify invoice belongs to team
        if ($invoice->team_id !== $team->id) {
            abort(404);
        }

        $pdfPath = $this->invoiceService->getPdfPath($invoice);

        if (! Storage::disk('local')->exists($pdfPath)) {
            return response()->json(['message' => 'PDF not found.'], 404);
        }

        return Storage::disk('local')->download(
            $pdfPath,
            "Invoice-{$invoice->invoice_number}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * Regenerate invoice PDF.
     */
    public function regeneratePdf(Request $request, Team $team, Invoice $invoice): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'invoices.update');

        // Verify invoice belongs to team
        if ($invoice->team_id !== $team->id) {
            abort(404);
        }

        $this->invoiceService->generatePdf($invoice);

        return response()->json([
            'message' => 'PDF regenerated successfully.',
        ]);
    }

    /**
     * Authorize team permission.
     */
    protected function authorizeTeamPermission(Team $team, string $permission): void
    {
        $user = request()->user();

        if (! $this->permissionService->hasTeamPermission($user, $team, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}
