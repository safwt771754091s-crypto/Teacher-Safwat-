<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AiTeacherController;
use App\Http\Controllers\Api\AudioController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\OwnerController;
use App\Http\Controllers\Api\ParentController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok', 'app' => config('app.name')]));

    // Public platform metadata (branding shown before login).
    Route::get('config', function () {
        $registry = app(\App\Agents\AgentRegistry::class);

        return response()->json([
            'app' => config('app.name'),
            'platform' => config('platform.name'),
            'owner' => [
                'name' => config('platform.owner.name'),
                'title' => config('platform.owner.title'),
            ],
            'repo' => [
                'name' => config('platform.repo.name'),
                'url' => config('platform.repo.url'),
            ],
            'offline' => $registry->offlineReadiness(),
        ]);
    });

    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    // Public curriculum browsing (the textbook catalogue is not secret).
    Route::get('grades', [CurriculumController::class, 'grades']);
    Route::get('grades/{grade}/subjects', [CurriculumController::class, 'subjects']);
    Route::get('subjects/{subject}/textbooks', [CurriculumController::class, 'textbooks']);
    Route::get('textbooks/{textbook}/lessons', [CurriculumController::class, 'lessons']);
    Route::get('lessons/{lesson}', [CurriculumController::class, 'lesson']);
    Route::get('lessons/{lesson}/pages/{page}', [CurriculumController::class, 'pageSource']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('parent/link-child', [AuthController::class, 'linkChild'])
            ->middleware('role:parent');

        // AI agents: catalogue, run, and history. Staff-only agents are gated
        // inside the controller; learner agents (explainer, solver, narrator)
        // are open to any authenticated user.
        Route::get('agents', [AgentController::class, 'index']);
        Route::get('agents/targets', [AgentController::class, 'targets']);
        Route::get('agents/runs', [AgentController::class, 'runs']);
        Route::post('agents/{key}/run', [AgentController::class, 'run']);

        // AI teacher (any authenticated learner can ask about a lesson).
        Route::post('lessons/{lesson}/explain', [AiTeacherController::class, 'explain']);
        Route::post('lessons/{lesson}/ask', [AiTeacherController::class, 'ask']);
        Route::get('lessons/{lesson}/summary', [AiTeacherController::class, 'summary']);

        // Audio narration.
        Route::post('lessons/{lesson}/narrate', [AudioController::class, 'narrate']);

        // Exercises: students submit; hints/solutions are reference content.
        Route::get('lessons/{lesson}/exercises', [ExerciseController::class, 'index']);
        Route::get('exercises/{exercise}/hint', [ExerciseController::class, 'hint']);
        Route::get('exercises/{exercise}/solution', [ExerciseController::class, 'solution']);
        Route::post('exercises/{exercise}/ai-solution', [ExerciseController::class, 'aiSolution']);
        Route::post('exercises/{exercise}/submit', [ExerciseController::class, 'submit'])
            ->middleware('role:student');

        // Student progress.
        Route::get('progress', [ProgressController::class, 'index'])->middleware('role:student');
        Route::post('lessons/{lesson}/track', [ProgressController::class, 'track'])->middleware('role:student');

        // Games.
        Route::get('games', [GameController::class, 'index'])->middleware('role:student');
        Route::post('games/{game}/complete', [GameController::class, 'complete'])->middleware('role:student');
        Route::get('achievements', [GameController::class, 'achievements'])->middleware('role:student');

        // Parent dashboard.
        Route::get('parent/dashboard', [ParentController::class, 'dashboard'])->middleware('role:parent');

        // Teacher / supervisor: content review and authoring.
        Route::middleware('role:owner,teacher')->group(function () {
            Route::post('textbooks', [TeacherController::class, 'createTextbook']);
            Route::post('textbooks/{textbook}/upload-pdf', [TeacherController::class, 'uploadPdf']);
            Route::post('textbooks/{textbook}/upload-page', [TeacherController::class, 'uploadPage']);
            Route::get('textbooks/{textbook}/pages', [TeacherController::class, 'pages']);
            Route::post('textbooks/{textbook}/draft-lessons', [TeacherController::class, 'draftLessons']);
            Route::post('textbooks/{textbook}/publish', [TeacherController::class, 'publishTextbook']);
            Route::patch('pages/{page}', [TeacherController::class, 'updatePage']);
            Route::post('textbooks/{textbook}/lessons', [TeacherController::class, 'createLesson']);
            Route::post('lessons/{lesson}/sections', [TeacherController::class, 'createSection']);
            Route::post('lessons/{lesson}/exercises', [TeacherController::class, 'createExercise']);
            Route::post('exercises/{exercise}/verify', [TeacherController::class, 'verifyExercise']);
            Route::post('exercises/{exercise}/suggest-solution', [TeacherController::class, 'suggestSolution']);
            Route::post('lessons/{lesson}/suggest-solutions', [TeacherController::class, 'suggestLessonSolutions']);
            Route::post('lessons/{lesson}/generate-summary', [TeacherController::class, 'generateSummary']);
            Route::post('summaries/{summary}/approve', [TeacherController::class, 'approveSummary']);
            Route::get('teacher/struggling-lessons', [TeacherController::class, 'strugglingLessons']);
        });

        // Owner / admin only.
        Route::middleware('role:owner')->group(function () {
            Route::get('owner/overview', [OwnerController::class, 'overview']);
            Route::get('owner/users', [OwnerController::class, 'users']);
            Route::post('owner/users', [OwnerController::class, 'storeUser']);
            Route::patch('owner/users/{user}', [OwnerController::class, 'updateUser']);
            Route::post('owner/assign-role', [OwnerController::class, 'assignRole']);
            Route::get('owner/roles', [OwnerController::class, 'roles']);
            Route::get('owner/ai-usage', [OwnerController::class, 'aiUsage']);
            Route::get('owner/settings', [OwnerController::class, 'settings']);
            Route::post('owner/settings', [OwnerController::class, 'updateSettings']);
            Route::get('owner/errors', [OwnerController::class, 'errors']);
            Route::post('owner/assign-teacher', [TeacherController::class, 'assignTeacher']);
        });
    });
});
