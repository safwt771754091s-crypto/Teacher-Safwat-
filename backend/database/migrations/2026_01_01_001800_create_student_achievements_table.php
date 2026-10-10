<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_achievements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $t->timestamp('earned_at')->nullable();
            $t->timestamps();
            $t->unique(['student_profile_id', 'achievement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_achievements');
    }
};
