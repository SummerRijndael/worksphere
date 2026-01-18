

echo "--- EMAIL DATABASE AUDIT ---\n\n";

// 1. Total Count
$total = \App\Models\Email::count();
echo "Total Emails in DB: $total\n";

// 2. Count by Account
echo "\n--- Count by Account ---\n";
$accounts = \App\Models\EmailAccount::withCount('emails')->get();
foreach ($accounts as $acc) {
    echo "[ID: {$acc->id}] {$acc->email}: {$acc->emails_count}\n";
}

// 3. Count by Folder
echo "\n--- Count by Folder ---\n";
$folders = \App\Models\Email::selectRaw('folder, count(*) as total')
    ->groupBy('folder')
    ->get();


foreach ($folders as $f) {
    echo str_pad($f->folder, 20) . ": " . $f->total . "\n";
}

// 4. Sample List (Top 50 most recent)
echo "\n--- Most Recent 50 Emails ---\n";
echo str_pad("ID", 6) . " | " . str_pad("Folder", 15) . " | " . str_pad("Date", 20) . " | " . "Subject" . "\n";
echo str_repeat("-", 100) . "\n";

$emails = \App\Models\Email::orderBy('received_at', 'desc')->limit(50)->get();

foreach ($emails as $email) {
    echo str_pad($email->id, 6) . " | " . 
         str_pad(substr($email->folder, 0, 15), 15) . " | " . 
         str_pad($email->received_at?->format('Y-m-d H:i') ?? 'N/A', 20) . " | " . 
         substr($email->subject, 0, 50) . "\n";
}
