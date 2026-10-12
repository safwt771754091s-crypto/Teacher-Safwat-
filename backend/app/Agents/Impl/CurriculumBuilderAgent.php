<?php

namespace App\Agents\Impl;

use App\Agents\AgentContext;
use App\Agents\AgentResult;
use App\Agents\Contracts\Agent;
use App\Models\Grade;
use App\Models\Subject;

/**
 * Builds the curriculum skeleton (grades and their subjects) for the whole
 * Yemeni school system — primary through secondary. The subject list per stage
 * is official data, so this needs no model and works fully offline.
 */
class CurriculumBuilderAgent implements Agent
{
    /** Official subjects by stage (used to seed every grade of that stage). */
    private const SUBJECTS = [
        'primary' => [
            ['name' => 'القرآن الكريم', 'icon' => 'book', 'color' => '#2f6a43', 'sort_order' => 1],
            ['name' => 'التربية الإسلامية', 'icon' => 'mosque', 'color' => '#1f7a5a', 'sort_order' => 2],
            ['name' => 'لغتي الجميلة (العربية)', 'icon' => 'text', 'color' => '#8a5a2b', 'sort_order' => 3],
            ['name' => 'الرياضيات', 'icon' => 'calc', 'color' => '#2b5f8a', 'sort_order' => 4],
            ['name' => 'العلوم', 'icon' => 'leaf', 'color' => '#3a7d44', 'sort_order' => 5],
            ['name' => 'الاجتماعيات', 'icon' => 'globe', 'color' => '#6a4a8a', 'sort_order' => 6],
            ['name' => 'اللغة الإنجليزية', 'icon' => 'text', 'color' => '#a25a2b', 'sort_order' => 7],
        ],
        'secondary' => [
            ['name' => 'القرآن الكريم', 'icon' => 'book', 'color' => '#2f6a43', 'sort_order' => 1],
            ['name' => 'التربية الإسلامية', 'icon' => 'mosque', 'color' => '#1f7a5a', 'sort_order' => 2],
            ['name' => 'اللغة العربية', 'icon' => 'text', 'color' => '#8a5a2b', 'sort_order' => 3],
            ['name' => 'الرياضيات', 'icon' => 'calc', 'color' => '#2b5f8a', 'sort_order' => 4],
            ['name' => 'الفيزياء', 'icon' => 'atom', 'color' => '#4a6a8a', 'sort_order' => 5],
            ['name' => 'الكيمياء', 'icon' => 'flask', 'color' => '#7a4a8a', 'sort_order' => 6],
            ['name' => 'الأحياء', 'icon' => 'leaf', 'color' => '#3a7d44', 'sort_order' => 7],
            ['name' => 'اللغة الإنجليزية', 'icon' => 'text', 'color' => '#a25a2b', 'sort_order' => 8],
            ['name' => 'التاريخ', 'icon' => 'globe', 'color' => '#6a4a8a', 'sort_order' => 9],
            ['name' => 'الجغرافيا', 'icon' => 'globe', 'color' => '#5a7a6a', 'sort_order' => 10],
        ],
    ];

    public function key(): string
    {
        return 'curriculum-builder';
    }

    public function name(): string
    {
        return 'وكيل بناء المنهج';
    }

    public function purpose(): string
    {
        return 'ينشئ هيكل الصفوف والمواد لكل المراحل اليمنية من الابتدائي إلى الثانوي.';
    }

    public function offlineCapable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return ['grades', 'subjects'];
    }

    public function run(AgentContext $context): AgentResult
    {
        $grades = Grade::orderBy('level')->get();
        if ($grades->isEmpty()) {
            return AgentResult::needsContent('لا توجد صفوف بعد. شغّل بذرة المنهج أولًا.');
        }

        $created = 0;
        foreach ($grades as $grade) {
            $stage = $grade->stage === 'secondary' || $grade->level > 9 ? 'secondary' : 'primary';
            foreach (self::SUBJECTS[$stage] as $subject) {
                $exists = Subject::where('grade_id', $grade->id)->where('name', $subject['name'])->exists();
                if ($exists) {
                    continue;
                }
                Subject::create($subject + ['grade_id' => $grade->id, 'is_active' => true]);
                $created++;
            }
        }

        return AgentResult::ok(
            ['subjects_created' => $created, 'grades_scanned' => $grades->count()],
            $created,
            offline: true,
        );
    }
}
