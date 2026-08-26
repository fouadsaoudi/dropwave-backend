<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('reply_to_msg_id')
                ->nullable()
                ->after('reaction_to_msg_id')
                ->constrained('messages')
                ->nullOnDelete();

            $table->string('reply_to_whatsapp_msg_id', 100)
                ->nullable()
                ->after('reply_to_msg_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to_msg_id']);
            $table->dropColumn(['reply_to_msg_id', 'reply_to_whatsapp_msg_id']);
        });
    }
};
