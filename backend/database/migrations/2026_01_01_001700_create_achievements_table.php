<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('icon')->nullable();
            $t->json('criteria')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
