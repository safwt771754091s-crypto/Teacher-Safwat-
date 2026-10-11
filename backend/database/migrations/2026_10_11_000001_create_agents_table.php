<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registry of AI agents. Each agent owns one job in the platform
        // (import a book, solve an exercise, narrate a lesson, ...) and declares
        // whether it can run fully offline, i.e. without any cloud LLM.
        Schema::create('agents', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
            $t->string('name_ar');
            $t->string('purpose');
            $t->string('category')->default('content'); // content|tutor|media|review|system
            $t->boolean('offline_capable')->default(true);
            $t->boolean('is_enabled')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->json('capabilities')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
