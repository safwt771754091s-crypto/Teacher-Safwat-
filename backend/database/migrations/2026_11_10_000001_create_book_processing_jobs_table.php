<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the automatic conversion of an uploaded textbook into interactive
 * models, one row per book.
 *
 * A full Yemeni textbook is hundreds of pages of OCR plus model generation, so
 * the work is far too slow for one HTTP request. The row is the durable cursor:
 * each stage records what it finished, so a run that dies (deploy, timeout,
 * worker restart) resumes at the next unfinished page instead of starting over,
 * and two runs of the same book never duplicate work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_processing_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('textbook_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('queued'); // queued|extracting|segmenting|generating|review|published|failed
            $t->string('stage')->nullable();          // human-readable current step
            $t->unsignedInteger('pages_total')->default(0);
            $t->unsignedInteger('pages_done')->default(0);
            $t->unsignedInteger('lessons_total')->default(0);
            $t->unsignedInteger('models_generated')->default(0);
            $t->unsignedInteger('models_approved')->default(0);
            // The page number OCR last completed, so a resumed run continues
            // from there rather than re-OCRing the whole book.
            $t->unsignedInteger('last_page_processed')->default(0);
            $t->text('last_error')->nullable();
            $t->json('stats')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique('textbook_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_processing_jobs');
    }
};
