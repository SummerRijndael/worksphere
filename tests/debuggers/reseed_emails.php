
$account = \App\Models\EmailAccount::first();

if ($account) {
    $account->update(['sync_status' => \App\Enums\EmailSyncStatus::Seeding]);
    \App\Jobs\SeedEmailAccountJob::dispatch($account->id);
    echo "Dispatched SeedEmailAccountJob for: " . $account->email . PHP_EOL;
} else {
    echo "No email account found" . PHP_EOL;
}
