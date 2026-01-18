<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
            background: #fff;
        }
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 20px;
        }
        .company-info {
            float: left;
            width: 50%;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 8px;
        }
        .company-details {
            color: #666;
            font-size: 11px;
        }
        .invoice-info {
            float: right;
            width: 50%;
            text-align: right;
        }
        .invoice-title {
            font-size: 28px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 8px;
        }
        .invoice-number {
            font-size: 14px;
            color: #666;
            margin-bottom: 4px;
        }
        .invoice-date {
            font-size: 11px;
            color: #888;
        }
        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }
        .billing-section {
            margin: 30px 0;
            display: table;
            width: 100%;
        }
        .bill-to, .bill-from {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        .section-title {
            font-size: 10px;
            font-weight: bold;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .client-name {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 4px;
        }
        .client-details {
            color: #666;
            font-size: 11px;
        }
        .dates-section {
            background: #f8fafc;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            display: table;
            width: 100%;
        }
        .date-item {
            display: table-cell;
            width: 33.33%;
            text-align: center;
        }
        .date-label {
            font-size: 10px;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .date-value {
            font-size: 14px;
            font-weight: bold;
            color: #333;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
        }
        .items-table th {
            background: #1e40af;
            color: #fff;
            padding: 12px 15px;
            text-align: left;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }
        .items-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        .items-table tr:nth-child(even) {
            background: #f9fafb;
        }
        .item-description {
            font-weight: 500;
        }
        .totals-section {
            float: right;
            width: 300px;
            margin-top: 20px;
        }
        .total-row {
            display: table;
            width: 100%;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .total-label {
            display: table-cell;
            width: 60%;
            color: #666;
        }
        .total-value {
            display: table-cell;
            width: 40%;
            text-align: right;
            font-weight: 500;
        }
        .grand-total {
            background: #1e40af;
            color: #fff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .grand-total .total-label,
        .grand-total .total-value {
            color: #fff;
            font-size: 16px;
            font-weight: bold;
        }
        .notes-section {
            clear: both;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .notes-title {
            font-size: 12px;
            font-weight: bold;
            color: #333;
            margin-bottom: 8px;
        }
        .notes-content {
            color: #666;
            font-size: 11px;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #3b82f6;
            text-align: center;
            color: #888;
            font-size: 10px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-draft { background: #e5e7eb; color: #6b7280; }
        .status-sent { background: #dbeafe; color: #1d4ed8; }
        .status-viewed { background: #fef3c7; color: #d97706; }
        .status-paid { background: #d1fae5; color: #059669; }
        .status-overdue { background: #fee2e2; color: #dc2626; }
        .status-cancelled { background: #f3f4f6; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="header clearfix">
            <div class="company-info">
                <div class="company-name">{{ $invoice->team->name ?? config('app.name') }}</div>
                <div class="company-details">
                    {{-- Add company address/contact from settings if available --}}
                </div>
            </div>
            <div class="invoice-info">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-number">#{{ $invoice->invoice_number }}</div>
                <div class="invoice-date">
                    <span class="status-badge status-{{ $invoice->status->value }}">
                        {{ $invoice->status->label() }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Billing Section -->
        <div class="billing-section">
            <div class="bill-to">
                <div class="section-title">Bill To</div>
                <div class="client-name">{{ $invoice->client->name }}</div>
                <div class="client-details">
                    @if($invoice->client->email)
                        {{ $invoice->client->email }}<br>
                    @endif
                    @if($invoice->client->phone)
                        {{ $invoice->client->phone }}<br>
                    @endif
                    @if($invoice->client->address)
                        {{ $invoice->client->address }}
                    @endif
                </div>
            </div>
            <div class="bill-from">
                @if($invoice->project)
                    <div class="section-title">Project</div>
                    <div class="client-name">{{ $invoice->project->name }}</div>
                @endif
            </div>
        </div>

        <!-- Dates Section -->
        <div class="dates-section">
            <div class="date-item">
                <div class="date-label">Issue Date</div>
                <div class="date-value">{{ $invoice->issue_date?->format('M d, Y') ?? '-' }}</div>
            </div>
            <div class="date-item">
                <div class="date-label">Due Date</div>
                <div class="date-value">{{ $invoice->due_date?->format('M d, Y') ?? '-' }}</div>
            </div>
            <div class="date-item">
                <div class="date-label">Currency</div>
                <div class="date-value">{{ $invoice->currency }}</div>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Description</th>
                    <th style="width: 15%;">Quantity</th>
                    <th style="width: 17%;">Unit Price</th>
                    <th style="width: 18%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                    <tr>
                        <td class="item-description">{{ $item->description }}</td>
                        <td>{{ number_format($item->quantity, 2) }}</td>
                        <td>{{ $invoice->currency }} {{ number_format($item->unit_price, 2) }}</td>
                        <td>{{ $invoice->currency }} {{ number_format($item->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals Section -->
        <div class="totals-section">
            <div class="total-row">
                <div class="total-label">Subtotal</div>
                <div class="total-value">{{ $invoice->currency }} {{ number_format($invoice->subtotal, 2) }}</div>
            </div>
            @if($invoice->tax_rate > 0)
                <div class="total-row">
                    <div class="total-label">Tax ({{ number_format($invoice->tax_rate, 2) }}%)</div>
                    <div class="total-value">{{ $invoice->currency }} {{ number_format($invoice->tax_amount, 2) }}</div>
                </div>
            @endif
            @if($invoice->discount_amount > 0)
                <div class="total-row">
                    <div class="total-label">Discount</div>
                    <div class="total-value">-{{ $invoice->currency }} {{ number_format($invoice->discount_amount, 2) }}</div>
                </div>
            @endif
            <div class="grand-total">
                <div class="total-row" style="border: none; padding: 0;">
                    <div class="total-label">Total Due</div>
                    <div class="total-value">{{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</div>
                </div>
            </div>
        </div>

        <div style="clear: both;"></div>

        <!-- Notes Section -->
        @if($invoice->notes || $invoice->terms)
            <div class="notes-section">
                @if($invoice->notes)
                    <div class="notes-title">Notes</div>
                    <div class="notes-content">{{ $invoice->notes }}</div>
                @endif
                @if($invoice->terms)
                    <div class="notes-title" style="margin-top: 15px;">Terms & Conditions</div>
                    <div class="notes-content">{{ $invoice->terms }}</div>
                @endif
            </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>Thank you for your business!</p>
            <p>Invoice generated on {{ now()->format('M d, Y \a\t h:i A') }}</p>
        </div>
    </div>
</body>
</html>
