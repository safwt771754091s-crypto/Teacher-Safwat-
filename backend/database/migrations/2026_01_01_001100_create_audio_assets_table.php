<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->foreignId('section_id')->nullable()->constrained('lesson_sections')->nullOnDelete();
            $t->text('text');
            $t->string('voice')->nullable();
            $t->string('lang')->default('ar');
            $t->string('file_path')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->enum('status', ['pending', 'ready', 'failed'])->default('pending');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_assets');
    }
};
