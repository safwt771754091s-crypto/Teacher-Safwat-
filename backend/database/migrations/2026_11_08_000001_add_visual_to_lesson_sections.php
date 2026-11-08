<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional interactive-visual descriptor to each lesson section.
 *
 * A section used to be text only. `visual` lets an authored section also carry a
 * self-contained interactive model (graph, number line, geometric figure, …)
 * that the learner manipulates and the teacher narrates. The payload is a small
 * JSON spec interpreted by the client (web/assets/interactive.js); the server
 * never renders it, so new model kinds ship without a backend change.
 *
 * Shape (all keys optional except `kind`):
 *   { kind: 'complex-plane', params: {...}, prompt: '...', steps: ['...'] }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_sections', function (Blueprint $t) {
            $t->json('visual')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_sections', function (Blueprint $t) {
            $t->dropColumn('visual');
        });
    }
};
