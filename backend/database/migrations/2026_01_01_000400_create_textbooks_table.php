<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textbooks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->enum('term', ['first', 'second'])->default('first');
            $t->string('edition')->nullable();
            $t->string('publisher')->default('وزارة التربية والتعليم - الجمهورية اليمنية');
            $t->enum('source_type', ['pdf', 'images', 'manual'])->default('manual');
            $t->string('file_path')->nullable();
            $t->unsignedSmallInteger('pages_count')->default(0);
            $t->enum('status', ['draft', 'review', 'published'])->default('draft');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('textbooks');
    }
};
