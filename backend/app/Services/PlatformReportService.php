<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\AiUsageLog;
use App\Models\Attempt;
use App\Models\GameActivity;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Textbook;
use App\Models\User;

/**
 * Renders a one-document PDF report describing the whole platform: the owner,
 * the curriculum coverage (grades / subjects / books / lessons), the learner
 * base, activity, and AI usage. It reuses TextbookExportService::pdf() so the
 * Arabic/RTL rendering is identical to the book exports.
 */
class PlatformReportService
{
    public function __construct(private TextbookExportService $export)
    {
    }

    /** @return array<string, mixed> the numbers shown in the report and UI */
    public function stats(): array
    {
        return [
            'grades' => Grade::count(),
            'subjects' => Subject::count(),
            'textbooks' => Textbook::count(),
            'lessons' => Lesson::count(),
            'published_lessons' => Lesson::where('status', 'published')->count(),
            'users' => User::count(),
            'students' => StudentProfile::count(),
            'attempts' => Attempt::count(),
            'games' => GameActivity::count(),
            'achievements' => Achievement::count(),
            'ai_requests' => AiUsageLog::count(),
            'ai_cost' => round((float) AiUsageLog::sum('cost'), 4),
        ];
    }

    /** @return array<string, mixed> per-grade curriculum coverage, largest first */
    public function coverage(): array
    {
        return Grade::query()
            ->withCount(['subjects'])
            ->orderBy('sort_order')
            ->get()
            ->map(function (Grade $grade) {
                $books = Textbook::whereHas('subject', fn ($q) => $q->where('grade_id', $grade->id));
                $lessons = Lesson::whereHas('textbook.subject', fn ($q) => $q->where('grade_id', $grade->id));

                return [
                    'name' => $grade->name,
                    'subjects' => $grade->subjects_count,
                    'textbooks' => (clone $books)->count(),
                    'lessons' => (clone $lessons)->count(),
                    'published' => (clone $lessons)->where('status', 'published')->count(),
                ];
            })
            ->all();
    }

    /** Render the report as PDF bytes. */
    public function pdf(): string
    {
        return $this->export->pdf($this->html());
    }

    public function html(): string
    {
        $stats = $this->stats();
        $coverage = $this->coverage();
        $owner = config('platform.owner.name', '');
        $generated = gmdate('Y-m-d H:i') . ' UTC';

        $cells = [
            'الصفوف' => $stats['grades'],
            'المواد' => $stats['subjects'],
            'الكتب' => $stats['textbooks'],
            'الدروس' => $stats['lessons'],
            'دروس منشورة' => $stats['published_lessons'],
            'المستخدمون' => $stats['users'],
            'الطلاب' => $stats['students'],
            'إجابات الطلاب' => $stats['attempts'],
            'الألعاب' => $stats['games'],
            'الإنجازات' => $stats['achievements'],
            'طلبات الذكاء الاصطناعي' => $stats['ai_requests'],
            'تكلفة الذكاء الاصطناعي' => $stats['ai_cost'],
        ];

        $cards = '';
        foreach ($cells as $label => $value) {
            $cards .= '<div class="stat"><span class="num">' . e((string) $value) . '</span>'
                . '<span class="lbl">' . e((string) $label) . '</span></div>';
        }

        $rows = '';
        foreach ($coverage as $row) {
            $rows .= '<tr><td>' . e($row['name']) . '</td>'
                . '<td>' . (int) $row['subjects'] . '</td>'
                . '<td>' . (int) $row['textbooks'] . '</td>'
                . '<td>' . (int) $row['lessons'] . '</td>'
                . '<td>' . (int) $row['published'] . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted">لا توجد بيانات منهج بعد.</td></tr>';
        }

        return '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
            . '<title>' . e(config('platform.name', 'المنصة')) . '</title>'
            . '<style>' . $this->css() . '</style></head><body>'
            . '<header class="cover"><h1>' . e(config('platform.name', 'المنصة')) . '</h1>'
            . '<p class="sub">تقرير المنصة — ملخّص شامل</p>'
            . '<p class="publisher">وزارة التربية والتعليم — الجمهورية اليمنية</p>'
            . ($owner !== '' ? '<p class="owner">' . e($owner) . '</p>' : '')
            . '<p class="meta">تاريخ الإنشاء: ' . e($generated) . '</p></header>'
            . '<section><h2>الملخّص العام</h2><div class="stats">' . $cards . '</div></section>'
            . '<section><h2>تغطية المنهج حسب الصف</h2>'
            . '<table class="grid"><thead><tr><th>الصف</th><th>المواد</th><th>الكتب</th><th>الدروس</th><th>منشورة</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></section>'
            . '<footer class="foot">تم إنشاء هذا التقرير تلقائيًا من ' . e(config('platform.name', 'المنصة')) . '.</footer>'
            . '</body></html>';
    }

    private function css(): string
    {
        return 'body{font-family:dejavusans,sans-serif;direction:rtl;text-align:right;color:#1f2933;line-height:1.9;}'
            . '.cover{text-align:center;border-bottom:3px solid #2f6a43;padding-bottom:14px;margin-bottom:18px;}'
            . '.cover h1{color:#2f6a43;margin:0 0 6px;}'
            . '.cover .sub{font-size:14px;color:#52606d;margin:2px 0;}'
            . '.cover .publisher{font-size:12px;color:#7b8794;margin:6px 0 0;}'
            . '.cover .owner{font-size:12px;color:#2f6a43;margin:2px 0 0;}'
            . '.cover .meta{font-size:11px;color:#7b8794;margin:4px 0 0;}'
            . 'h2{color:#2f6a43;font-size:16px;border-bottom:1px solid #d8e4d6;padding-bottom:6px;}'
            . '.stats{width:100%;}'
            . '.stat{display:inline-block;width:30%;margin:4px 1%;padding:8px 10px;background:#f4f8f3;border:1px solid #d8e4d6;border-radius:8px;text-align:center;}'
            . '.stat .num{display:block;font-size:20px;font-weight:bold;color:#2f6a43;}'
            . '.stat .lbl{display:block;font-size:11px;color:#52606d;}'
            . 'table.grid{width:100%;border-collapse:collapse;font-size:12px;}'
            . 'table.grid th,table.grid td{border:1px solid #cbd2d9;padding:5px 8px;text-align:center;}'
            . 'table.grid th{background:#e8f3e8;color:#2f6a43;}'
            . '.muted{color:#7b8794;}'
            . '.foot{margin-top:18px;font-size:11px;color:#7b8794;text-align:center;border-top:1px dashed #cbd2d9;padding-top:8px;}';
    }
}
