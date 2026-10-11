<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $t->string('feature')->default('explain');
            $t->string('provider')->nullable();
            $t->string('model')->nullable();
            $t->unsignedInteger('prompt_tokens')->default(0);
            $t->unsignedInteger('completion_tokens')->default(0);
            $t->decimal('cost', 10, 6)->default(0);
            $t->boolean('success')->default(true);
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
