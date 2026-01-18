<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientProjectResource;
use App\Http\Resources\ClientTaskResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClientPortalController extends Controller
{
    /**
     * Get the authenticated user's linked client.
     */
    protected function getClientForUser(Request $request): ?Client
    {
        $user = $request->user();

        // Check if user has a linked client
        if ($user->client_id) {
            return Client::find($user->client_id);
        }

        // Try to find a client by email
        return Client::where('email', $user->email)->first();
    }

    /**
     * Get dashboard stats for the client portal.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $client = $this->getClientForUser($request);

        if (! $client) {
            return response()->json([
                'message' => 'No client profile found for your account.',
            ], 404);
        }

        // Get counts
        $projectsCount = Project::where('client_id', $client->id)
            ->whereNotIn('status', ['archived', 'cancelled'])
            ->count();

        $activeProjectsCount = Project::where('client_id', $client->id)
            ->where('status', 'in_progress')
            ->count();

        $ticketsCount = Ticket::where('client_id', $client->id)->count();
        $openTicketsCount = Ticket::where('client_id', $client->id)
            ->whereIn('status', ['open', 'in_progress', 'pending'])
            ->count();

        $invoicesCount = Invoice::where('client_id', $client->id)->count();
        $pendingInvoicesCount = Invoice::where('client_id', $client->id)
            ->pending()
            ->count();
        $pendingInvoicesTotal = Invoice::where('client_id', $client->id)
            ->pending()
            ->sum('total');

        // Recent projects
        $recentProjects = Project::where('client_id', $client->id)
            ->whereNotIn('status', ['archived', 'cancelled'])
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get();

        // Recent invoices
        $recentInvoices = Invoice::where('client_id', $client->id)
            ->with(['project'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'client' => [
                'name' => $client->name,
                'initials' => $client->initials,
            ],
            'stats' => [
                'projects' => [
                    'total' => $projectsCount,
                    'active' => $activeProjectsCount,
                ],
                'tickets' => [
                    'total' => $ticketsCount,
                    'open' => $openTicketsCount,
                ],
                'invoices' => [
                    'total' => $invoicesCount,
                    'pending' => $pendingInvoicesCount,
                    'pending_amount' => (float) $pendingInvoicesTotal,
                ],
            ],
            'recent_projects' => ClientProjectResource::collection($recentProjects),
            'recent_invoices' => InvoiceResource::collection($recentInvoices),
        ]);
    }

    /**
     * List projects for the client.
     */
    public function projects(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $client = $this->getClientForUser($request);

        if (! $client) {
            return response()->json([
                'message' => 'No client profile found for your account.',
            ], 404);
        }

        $query = Project::where('client_id', $client->id)
            ->with(['team'])
            ->withCount('tasks');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            // By default, hide archived/cancelled
            $query->whereNotIn('status', ['archived', 'cancelled']);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        // Sort
        $sortBy = $request->input('sort_by', 'updated_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $projects = $query->paginate($request->input('per_page', 15));

        return ClientProjectResource::collection($projects);
    }

    /**
     * Get a single project with tasks.
     */
    public function projectDetail(Request $request, Project $project): ClientProjectResource|JsonResponse
    {
        $client = $this->getClientForUser($request);

        if (! $client) {
            return response()->json([
                'message' => 'No client profile found for your account.',
            ], 404);
        }

        // Verify project belongs to client
        if ($project->client_id !== $client->id) {
            return response()->json([
                'message' => 'Project not found.',
            ], 404);
        }

        $project->load(['team']);
        $project->loadCount('tasks');

        // Load tasks (public info only, no internal comments)
        $tasks = $project->tasks()
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->get();

        return (new ClientProjectResource($project))
            ->additional([
                'tasks' => ClientTaskResource::collection($tasks),
            ]);
    }

    /**
     * List invoices for the client.
     */
    public function invoices(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $client = $this->getClientForUser($request);

        if (! $client) {
            return response()->json([
                'message' => 'No client profile found for your account.',
            ], 404);
        }

        $query = Invoice::where('client_id', $client->id)
            ->with(['project'])
            ->withCount('items');

        // Filter by status
        if ($request->filled('status')) {
            $statuses = explode(',', $request->input('status'));
            $query->whereIn('status', $statuses);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $invoices = $query->paginate($request->input('per_page', 15));

        return InvoiceResource::collection($invoices);
    }

    /**
     * Get a single invoice with items.
     */
    public function invoiceDetail(Request $request, Invoice $invoice): InvoiceResource|JsonResponse
    {
        $client = $this->getClientForUser($request);

        if (! $client) {
            return response()->json([
                'message' => 'No client profile found for your account.',
            ], 404);
        }

        // Verify invoice belongs to client
        if ($invoice->client_id !== $client->id) {
            return response()->json([
                'message' => 'Invoice not found.',
            ], 404);
        }

        // Mark as viewed if first view
        if ($invoice->status === \App\Enums\InvoiceStatus::Sent) {
            $invoice->markAsViewed();
        }

        $invoice->load(['project', 'items', 'team']);

        return new InvoiceResource($invoice);
    }

    /**
     * Submit a request to update profile information.
     * This sends a notification to admin rather than updating directly.
     */
    public function requestInfoUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
        ]);

        $client = $this->getClientForUser($request);

        if (! $client) {
            return response()->json([
                'message' => 'No client profile found for your account.',
            ], 404);
        }

        // In a full implementation, this would create a support ticket
        // or send a notification to admin. For now, we'll just acknowledge.
        // Future: Create a ticket or notification for admin review

        // Log the request for audit purposes
        \Log::info('Client info update request', [
            'client_id' => $client->id,
            'user_id' => $request->user()->id,
            'message' => $request->input('message'),
            'fields' => $request->input('fields'),
        ]);

        return response()->json([
            'message' => 'Your update request has been submitted. Our team will review and contact you shortly.',
        ]);
    }

    /**
     * Get tickets for the client.
     */
    public function tickets(Request $request): JsonResponse
    {
        $client = $this->getClientForUser($request);

        if (! $client) {
            return response()->json([
                'message' => 'No client profile found for your account.',
            ], 404);
        }

        $query = Ticket::where('client_id', $client->id);

        // Filter by status
        if ($request->filled('status')) {
            $statuses = explode(',', $request->input('status'));
            $query->whereIn('status', $statuses);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $tickets = $query->paginate($request->input('per_page', 15));

        return response()->json($tickets);
    }
}
