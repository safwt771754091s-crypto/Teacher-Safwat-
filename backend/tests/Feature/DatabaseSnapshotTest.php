<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\User;
use App\Services\DatabaseSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Snapshots are exercised against SQLite because the portable dumper needs no
 * external binary there; the pg_dump/mysqldump paths are thin process wrappers.
 * Run with: php artisan test --group=sqlite-snapshots
 */
#[Group('sqlite-snapshots')]
class DatabaseSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/db-snapshots-' . uniqid());
        @mkdir($this->dir, 0775, true);
        config(['platform.db_snapshots.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::where('email', 'owner@school.ye')->firstOrFail();
    }

    public function test_owner_can_create_and_list_snapshots(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/api/v1/owner/db-snapshots', ['label' => 'اختبار'])
            ->assertCreated()
            ->assertJsonPath('snapshot.label', 'اختبار')
            ->assertJsonStructure(['snapshot' => ['name', 'created_at', 'sha256', 'tables', 'rows']]);

        $this->actingAs($this->owner())
            ->getJson('/api/v1/owner/db-snapshots')
            ->assertOk()
            ->assertJsonPath('snapshots.0.label', 'اختبار')
            ->assertJsonPath('driver', 'sqlite');
    }

    public function test_snapshot_is_a_restorable_copy_of_the_database(): void
    {
        $snapshots = app(DatabaseSnapshotService::class);
        $meta = $snapshots->create(label: 'round-trip');

        $users = User::count();
        $lessons = Lesson::count();
        $this->assertGreaterThan(0, $users);
        $this->assertGreaterThan(0, $lessons);

        // Wipe data, then restore and confirm everything came back.
        User::query()->delete();
        Lesson::query()->delete();
        $this->assertSame(0, User::count());

        $snapshots->restore($snapshots->path($meta['name']));

        $this->assertSame($users, User::count());
        $this->assertSame($lessons, Lesson::count());
        $this->assertNotNull(User::where('email', 'owner@school.ye')->first());
    }

    public function test_prune_keeps_only_the_newest_snapshots(): void
    {
        $snapshots = app(DatabaseSnapshotService::class);
        $snapshots->create(label: 'one');
        sleep(1); // distinct timestamp in the file name
        $snapshots->create(label: 'two');

        $removed = $snapshots->prune(1);

        $this->assertCount(1, $removed);
        $this->assertCount(1, $snapshots->list());
    }

    public function test_snapshot_name_is_path_traversal_safe(): void
    {
        $snapshots = app(DatabaseSnapshotService::class);
        $this->assertNull($snapshots->path('../../etc/passwd'));
        $this->assertNull($snapshots->path('evil.sql'));
        $this->assertNull($snapshots->path(''));
    }

    public function test_non_owner_cannot_manage_snapshots(): void
    {
        $student = User::where('email', 'student@school.ye')->firstOrFail();

        $this->actingAs($student)->getJson('/api/v1/owner/db-snapshots')->assertForbidden();
        $this->actingAs($student)->postJson('/api/v1/owner/db-snapshots', [])->assertForbidden();
        $this->actingAs($student)->postJson('/api/v1/owner/db-snapshots/restore', ['name' => 'x.sql.gz'])->assertForbidden();
    }

    public function test_restoring_a_missing_snapshot_returns_404(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/api/v1/owner/db-snapshots/restore', ['name' => 'db_snapshot_2000-01-01-000000.sql.gz'])
            ->assertNotFound();
    }

    public function test_owner_can_download_a_snapshot(): void
    {
        $snapshots = app(DatabaseSnapshotService::class);
        $meta = $snapshots->create();

        $this->actingAs($this->owner())
            ->get('/api/v1/owner/db-snapshots/download?name=' . $meta['name'])
            ->assertOk()
            ->assertHeader('content-type', 'application/gzip');
    }
}
