<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textbook_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('textbook_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('page_number');
            $t->string('image_path')->nullable();
            $t->longText('extracted_text')->nullable();
            $t->enum('status', ['raw', 'extracted', 'reviewed'])->default('raw');
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->unique(['textbook_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('textbook_pages');
    }
};
