<?php

namespace App\Http\Controllers\Api;

use App\Agents\AgentContext;
use App\Agents\AgentRegistry;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Exposes the AI-agent layer: what agents exist, whether the platform is
 * offline-ready, how to run an agent, and the run history.
 */
class AgentController extends Controller
{
    /** Agents that only staff may trigger (they touch curriculum/content). */
    private const STAFF_ONLY = ['book-importer', 'curriculum-builder', 'content-reviewer'];

    public function __construct(private AgentRegistry $registry)
    {
    }

    public function index()
    {
        $agents = collect($this->registry->all())->map(function ($agent) {
            $meta = Agent::where('key', $agent->key())->first();

            return [
                'key' => $agent->key(),
                'name' => $agent->name(),
                'purpose' => $agent->purpose(),
                'offline_capable' => $agent->offlineCapable(),
                'capabilities' => $agent->capabilities(),
                'category' => $meta->category ?? 'content',
                'staff_only' => in_array($agent->key(), self::STAFF_ONLY, true),
                'runs' => AgentRun::where('agent_key', $agent->key())->count(),
                'last_run_at' => AgentRun::where('agent_key', $agent->key())->max('created_at'),
            ];
        })->values();

        return response()->json([
            'agents' => $agents,
            'readiness' => $this->registry->offlineReadiness(),
        ]);
    }

    public function run(Request $request, string $key)
    {
        $agent = $this->registry->get($key);
        if (! $agent) {
            return response()->json(['message' => 'وكيل غير معروف'], 404);
        }

        $user = $request->user();
        if (in_array($key, self::STAFF_ONLY, true) && ! in_array($user->role, ['owner', 'admin', 'teacher'], true)) {
            return response()->json(['message' => 'هذا الوكيل متاح للمعلم والمشرف فقط'], 403);
        }

        $data = $request->validate([
            'subject_type' => ['nullable', 'string', 'in:lesson,exercise,textbook,grade,subject'],
            'subject_id' => ['nullable', 'integer'],
            'params' => ['nullable', 'array'],
        ]);

        $subject = $this->resolveSubject($data['subject_type'] ?? null, $data['subject_id'] ?? null);

        $context = new AgentContext($user, $subject, $data['params'] ?? []);
        $result = $this->registry->run($key, $context);

        return response()->json([
            'agent' => $key,
            'result' => $result->toArray(),
        ], $result->status === 'error' ? 422 : 200);
    }

    /**
     * Sample subjects the UI can offer when running an agent on a lesson or an
     * exercise, so staff do not have to type raw IDs.
     */
    public function targets()
    {
        $lessons = \App\Models\Lesson::where('status', 'published')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'title'])
            ->map(fn ($l) => ['id' => $l->id, 'title' => $l->title]);

        $exercises = \App\Models\Exercise::where('is_draft', false)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'question'])
            ->map(fn ($e) => ['id' => $e->id, 'title' => mb_substr((string) $e->question, 0, 60)]);

        return response()->json(['lessons' => $lessons, 'exercises' => $exercises]);
    }

    public function runs(Request $request)
    {
        $runs = AgentRun::with('user:id,name')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (AgentRun $r) => [
                'id' => $r->id,
                'agent_key' => $r->agent_key,
                'status' => $r->status,
                'offline' => $r->offline,
                'items' => $r->items,
                'duration_ms' => $r->duration_ms,
                'user' => $r->user?->name,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json(['runs' => $runs]);
    }

    private function resolveSubject(?string $type, ?int $id): ?Model
    {
        if (! $type || ! $id) {
            return null;
        }

        $class = [
            'lesson' => \App\Models\Lesson::class,
            'exercise' => \App\Models\Exercise::class,
            'textbook' => \App\Models\Textbook::class,
            'grade' => \App\Models\Grade::class,
            'subject' => \App\Models\Subject::class,
        ][$type] ?? null;

        return $class ? $class::find($id) : null;
    }
}
