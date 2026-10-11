<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $t->date('birthdate')->nullable();
            $t->string('school')->nullable();
            $t->string('avatar')->nullable();
            $t->unsignedInteger('points')->default(0);
            $t->timestamps();
            $t->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
