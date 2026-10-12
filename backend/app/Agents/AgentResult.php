<?php

namespace App\Agents;

/**
 * The uniform result every agent returns. `needs_content` is the platform's
 * honesty guarantee: when an agent cannot act from real textbook source it says
 * so instead of inventing content.
 */
class AgentResult
{
    public function __construct(
        public string $status = 'ok',           // ok|needs_content|error
        public array $output = [],
        public int $items = 0,
        public bool $offline = true,
        public string $provider = 'none',
        public ?string $error = null,
    ) {
    }

    public static function ok(array $output = [], int $items = 0, bool $offline = true, string $provider = 'none'): self
    {
        return new self('ok', $output, $items, $offline, $provider);
    }

    public static function needsContent(string $reason, array $output = []): self
    {
        return new self('needs_content', $output + ['reason' => $reason], 0, true, 'none');
    }

    public static function error(string $message): self
    {
        return new self('error', [], 0, true, 'none', $message);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'output' => $this->output,
            'items' => $this->items,
            'offline' => $this->offline,
            'provider' => $this->provider,
            'error' => $this->error,
        ];
    }
}
