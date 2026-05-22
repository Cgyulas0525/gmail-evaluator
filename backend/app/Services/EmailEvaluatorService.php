<?php

namespace App\Services;

use App\Models\Email;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailEvaluatorService
{
    /**
     * Evaluate the email (AI if key is present, fallback keyword-based analysis otherwise).
     *
     * @param Email $email
     * @return Email The updated email model
     */
    public function evaluateEmail(Email $email): Email
    {
        $apiKey = env('GEMINI_API_KEY');

        if (!empty($apiKey)) {
            try {
                Log::info("Using Gemini AI to evaluate email ID: {$email->id} (Subject: {$email->subject})");
                return $this->evaluateWithGemini($email, $apiKey);
            } catch (\Exception $e) {
                Log::error("Gemini AI evaluation failed: " . $e->getMessage() . ". Falling back to rule-based analysis.");
            }
        }

        Log::info("Using Rule-based parser to evaluate email ID: {$email->id}");
        return $this->evaluateWithRules($email);
    }

    /**
     * Call Gemini API to evaluate the email.
     */
    private function evaluateWithGemini(Email $email, string $apiKey): Email
    {
        // Construct the prompt
        $prompt = "You are an expert email classifier and evaluator. Evaluate the following email and return a JSON object ONLY. 
Do not include any markdown formatting (like ```json), backticks, or trailing text. Return exactly a raw JSON block.

The JSON schema MUST be exactly:
{
  \"priority\": \"urgent\" | \"high\" | \"medium\" | \"low\",
  \"sentiment\": \"positive\" | \"neutral\" | \"negative\",
  \"category\": \"billing\" | \"work\" | \"spam\" | \"promotion\" | \"personal\" | \"security\",
  \"summary\": \"Short 1-2 sentence summary written in HUNGARIAN\",
  \"action_items\": [\"List of action items in HUNGARIAN. Keep empty list [] if no clear action items\"]
}

Email Details:
Sender: {$email->sender}
Subject: {$email->subject}
Received At: {$email->received_at}
Body Content: {$email->body}
";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}", [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ]);

        if ($response->failed()) {
            throw new \Exception("Gemini API request failed with status " . $response->status() . ": " . $response->body());
        }

        $result = $response->json();
        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        // Clean JSON text just in case (removing markdown code blocks)
        $text = trim($text);
        if (str_starts_with($text, '```json')) {
            $text = substr($text, 7);
        }
        if (str_ends_with($text, '```')) {
            $text = substr($text, 0, -3);
        }
        $text = trim($text);

        $data = json_decode($text, true);

        if (!$data || !isset($data['priority'])) {
            Log::warning("Invalid response JSON from Gemini API: " . $text);
            throw new \Exception("Gemini returned invalid JSON structure.");
        }

        $email->update([
            'priority' => $data['priority'] ?? 'medium',
            'sentiment' => $data['sentiment'] ?? 'neutral',
            'category' => $data['category'] ?? 'personal',
            'summary' => $data['summary'] ?? 'Nincs összefoglaló.',
            'action_items' => $data['action_items'] ?? [],
        ]);

        return $email;
    }

    /**
     * Local keyword-based rule fallback.
     */
    private function evaluateWithRules(Email $email): Email
    {
        $subject = mb_strtolower($email->subject);
        $body = mb_strtolower($email->body);
        $combined = $subject . ' ' . $body;

        // 1. Determine Category
        $category = 'personal';
        if ($this->containsAny($combined, ['számla', 'fizetés', 'díjbekérő', 'invoice', 'payment', 'receipt', 'tranzakció', 'utalás', 'billing'])) {
            $category = 'billing';
        } elseif ($this->containsAny($combined, ['jelszó', 'password', 'biztonság', 'security', 'bejelentkezés', 'alert', 'figyelmeztetés', 'login', 'kétlépcsős', 'mfa'])) {
            $category = 'security';
        } elseif ($this->containsAny($combined, ['akció', 'promotion', 'sale', 'kupon', 'coupon', 'kedvezmény', 'vásároljon', 'megtakarítás'])) {
            $category = 'promotion';
        } elseif ($this->containsAny($combined, ['nyerj', 'win', 'nyeremény', 'jackpot', 'ingyen', 'free', 'kaszinó', 'lottó', 'spam', 'bitcoin', 'crypto'])) {
            $category = 'spam';
        } elseif ($this->containsAny($combined, ['értekezlet', 'meeting', 'feladat', 'task', 'projekt', 'jira', 'trello', 'github', 'work', 'munka', 'határidő'])) {
            $category = 'work';
        }

        // 2. Determine Priority
        $priority = 'medium';
        if ($this->containsAny($combined, ['sürgős', 'urgent', 'azonnal', 'fontos', 'important', 'crit', 'critical', 'veszély', 'emergency'])) {
            $priority = $category === 'spam' ? 'low' : 'urgent';
        } elseif ($this->containsAny($combined, ['határidő', 'deadline', 'ma', 'today', 'holnap', 'tomorrow'])) {
            $priority = 'high';
        } elseif ($category === 'spam' || $category === 'promotion') {
            $priority = 'low';
        }

        // 3. Determine Sentiment
        $sentiment = 'neutral';
        $positiveCount = $this->countMatches($combined, ['örülök', 'köszönöm', 'szuper', 'kiváló', 'happy', 'thanks', 'great', 'excellent', 'csodás', 'perfect', 'nagyszerű']);
        $negativeCount = $this->countMatches($combined, ['dühös', 'mérges', 'rossz', 'sajnos', 'baj', 'hiba', 'error', 'fail', 'broken', 'angry', 'probléma', 'panasz', 'nem működik']);
        
        if ($positiveCount > $negativeCount) {
            $sentiment = 'positive';
        } elseif ($negativeCount > $positiveCount) {
            $sentiment = 'negative';
        }

        // 4. Generate Hungarian Summary
        $senderClean = explode('<', $email->sender)[0];
        $summary = "Levél érkezett tőle: " . trim($senderClean) . ". ";
        if (!empty($email->subject)) {
            $summary .= "A levél tárgya: \"{$email->subject}\". ";
        }
        $bodyExcerpt = mb_substr($email->body, 0, 100);
        if (mb_strlen($email->body) > 100) {
            $bodyExcerpt .= "...";
        }
        $summary .= "Tartalmi kivonat: " . trim($bodyExcerpt);

        // 5. Action Items
        $actionItems = [];
        if ($category === 'billing') {
            $actionItems[] = "Számla ellenőrzése és befizetési határidő rögzítése.";
        } elseif ($category === 'security') {
            $actionItems[] = "Biztonsági riasztás ellenőrzése, szükség esetén jelszócsere.";
        } elseif ($category === 'work') {
            $actionItems[] = "Munkával kapcsolatos teendő áttekintése és válaszadás.";
        } elseif ($priority === 'urgent' || $priority === 'high') {
            $actionItems[] = "A levél sürgős megválaszolása vagy intézkedés.";
        } else {
            $actionItems[] = "Levél olvasottá tétele és archiválása.";
        }

        $email->update([
            'priority' => $priority,
            'sentiment' => $sentiment,
            'category' => $category,
            'summary' => $summary,
            'action_items' => $actionItems,
        ]);

        return $email;
    }

    /**
     * Check if text contains any of the keywords.
     */
    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count occurrences of keywords.
     */
    private function countMatches(string $text, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $keyword) {
            $count += substr_count($text, $keyword);
        }
        return $count;
    }
}
