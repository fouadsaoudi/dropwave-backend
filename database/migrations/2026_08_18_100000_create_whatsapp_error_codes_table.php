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
        Schema::create('whatsapp_error_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->index();
            $table->string('subcode')->nullable()->index();
            $table->string('category')->default('Other')->index();
            $table->string('title')->nullable();
            $table->text('details')->nullable();
            $table->text('possible_reasons')->nullable();
            $table->text('possible_solutions')->nullable();
            $table->integer('http_status_code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_error_codes');
    }
};
