<?php

namespace App\Http\Controllers;

use App\Models\GmailAccount;
use App\Services\EmailFetcherService;
use App\Services\EmailProcessingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class GmailAccountController extends Controller
{
    private $fetcher;
    private $processor;

    public function __construct(EmailFetcherService $fetcher, EmailProcessingService $processor)
    {
        $this->fetcher = $fetcher;
        $this->processor = $processor;
    }

    /**
     * Display a listing of the Gmail accounts.
     */
    public function index(): JsonResponse
    {
        // Fetch accounts and append the number of fetched emails
        $accounts = GmailAccount::withCount('emails')->get();
        return response()->json($accounts);
    }

    /**
     * Store a newly created Gmail account.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:gmail_accounts,email',
            'password' => 'required|string|min:8',
        ]);

        // Test connection first
        $connectionTest = $this->fetcher->testConnection($validated['email'], $validated['password']);
        
        if (!$connectionTest['success']) {
            return response()->json([
                'message' => 'Connection test failed: ' . $connectionTest['message'],
                'errors' => ['password' => ['A megadott jelszóval vagy e-mail címmel nem sikerült kapcsolódni a Gmail-hez. Kérlek ellenőrizd az alkalmazás-jelszót (App Password)!']]
            ], 422);
        }

        // Save
        $account = GmailAccount::create([
            'email' => $validated['email'],
            'password' => $validated['password'],
            'status' => 'active'
        ]);

        // Proactively fetch first few emails in background or synchronously to populate the DB
        try {
            $emails = $this->fetcher->fetchEmails($account, 50);
            $this->processor->processEmails($emails);
        } catch (\Exception $e) {
            Log::error("Initial email fetch failed for {$account->email}: " . $e->getMessage());
        }

        // Reload count
        $account->loadCount('emails');

        return response()->json([
            'message' => 'Gmail fiók sikeresen hozzáadva és tesztelve!',
            'account' => $account
        ], 210);
    }

    /**
     * Test connection for an existing account.
     */
    public function testExistingConnection(GmailAccount $account): JsonResponse
    {
        $test = $this->fetcher->testConnection($account->email, $account->password);
        
        if ($test['success']) {
            $account->update(['status' => 'active', 'last_error' => null]);
            return response()->json(['success' => true, 'message' => 'A kapcsolat sikeres!']);
        } else {
            $account->update(['status' => 'error', 'last_error' => $test['message']]);
            return response()->json(['success' => false, 'message' => $test['message']], 400);
        }
    }

    /**
     * Manually sync an account.
     */
    public function sync(GmailAccount $account): JsonResponse
    {
        try {
            $emails = $this->fetcher->fetchEmails($account, 50);
            $processedCount = $this->processor->processEmails($emails);

            return response()->json([
                'success' => true,
                'message' => "Sikeres szinkronizálás! Letöltve és kiértékelve: {$processedCount} új levél.",
                'fetched_count' => $processedCount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Hiba történt a szinkronizálás során: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified Gmail account.
     */
    public function destroy(GmailAccount $account): JsonResponse
    {
        $account->delete();
        return response()->json(['message' => 'A Gmail fiók sikeresen eltávolítva!']);
    }
}
