<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $t->string('label')->nullable();
            $t->text('text');
            $t->boolean('is_correct')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_options');
    }
};
