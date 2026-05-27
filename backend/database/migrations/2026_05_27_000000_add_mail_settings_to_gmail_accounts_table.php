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
        Schema::table('gmail_accounts', function (Blueprint $table) {
            $table->string('provider')->default('gmail')->after('password');
            $table->string('imap_host')->default('imap.gmail.com')->after('provider');
            $table->unsignedSmallInteger('imap_port')->default(993)->after('imap_host');
            $table->string('imap_encryption')->default('ssl')->after('imap_port');
            $table->string('smtp_host')->default('smtp.gmail.com')->after('imap_encryption');
            $table->unsignedSmallInteger('smtp_port')->default(587)->after('smtp_host');
            $table->string('smtp_encryption')->default('tls')->after('smtp_port');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gmail_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'imap_host',
                'imap_port',
                'imap_encryption',
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
            ]);
        });
    }
};
