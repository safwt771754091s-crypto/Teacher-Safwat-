<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $fillable = [
        'key', 'name', 'name_ar', 'purpose', 'category',
        'offline_capable', 'is_enabled', 'sort_order', 'capabilities',
    ];

    protected $casts = [
        'offline_capable' => 'boolean',
        'is_enabled' => 'boolean',
        'capabilities' => 'array',
    ];

    public function runs()
    {
        return $this->hasMany(AgentRun::class, 'agent_key', 'key');
    }
}
