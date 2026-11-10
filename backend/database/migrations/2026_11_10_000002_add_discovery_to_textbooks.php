<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a book's source file was discovered, and the candidate links found.
 *
 * A textbook can be catalogued before its PDF is known: the owner records the
 * page that lists the official editions, and the Crawlee helper proposes the
 * files on it. Keeping the candidates on the row means a reviewer can pick one
 * later without crawling again, and keeping `discovery_url` separate from
 * `source_url` means the two questions - "where do I look" and "which file did
 * I choose" - never overwrite each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbooks', function (Blueprint $t) {
            if (! Schema::hasColumn('textbooks', 'discovery_url')) {
                $t->string('discovery_url')->nullable()->after('source_url');
            }
            if (! Schema::hasColumn('textbooks', 'source_candidates')) {
                $t->json('source_candidates')->nullable()->after('discovery_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('textbooks', function (Blueprint $t) {
            $t->dropColumn(['discovery_url', 'source_candidates']);
        });
    }
};
