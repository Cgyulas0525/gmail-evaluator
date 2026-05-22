<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('in_reply_to')->nullable()->after('message_id');
            $table->text('references')->nullable()->after('in_reply_to');
            $table->timestamp('auto_replied_at')->nullable()->after('action_items');
            $table->string('auto_reply_status')->nullable()->after('auto_replied_at');
            $table->text('auto_reply_error')->nullable()->after('auto_reply_status');
            $table->string('auto_reply_message_id')->nullable()->after('auto_reply_error');
            $table->string('auto_reply_thread_key')->nullable()->after('auto_reply_message_id');

            $table->index('auto_reply_thread_key');
            $table->index('auto_replied_at');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex(['auto_reply_thread_key']);
            $table->dropIndex(['auto_replied_at']);
            $table->dropColumn([
                'in_reply_to',
                'references',
                'auto_replied_at',
                'auto_reply_status',
                'auto_reply_error',
                'auto_reply_message_id',
                'auto_reply_thread_key',
            ]);
        });
    }
};
