<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every agent invocation is recorded so the owner dashboard can show
        // what each agent did, what it produced, and whether it needed an LLM.
        Schema::create('agent_runs', function (Blueprint $t) {
            $t->id();
            $t->string('agent_key')->index();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->nullableMorphs('subject'); // the model the agent acted on (Lesson, Textbook, Exercise...)
            $t->string('status')->default('ok'); // ok|needs_content|error
            $t->boolean('offline')->default(true);
            $t->string('provider')->default('none');
            $t->json('input')->nullable();
            $t->json('output')->nullable();
            $t->unsignedInteger('items')->default(0);
            $t->unsignedInteger('duration_ms')->default(0);
            $t->text('error')->nullable();
            $t->timestamps();

            $t->index(['agent_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
