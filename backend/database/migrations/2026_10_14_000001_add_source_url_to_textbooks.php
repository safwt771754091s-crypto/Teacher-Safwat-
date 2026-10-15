<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbooks', function (Blueprint $table) {
            // Official source PDF, kept so a book's pages can be re-rendered on
            // demand (the imported PDF itself is not retained on disk).
            $table->string('source_url')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('textbooks', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
