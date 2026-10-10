<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $t->string('relation')->default('father');
            $t->enum('status', ['pending', 'active'])->default('active');
            $t->timestamps();
            $t->unique(['parent_user_id', 'student_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_links');
    }
};
