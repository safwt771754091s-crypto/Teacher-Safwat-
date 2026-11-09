<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The durable progress cursor for turning one textbook into interactive models.
 * See the migration for why this is a row rather than a queue message.
 */
class BookProcessingJob extends Model
{
    protected $fillable = [
        'textbook_id', 'status', 'stage', 'pages_total', 'pages_done',
        'lessons_total', 'models_generated', 'models_approved',
        'last_page_processed', 'last_error', 'stats',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'stats' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const ACTIVE = ['queued', 'extracting', 'segmenting', 'generating'];

    public function textbook()
    {
        return $this->belongsTo(Textbook::class);
    }

    /** Percentage complete, for the admin progress bar. */
    public function percent(): int
    {
        // 'review' means the machine is done and a human must look; the bar is
        // full because there is no automated work left to do.
        if (in_array($this->status, ['published', 'review'], true)) {
            return 100;
        }
        if ($this->status === 'failed') {
            return $this->pages_total > 0
                ? (int) floor($this->pages_done / $this->pages_total * 100)
                : 0;
        }
        if ($this->pages_total === 0) {
            return 0;
        }
        // Page work is 80% of the journey; model generation is the last 20%.
        $pageShare = min(1, $this->pages_done / $this->pages_total) * 80;
        $modelShare = $this->lessons_total > 0
            ? min(1, $this->models_generated / $this->lessons_total) * 20
            : 0;

        return (int) min(100, round($pageShare + $modelShare));
    }
}
