<?php

namespace App\Exports;

use App\Contracts\TicketServiceContract;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TicketExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        protected array $filters,
        protected TicketServiceContract $ticketService
    ) {}

    public function query()
    {
        return $this->ticketService->getFilterQuery($this->filters);
    }

    public function headings(): array
    {
        return [
            'Ticket #',
            'Title',
            'Status',
            'Priority',
            'Type',
            'Reporter',
            'Assignee',
            'Team',
            'Created At',
            'Resolved At',
            'Closed At',
        ];
    }

    public function map($ticket): array
    {
        return [
            $ticket->ticket_number,
            $ticket->title,
            $ticket->status->label(),
            ucfirst($ticket->priority),
            ucfirst($ticket->type),
            $ticket->reporter->name,
            $ticket->assignee->name ?? 'Unassigned',
            $ticket->team->name ?? '-',
            $ticket->created_at->format('Y-m-d H:i:s'),
            $ticket->resolved_at?->format('Y-m-d H:i:s') ?? '-',
            $ticket->closed_at?->format('Y-m-d H:i:s') ?? '-',
        ];
    }
}
