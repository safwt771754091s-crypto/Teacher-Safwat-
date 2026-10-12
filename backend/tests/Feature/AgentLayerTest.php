<?php

namespace Tests\Feature;

use App\Agents\AgentContext;
use App\Agents\AgentRegistry;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLayerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_all_agents_are_registered_and_offline_capable(): void
    {
        $registry = app(AgentRegistry::class);

        $this->assertSame(
            ['book-importer', 'curriculum-builder', 'exercise-solver', 'lesson-explainer', 'audio-narrator', 'content-reviewer'],
            array_keys($registry->all()),
        );

        $readiness = $registry->offlineReadiness();
        $this->assertTrue($readiness['offline_ready']);
        $this->assertSame(6, $readiness['agents_total']);
        $this->assertSame(6, $readiness['agents_offline']);
    }

    public function test_agent_catalogue_endpoint_lists_agents_and_readiness(): void
    {
        $student = User::where('email', 'student@school.ye')->first();

        $res = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/agents')
            ->assertOk();

        $this->assertCount(6, $res->json('agents'));
        $this->assertTrue($res->json('readiness.offline_ready'));

        // Staff-only agents are flagged so the UI can hide them from learners.
        $importer = collect($res->json('agents'))->firstWhere('key', 'book-importer');
        $this->assertTrue($importer['staff_only']);
    }

    public function test_exercise_solver_agent_solves_grounded_and_never_invents(): void
    {
        $exercise = Exercise::where('is_draft', false)
            ->whereNotNull('solution_steps')
            ->firstOrFail();

        $result = app(AgentRegistry::class)->run('exercise-solver', new AgentContext(null, $exercise));

        $this->assertSame('ok', $result->status);
        $this->assertNotEmpty($result->output['solution']['steps']);
        $this->assertTrue($result->output['solution']['grounded']);
        $this->assertSame('source', $result->provider);

        // The run is audited.
        $this->assertDatabaseHas('agent_runs', [
            'agent_key' => 'exercise-solver',
            'status' => 'ok',
        ]);
    }

    public function test_lesson_explainer_reports_needs_content_for_empty_lesson(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $lesson->sections()->delete();
        $lesson->summaries()->delete();

        $result = app(AgentRegistry::class)->run('lesson-explainer', new AgentContext(null, $lesson));

        $this->assertSame('needs_content', $result->status);
        $this->assertSame(0, $result->items);
    }

    public function test_content_reviewer_flags_missing_options(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $exercise = Exercise::create([
            'lesson_id' => $lesson->id,
            'type' => 'mcq',
            'question' => 'أي مما يلي يساوي ١ + ١ ؟',
            'is_draft' => true,
        ]);

        $result = app(AgentRegistry::class)->run('content-reviewer', new AgentContext(null, $exercise));

        $this->assertSame('ok', $result->status);
        $this->assertSame('missing_options', $result->output['issues'][0]['type']);
    }

    public function test_staff_only_agent_is_blocked_for_students(): void
    {
        $student = User::where('email', 'student@school.ye')->first();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/agents/book-importer/run', ['params' => ['limit' => 0]])
            ->assertForbidden();
    }

    public function test_staff_can_run_curriculum_builder_agent(): void
    {
        $owner = User::where('email', 'owner@school.ye')->first();

        $res = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/agents/curriculum-builder/run', [])
            ->assertOk();

        $this->assertSame('ok', $res->json('result.status'));
        $this->assertGreaterThanOrEqual(0, $res->json('result.items'));
    }

    public function test_agent_seeder_registers_all_agents_in_db(): void
    {
        $this->assertSame(6, Agent::count());
        $this->assertTrue((bool) Agent::where('key', 'exercise-solver')->value('offline_capable'));
    }
}
