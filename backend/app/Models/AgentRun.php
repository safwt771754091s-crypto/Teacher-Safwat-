<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    protected $fillable = [
        'agent_key', 'user_id', 'subject_type', 'subject_id',
        'status', 'offline', 'provider', 'input', 'output',
        'items', 'duration_ms', 'error',
    ];

    protected $casts = [
        'offline' => 'boolean',
        'input' => 'array',
        'output' => 'array',
    ];

    public function subject()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
