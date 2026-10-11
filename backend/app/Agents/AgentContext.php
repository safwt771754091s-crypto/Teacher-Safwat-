<?php

namespace App\Agents;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything an agent needs to do its job: who triggered it, what model it acts
 * on, and the caller-supplied parameters. Passing this (rather than relying on
 * globals) keeps agents testable and lets the registry run them in isolation.
 */
class AgentContext
{
    public function __construct(
        public ?User $user = null,
        public ?Model $subject = null,
        public array $params = [],
    ) {
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }
}
