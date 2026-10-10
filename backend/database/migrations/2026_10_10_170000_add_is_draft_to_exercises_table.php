<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks exercises surfaced from a textbook's scanned pages before a human has
 * verified them. A draft carries only the book's question text (no answer), so
 * it must stay hidden from learners until reviewed. Kept separate from the
 * 'source' enum so authored ('textbook'/'teacher') exercises are never affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->boolean('is_draft')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table) {
            $table->dropColumn('is_draft');
        });
    }
};
