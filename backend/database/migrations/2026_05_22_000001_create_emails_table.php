<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmail_account_id')->constrained('gmail_accounts')->onDelete('cascade');
            $table->string('message_id')->unique(); // Unique Gmail Message-ID
            $table->string('sender');
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->longText('body');
            $table->timestamp('received_at');
            $table->string('sentiment')->default('neutral'); // positive, neutral, negative
            $table->string('priority')->default('medium'); // urgent, high, medium, low
            $table->string('category')->default('personal'); // billing, work, spam, promotion, personal, security, etc.
            $table->text('summary')->nullable();
            $table->json('action_items')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
