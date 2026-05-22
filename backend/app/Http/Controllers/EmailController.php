<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\GmailAccount;
use App\Services\EmailComposeService;
use App\Services\EmailAttachmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmailController extends Controller
{
    /**
     * Display a listing of emails with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Email::with('gmailAccount:id,email');

        // Filter by Gmail Account
        if ($request->has('gmail_account_id') && !empty($request->gmail_account_id)) {
            $query->where('gmail_account_id', $request->gmail_account_id);
        }

        // Filter by Priority
        if ($request->has('priority') && !empty($request->priority)) {
            $query->where('priority', $request->priority);
        }

        // Filter by Sentiment
        if ($request->has('sentiment') && !empty($request->sentiment)) {
            $query->where('sentiment', $request->sentiment);
        }

        // Filter by Category
        if ($request->has('category') && !empty($request->category)) {
            $query->where('category', $request->category);
        }

        // Fulltext Search
        if ($request->has('search') && !empty($request->search)) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', $search)
                  ->orWhere('sender', 'like', $search)
                  ->orWhere('body', 'like', $search)
                  ->orWhere('summary', 'like', $search);
            });
        }

        // Order by received date
        $emails = $query->orderBy('received_at', 'desc')
                        ->paginate($request->get('per_page', 15));

        return response()->json($emails);
    }

    /**
     * Display the specified email.
     */
    public function show(Email $email): JsonResponse
    {
        $email->load('gmailAccount:id,email');
        return response()->json($email);
    }

    /**
     * Send a manual reply to the specified email.
     */
    public function reply(Request $request, Email $email, EmailComposeService $composeService): JsonResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:50000',
            'to' => 'nullable|email|max:255',
            'subject' => 'nullable|string|max:998',
        ]);

        try {
            $composeService->reply($email, $validated);

            return response()->json([
                'success' => true,
                'message' => 'A válasz sikeresen elküldve.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nem sikerült elküldeni a választ: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Forward the specified email to another recipient.
     */
    public function forward(Request $request, Email $email, EmailComposeService $composeService): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|email|max:255',
            'body' => 'nullable|string|max:50000',
            'subject' => 'nullable|string|max:998',
        ]);

        try {
            $composeService->forward($email, $validated);

            return response()->json([
                'success' => true,
                'message' => 'A továbbított e-mail sikeresen elküldve.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nem sikerült továbbítani az e-mailt: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download an attachment from IMAP on demand.
     */
    public function downloadAttachment(Email $email, string $attachmentId, EmailAttachmentService $attachmentService): StreamedResponse|JsonResponse
    {
        try {
            return $attachmentService->download($email, $attachmentId);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nem sikerült letölteni a mellékletet: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified email from the local database.
     */
    public function destroy(Email $email): JsonResponse
    {
        $email->delete();

        return response()->json([
            'success' => true,
            'message' => 'Az e-mail sikeresen törölve.',
        ]);
    }

    /**
     * Retrieve statistics for dashboard.
     */
    public function statistics(): JsonResponse
    {
        $totalEmails = Email::count();
        
        // Category Distribution
        $categories = Email::select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->get()
            ->pluck('count', 'category')
            ->toArray();

        $defaultCategories = ['billing' => 0, 'work' => 0, 'spam' => 0, 'promotion' => 0, 'personal' => 0, 'security' => 0];
        $categories = array_merge($defaultCategories, $categories);

        // Priority Distribution
        $priorities = Email::select('priority', DB::raw('count(*) as count'))
            ->groupBy('priority')
            ->get()
            ->pluck('count', 'priority')
            ->toArray();

        $defaultPriorities = ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $priorities = array_merge($defaultPriorities, $priorities);

        // Sentiment Distribution
        $sentiments = Email::select('sentiment', DB::raw('count(*) as count'))
            ->groupBy('sentiment')
            ->get()
            ->pluck('count', 'sentiment')
            ->toArray();

        $defaultSentiments = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $sentiments = array_merge($defaultSentiments, $sentiments);

        // 7 Days Trend
        $daysTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dateStr = $date->format('Y-m-d');
            $label = $date->isoFormat('ddd'); // Short day name, e.g., Mon, Tue
            
            $count = Email::whereDate('received_at', $dateStr)->count();
            
            $daysTrend[] = [
                'date' => $dateStr,
                'label' => $label,
                'count' => $count
            ];
        }

        // Account-wise Breakdown
        $accountsBreakdown = GmailAccount::withCount('emails')->get()->map(function ($account) {
            return [
                'id' => $account->id,
                'email' => $account->email,
                'status' => $account->status,
                'count' => $account->emails_count
            ];
        });

        // Recent Urgent Emails
        $recentUrgent = Email::whereIn('priority', ['urgent', 'high'])
            ->orderBy('received_at', 'desc')
            ->limit(5)
            ->get(['id', 'subject', 'sender', 'priority', 'category', 'received_at']);

        $recentBilling = Email::where('category', 'billing')
            ->orderBy('received_at', 'desc')
            ->limit(8)
            ->get(['id', 'subject', 'sender', 'priority', 'received_at']);

        return response()->json([
            'total_emails' => $totalEmails,
            'categories' => $categories,
            'priorities' => $priorities,
            'sentiments' => $sentiments,
            'trend' => $daysTrend,
            'accounts' => $accountsBreakdown,
            'recent_urgent' => $recentUrgent,
            'recent_billing' => $recentBilling,
        ]);
    }
}
