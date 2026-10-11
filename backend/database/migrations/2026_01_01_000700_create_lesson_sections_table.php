<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['heading', 'point', 'definition', 'example', 'note', 'vocab'])->default('point');
            $t->string('title')->nullable();
            $t->text('content');
            $t->unsignedSmallInteger('source_page')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_from_textbook')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sections');
    }
};
