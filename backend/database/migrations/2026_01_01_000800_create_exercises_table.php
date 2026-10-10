<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['mcq', 'true_false', 'short_answer', 'step_by_step', 'numeric', 'ordering'])->default('mcq');
            $t->text('question');
            $t->text('answer')->nullable();
            $t->json('solution_steps')->nullable();
            $t->text('hint')->nullable();
            $t->unsignedSmallInteger('source_page')->nullable();
            $t->enum('difficulty', ['easy', 'medium', 'hard'])->default('easy');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->enum('source', ['textbook', 'ai_generated', 'teacher'])->default('textbook');
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
