<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $fillable = ['user_id', 'lesson_id', 'feature', 'provider', 'model', 'prompt_tokens', 'completion_tokens', 'cost', 'success', 'error'];
    protected $casts = ['success' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
