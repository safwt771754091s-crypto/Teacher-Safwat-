<?php

namespace App\Agents\Contracts;

use App\Agents\AgentContext;
use App\Agents\AgentResult;

/**
 * One AI agent = one job. Implementations must be safe to run without any cloud
 * provider; they retrieve real source content and never invent textbook facts.
 */
interface Agent
{
    /** Stable identifier, e.g. "exercise-solver". */
    public function key(): string;

    /** Arabic display name. */
    public function name(): string;

    /** One-line purpose shown in the owner dashboard. */
    public function purpose(): string;

    /** Can this agent run with no internet / no cloud LLM? */
    public function offlineCapable(): bool;

    /** What this agent can do (free-form capability tags). */
    public function capabilities(): array;

    /** Run the job. */
    public function run(AgentContext $context): AgentResult;
}
