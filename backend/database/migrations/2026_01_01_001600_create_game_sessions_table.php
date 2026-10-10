<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('game_activity_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('score')->default(0);
            $t->unsignedTinyInteger('stars')->default(0);
            $t->unsignedInteger('duration_seconds')->default(0);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sessions');
    }
};
