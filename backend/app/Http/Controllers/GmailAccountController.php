<?php

namespace App\Http\Controllers;

use App\Models\GmailAccount;
use App\Services\EmailFetcherService;
use App\Services\EmailProcessingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
            'provider' => ['nullable', Rule::in(['gmail', 'custom'])],
            'imap_username' => 'nullable|string|max:255',
            'imap_host' => 'required_if:provider,custom|nullable|string|max:255',
            'imap_port' => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'none'])],
            'imap_mailbox' => 'nullable|string|max:255',
            'smtp_host' => 'required_if:provider,custom|nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'none'])],
        ]);

        $mailSettings = GmailAccount::settingsFromInput($validated);

        $connectionTest = $this->fetcher->testConnection(
            $validated['email'],
            $validated['password'],
            $mailSettings
        );

        if (!$connectionTest['success']) {
            return response()->json([
                'message' => 'Connection test failed: ' . $connectionTest['message'],
                'errors' => ['password' => ['A megadott adatokkal nem sikerült IMAP kapcsolódás. Ellenőrizd az e-mail címet, jelszót és szerverbeállításokat!']]
            ], 422);
        }

        $account = GmailAccount::create(array_merge($mailSettings, [
            'email' => $validated['email'],
            'password' => $validated['password'],
            'status' => 'active',
        ]));

        try {
            $emails = $this->fetcher->fetchEmails($account, 50);
            $this->processor->processEmails($emails);
        } catch (\Exception $e) {
            Log::error("Initial email fetch failed for {$account->email}: " . $e->getMessage());
        }

        $account->loadCount('emails');

        return response()->json([
            'message' => 'E-mail fiók sikeresen hozzáadva és tesztelve!',
            'account' => $account,
        ], 210);
    }

    /**
     * Test connection for an existing account.
     */
    public function testExistingConnection(GmailAccount $account): JsonResponse
    {
        $test = $this->fetcher->testAccountConnection($account);

        if ($test['success']) {
            $account->update(['status' => 'active', 'last_error' => null]);
            return response()->json(['success' => true, 'message' => 'A kapcsolat sikeres!']);
        }

        $account->update(['status' => 'error', 'last_error' => $test['message']]);
        return response()->json(['success' => false, 'message' => $test['message']], 400);
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
        return response()->json(['message' => 'Az e-mail fiók sikeresen eltávolítva!']);
    }
}
