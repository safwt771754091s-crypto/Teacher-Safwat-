<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->enum('status', ['not_started', 'in_progress', 'mastered'])->default('in_progress');
            $t->unsignedTinyInteger('mastery_percent')->default(0);
            $t->unsignedInteger('time_spent_seconds')->default(0);
            $t->timestamp('last_activity_at')->nullable();
            $t->timestamps();
            $t->unique(['student_profile_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_progress');
    }
};
