<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\DatabaseSnapshotService;
use App\Services\PlatformReportService;
use Illuminate\Http\Request;
use Throwable;

class OwnerController extends Controller
{
    public function users(Request $request)
    {
        $q = User::query();
        if ($request->filled('role')) {
            $q->where('role', $request->string('role'));
        }
        if ($request->filled('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', '%' . $request->string('search') . '%')
                ->orWhere('email', 'like', '%' . $request->string('search') . '%'));
        }
        return response()->json(['users' => $q->withCount('teacherAssignments')->orderByDesc('id')->paginate(30)]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:owner,admin,teacher,parent,student'],
            'grade_id' => ['nullable', 'exists:grades,id'],
        ]);

        $user = User::create($data);
        if ($user->role === User::ROLE_STUDENT) {
            $user->studentProfile()->create(['grade_id' => $data['grade_id'] ?? null]);
        }
        return response()->json(['user' => $user], 201);
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['nullable', 'in:owner,admin,teacher,parent,student'],
            'status' => ['nullable', 'in:active,suspended'],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        $user->update(array_filter($data, fn ($v) => $v !== null));
        return response()->json(['user' => $user]);
    }

    public function assignRole(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['required', 'in:owner,admin,teacher,parent,student'],
        ]);
        $user = User::findOrFail($data['user_id']);
        $user->update(['role' => $data['role']]);
        return response()->json(['user' => $user]);
    }

    public function roles()
    {
        return response()->json(['roles' => Role::all()]);
    }

    public function aiUsage()
    {
        $total = AiUsageLog::count();
        $cost = (float) AiUsageLog::sum('cost');
        $failed = AiUsageLog::where('success', false)->count();
        $byFeature = AiUsageLog::selectRaw('feature, count(*) c, sum(cost) cost')
            ->groupBy('feature')->get();
        return response()->json([
            'total_requests' => $total,
            'total_cost' => $cost,
            'failed' => $failed,
            'by_feature' => $byFeature,
            'recent' => AiUsageLog::with('user:id,name')->latest()->take(20)->get(),
        ]);
    }

    public function settings()
    {
        return response()->json(['settings' => Setting::all()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string'], 'value' => ['nullable']]);
        Setting::set($data['key'], $data['value']);
        return response()->json(['message' => 'تم الحفظ.']);
    }

    public function overview()
    {
        return response()->json([
            'counts' => [
                'users' => User::count(),
                'students' => User::where('role', 'student')->count(),
                'teachers' => User::where('role', 'teacher')->count(),
                'parents' => User::where('role', 'parent')->count(),
                'lessons' => Lesson::count(),
                'published_lessons' => Lesson::where('status', 'published')->count(),
            ],
            'ai' => [
                'requests' => AiUsageLog::count(),
                'cost' => (float) AiUsageLog::sum('cost'),
            ],
        ]);
    }

    public function errors()
    {
        return response()->json(['logs' => AiUsageLog::where('success', false)->latest()->take(50)->get()]);
    }

    // ---------------- Database snapshots ----------------

    public function dbSnapshots(DatabaseSnapshotService $snapshots)
    {
        return response()->json([
            'snapshots' => $snapshots->list(),
            'driver' => $snapshots->driver(),
            'directory' => $snapshots->directory(),
            'uploads_enabled' => $snapshots->uploadsEnabled(),
            'remote_target' => $snapshots->remoteTarget(),
        ]);
    }

    public function createDbSnapshot(Request $request, DatabaseSnapshotService $snapshots)
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:200']]);

        try {
            $meta = $snapshots->createAndUpload(label: $data['label'] ?? null);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['snapshot' => $meta], 201);
    }

    public function uploadDbSnapshot(Request $request, DatabaseSnapshotService $snapshots)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        if (! $snapshots->uploadsEnabled()) {
            return response()->json(['message' => 'التخزين البعيد غير مُفعّل.'], 422);
        }

        try {
            $target = $snapshots->upload($data['name']);
        } catch (Throwable $e) {
            return response()->json(['message' => 'تعذّر رفع اللقطة: ' . $e->getMessage()], 422);
        }

        return response()->json(['message' => 'تم رفع اللقطة إلى التخزين البعيد.', 'remote' => $target]);
    }

    public function restoreDbSnapshot(Request $request, DatabaseSnapshotService $snapshots)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        try {
            $path = $snapshots->localPath($data['name']);
        } catch (Throwable $e) {
            return response()->json(['message' => 'تعذّر جلب اللقطة من التخزين البعيد: ' . $e->getMessage()], 422);
        }
        if (! $path) {
            return response()->json(['message' => 'لم يُعثر على اللقطة المطلوبة.'], 404);
        }

        try {
            $snapshots->restore($path);
        } catch (Throwable $e) {
            return response()->json(['message' => 'تعذّرت الاستعادة: ' . $e->getMessage()], 422);
        }

        return response()->json(['message' => 'تمت استعادة اللقطة.', 'name' => $data['name']]);
    }

    public function downloadDbSnapshot(Request $request, DatabaseSnapshotService $snapshots)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        try {
            $path = $snapshots->localPath($data['name']);
        } catch (Throwable $e) {
            return response()->json(['message' => 'تعذّر جلب اللقطة من التخزين البعيد: ' . $e->getMessage()], 422);
        }
        if (! $path) {
            return response()->json(['message' => 'لم يُعثر على اللقطة المطلوبة.'], 404);
        }

        return response()->download($path, basename($path), ['Content-Type' => 'application/gzip']);
    }

    // ---------------- Platform report ----------------

    public function platformReport(PlatformReportService $report)
    {
        return response()->json(['stats' => $report->stats(), 'coverage' => $report->coverage()]);
    }

    public function downloadPlatformReport(PlatformReportService $report)
    {
        $name = 'platform-report-' . gmdate('Ymd-His');

        return response($report->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="report.pdf"; filename*=UTF-8\'\'' . rawurlencode($name . '.pdf'),
        ]);
    }
}
