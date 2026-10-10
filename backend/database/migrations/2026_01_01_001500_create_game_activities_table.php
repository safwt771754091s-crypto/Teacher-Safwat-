<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->enum('type', ['math', 'science', 'reading', 'sorting', 'memory', 'sequence'])->default('math');
            $t->unsignedTinyInteger('difficulty')->default(1);
            $t->json('config')->nullable();
            $t->unsignedInteger('points')->default(10);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_activities');
    }
};
