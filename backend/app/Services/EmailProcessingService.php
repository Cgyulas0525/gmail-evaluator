<?php

namespace App\Services;

use App\Models\Email;
use Illuminate\Support\Facades\Log;

class EmailProcessingService
{
    public function __construct(
        private EmailEvaluatorService $evaluator,
        private EmailAutoReplyService $autoReplier,
    ) {
    }

    public function processEmail(Email $email): Email
    {
        $email = $this->evaluator->evaluateEmail($email);

        try {
            $this->autoReplier->maybeAutoReply($email->fresh());
        } catch (\Throwable $e) {
            Log::error("Auto-reply step failed for email {$email->id}: " . $e->getMessage());
        }

        return $email->fresh();
    }

    public function processEmails(array $emails): int
    {
        $count = 0;

        foreach ($emails as $email) {
            $this->processEmail($email);
            $count++;
        }

        return $count;
    }
}
