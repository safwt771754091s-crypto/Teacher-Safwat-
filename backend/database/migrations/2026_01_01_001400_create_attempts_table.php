<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $t->text('answer')->nullable();
            $t->boolean('is_correct')->default(false);
            $t->decimal('score', 5, 2)->default(0);
            $t->json('solution_steps')->nullable();
            $t->boolean('hint_used')->default(false);
            $t->unsignedInteger('duration_seconds')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempts');
    }
};
