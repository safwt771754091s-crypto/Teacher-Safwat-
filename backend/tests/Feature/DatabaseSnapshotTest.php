<?php

namespace Tests\Feature;

use App\Listeners\SnapshotBeforeDestructiveCommand;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\User;
use App\Services\DatabaseSnapshotService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
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
        config([
            'platform.db_snapshots.path' => $this->dir,
            // phpunit.xml disables the safety net (DB_SNAPSHOT_BEFORE=0); enable
            // it per-test so the listener logic can be exercised.
            'platform.db_snapshots.before_destructive' => true,
        ]);
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

    // --- automatic pre-command safety net ------------------------------------

    public function test_it_snapshots_before_a_destructive_command(): void
    {
        $this->realListener()->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new BufferedOutput));

        $snapshots = app(DatabaseSnapshotService::class)->list();
        $this->assertCount(1, $snapshots);
        $this->assertSame('auto pre-migrate:fresh', $snapshots[0]['label']);
    }

    public function test_it_skips_non_destructive_commands(): void
    {
        $this->realListener()->handle(new CommandStarting('migrate', new ArrayInput([]), new BufferedOutput));

        $this->assertCount(0, app(DatabaseSnapshotService::class)->list());
    }

    public function test_it_can_be_disabled_by_config(): void
    {
        config(['platform.db_snapshots.before_destructive' => false]);

        $this->realListener()->handle(new CommandStarting('db:seed', new ArrayInput([]), new BufferedOutput));

        $this->assertCount(0, app(DatabaseSnapshotService::class)->list());
    }

    public function test_it_keeps_only_the_newest_automatic_snapshots(): void
    {
        config(['platform.db_snapshots.pre_command_keep' => 2]);
        $service = app(DatabaseSnapshotService::class);
        $listener = $this->realListener();

        foreach (range(1, 3) as $i) {
            sleep(1); // distinct timestamp in the file name
            $listener->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new BufferedOutput));
        }

        $automatic = array_values(array_filter(
            $service->list(),
            fn ($s) => str_starts_with((string) $s['label'], 'auto pre-')
        ));
        $this->assertCount(2, $automatic);
    }

    public function test_it_does_not_snapshot_when_there_are_no_tables(): void
    {
        // A brand-new/empty database has nothing worth saving.
        $service = new class extends DatabaseSnapshotService
        {
            public function tableTotal(): int
            {
                return 0;
            }
        };

        (new SnapshotBeforeDestructiveCommand($service))
            ->handle(new CommandStarting('migrate:fresh', new ArrayInput([]), new BufferedOutput));

        $this->assertCount(0, app(DatabaseSnapshotService::class)->list());
    }

    private function realListener(): SnapshotBeforeDestructiveCommand
    {
        return new SnapshotBeforeDestructiveCommand(app(DatabaseSnapshotService::class));
    }
}
