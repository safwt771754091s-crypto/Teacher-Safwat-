<?php

namespace App\Agents;

use App\Agents\Contracts\Agent;
use App\Agents\Impl\AudioNarratorAgent;
use App\Agents\Impl\BookImporterAgent;
use App\Agents\Impl\ContentReviewerAgent;
use App\Agents\Impl\CurriculumBuilderAgent;
use App\Agents\Impl\ExerciseSolverAgent;
use App\Agents\Impl\LessonExplainerAgent;
use App\Models\AgentRun;
use App\Services\OfflineAiService;

/**
 * The single place that knows every agent. Agents are resolved from the
 * container (so they can receive services) and indexed by their stable key.
 */
class AgentRegistry
{
    /** @var array<string, Agent>|null */
    private ?array $agents = null;

    public function __construct(private OfflineAiService $offlineAi)
    {
    }

    /**
     * @return array<string, Agent>
     */
    public function all(): array
    {
        if ($this->agents === null) {
            $classes = [
                BookImporterAgent::class,
                CurriculumBuilderAgent::class,
                ExerciseSolverAgent::class,
                LessonExplainerAgent::class,
                AudioNarratorAgent::class,
                ContentReviewerAgent::class,
            ];

            $this->agents = [];
            foreach ($classes as $class) {
                /** @var Agent $agent */
                $agent = app($class);
                $this->agents[$agent->key()] = $agent;
            }
        }

        return $this->agents;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?Agent
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Run an agent and record the run. Never throws for a domain failure: the
     * outcome is captured in the AgentResult and the AgentRun row.
     */
    public function run(string $key, AgentContext $context): AgentResult
    {
        $agent = $this->get($key);
        if (! $agent) {
            return AgentResult::error("وكيل غير معروف: {$key}");
        }

        $started = microtime(true);
        try {
            $result = $agent->run($context);
        } catch (\Throwable $e) {
            $result = AgentResult::error($e->getMessage());
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        AgentRun::create([
            'agent_key' => $key,
            'user_id' => $context->user?->id,
            'subject_type' => $context->subject?->getMorphClass(),
            'subject_id' => $context->subject?->getKey(),
            'status' => $result->status,
            'offline' => $result->offline,
            'provider' => $result->provider,
            'input' => $this->safeInput($context),
            'output' => $this->safeOutput($result->output),
            'items' => $result->items,
            'duration_ms' => $durationMs,
            'error' => $result->error,
        ]);

        return $result;
    }

    /**
     * Whether the whole platform can run with no internet: every agent is
     * offline-capable and the cloud provider is optional.
     */
    public function offlineReadiness(): array
    {
        $agents = array_map(fn (Agent $a) => [
            'key' => $a->key(),
            'name' => $a->name(),
            'offline_capable' => $a->offlineCapable(),
        ], array_values($this->all()));

        $allOffline = ! in_array(false, array_column($agents, 'offline_capable'), true);

        return [
            'offline_ready' => $allOffline,
            'cloud_provider' => $this->offlineAi->provider(),
            'cloud_available' => $this->offlineAi->available(),
            'agents_total' => count($agents),
            'agents_offline' => count(array_filter($agents, fn ($a) => $a['offline_capable'])),
        ];
    }

    private function safeInput(AgentContext $context): array
    {
        return array_filter([
            'params' => $context->params,
            'subject_id' => $context->subject?->getKey(),
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function safeOutput(array $output): array
    {
        // Keep run rows small; the full payload is returned to the caller.
        $trimmed = [];
        foreach ($output as $key => $value) {
            $trimmed[$key] = is_string($value) && mb_strlen($value) > 2000
                ? mb_substr($value, 0, 2000).'…'
                : $value;
        }

        return $trimmed;
    }
}
