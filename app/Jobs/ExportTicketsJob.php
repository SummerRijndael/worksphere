<?php

namespace App\Jobs;

use App\Contracts\TicketServiceContract;
use App\Exports\TicketExport;
use App\Models\User;
use App\Notifications\ExportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ExportTicketsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public array $filters
    ) {}

    public function handle(): void
    {
        // Apply scope logic here to ensure security
        if (! $this->user->hasPermissionTo('tickets.view') && ! $this->user->hasRole('administrator')) {
            $this->filters['for_user'] = $this->user;
        }

        $filename = 'exports/tickets-'.now()->timestamp.'-'.Str::random(8).'.xlsx';

        // Instantiate Export with service dependency
        $export = new TicketExport(
            $this->filters,
            app(TicketServiceContract::class)
        );

        // Store file (using 'public' disk usually linked to storage/app/public)
        Excel::store($export, $filename, 'public');

        // Generate URL
        $url = Storage::disk('public')->url($filename);

        // Notify user
        $this->user->notify(new ExportReady($url, 'Ticket Report'));
    }
}
