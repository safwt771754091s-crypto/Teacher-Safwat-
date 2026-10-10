<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedTinyInteger('level');
            $t->enum('stage', ['primary', 'preparatory', 'secondary'])->default('primary');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['name', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
