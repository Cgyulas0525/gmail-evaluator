<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GmailAccount;
use App\Services\EmailFetcherService;
use App\Services\EmailProcessingService;
use Illuminate\Support\Facades\Log;

class FetchEmailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emails:fetch {--limit=50 : Maximum number of emails to fetch per account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and evaluate emails from all active Gmail accounts';

    /**
     * Execute the console command.
     */
    public function handle(EmailFetcherService $fetcher, EmailProcessingService $processor): int
    {
        $this->info("Starting email fetch and evaluation job...");
        Log::info("FetchEmailsCommand: Started");

        $accounts = GmailAccount::all(); // Fetch all accounts to evaluate (even if pending or error, we'll try to re-connect)
        
        if ($accounts->isEmpty()) {
            $this->warn("No Gmail accounts configured yet. Use the dashboard to add an account.");
            Log::info("FetchEmailsCommand: No accounts found.");
            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $totalFetched = 0;
        $totalEvaluated = 0;

        foreach ($accounts as $account) {
            $this->info("Processing account: {$account->email}...");
            try {
                // 1. Fetch emails
                $emails = $fetcher->fetchEmails($account, $limit);
                $fetchedCount = count($emails);
                $totalFetched += $fetchedCount;
                
                $this->line(" - Fetched $fetchedCount new email(s).");

                // 2. Evaluate emails
                foreach ($emails as $email) {
                    $this->line("   - Evaluating: \"{$email->subject}\"");
                    $processor->processEmail($email);
                    $totalEvaluated++;
                }

                $account->update([
                    'status' => 'active',
                    'last_error' => null
                ]);
            } catch (\Exception $e) {
                $this->error(" - Error for {$account->email}: " . $e->getMessage());
                Log::error("FetchEmailsCommand failed for {$account->email}: " . $e->getMessage());
                
                $account->update([
                    'status' => 'error',
                    'last_error' => $e->getMessage()
                ]);
            }
        }

        $this->info("Job finished! Total Fetched: $totalFetched, Total Evaluated: $totalEvaluated");
        Log::info("FetchEmailsCommand: Completed (Fetched: $totalFetched, Evaluated: $totalEvaluated)");

        return Command::SUCCESS;
    }
}
