<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbooks', function (Blueprint $t) {
            // Set once a book's pages have been fully extracted from its official
            // PDF. Null means "still an outline": the book has only seeded stub
            // pages (or was never fetched), so the importer must still process it.
            $t->timestamp('ingested_at')->nullable()->after('pages_count');
        });
    }

    public function down(): void
    {
        Schema::table('textbooks', function (Blueprint $t) {
            $t->dropColumn('ingested_at');
        });
    }
};
