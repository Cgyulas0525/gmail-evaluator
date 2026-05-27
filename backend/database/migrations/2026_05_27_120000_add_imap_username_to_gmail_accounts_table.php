<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_accounts', function (Blueprint $table) {
            $table->string('imap_username')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('gmail_accounts', function (Blueprint $table) {
            $table->dropColumn('imap_username');
        });
    }
};
