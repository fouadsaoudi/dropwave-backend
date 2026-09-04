<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('call_id')->nullable()->after('conversation_id')->constrained('calls')->onDelete('set null');
        });

        // Modify the enum type column in MySQL to include 'call'
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE messages MODIFY COLUMN type ENUM('text', 'image', 'audio', 'video', 'document', 'location', 'sticker', 'reaction', 'template', 'unsupported', 'call') NOT NULL DEFAULT 'text'");
        }
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['call_id']);
            $table->dropColumn('call_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE messages MODIFY COLUMN type ENUM('text', 'image', 'audio', 'video', 'document', 'location', 'sticker', 'reaction', 'template', 'unsupported') NOT NULL DEFAULT 'text'");
        }
    }
};
