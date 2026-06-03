<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tutor_conversations', function (Blueprint $table) {
            $table->uuid('conversation_id')->nullable()->after('user_id')->index();
        });

        DB::table('tutor_conversations')
            ->select('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->pluck('user_id')
            ->each(function ($userId) {
                $conversationId = null;
                $messageIndex = 0;

                DB::table('tutor_conversations')
                    ->select('id')
                    ->where('user_id', $userId)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get()
                    ->each(function ($row) use (&$conversationId, &$messageIndex) {
                        if ($messageIndex % 2 === 0) {
                            $conversationId = (string) Str::uuid();
                        }

                        DB::table('tutor_conversations')
                            ->where('id', $row->id)
                            ->update(['conversation_id' => $conversationId]);

                        $messageIndex++;
                    });
            });
    }

    public function down(): void
    {
        Schema::table('tutor_conversations', function (Blueprint $table) {
            $table->dropIndex(['conversation_id']);
            $table->dropColumn('conversation_id');
        });
    }
};