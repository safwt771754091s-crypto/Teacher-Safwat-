<?php

namespace Database\Seeders;

use App\Agents\AgentRegistry;
use App\Models\Agent;
use Illuminate\Database\Seeder;

/**
 * Registers every AI agent in the database so the owner dashboard can list them
 * and toggle them. The registry is the source of truth; this seeder mirrors it.
 */
class AgentSeeder extends Seeder
{
    public function run(): void
    {
        $category = [
            'book-importer' => 'content',
            'curriculum-builder' => 'system',
            'exercise-solver' => 'tutor',
            'lesson-explainer' => 'tutor',
            'audio-narrator' => 'media',
            'content-reviewer' => 'review',
        ];

        foreach (app(AgentRegistry::class)->all() as $key => $agent) {
            Agent::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $key,
                    'name_ar' => $agent->name(),
                    'purpose' => $agent->purpose(),
                    'category' => $category[$key] ?? 'content',
                    'offline_capable' => $agent->offlineCapable(),
                    'is_enabled' => true,
                    'capabilities' => $agent->capabilities(),
                ],
            );
        }
    }
}
