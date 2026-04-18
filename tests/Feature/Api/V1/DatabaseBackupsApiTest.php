<?php

declare(strict_types=1);

use App\DAL\Maintenance\DatabaseBackupRepository;
use App\Models\DatabaseBackup;
use App\Services\Maintenance\DatabaseBackupManager;
use App\Services\Maintenance\DatabaseRestore;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('lists database backups', function (): void {
    DatabaseBackup::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090001',
        'driver' => 'mysql',
        'filename' => 'test.sql',
        'storage_path' => 'backups/test.sql',
        'description' => 'Test backup',
        'created_by' => 'manual',
        'size_bytes' => 123,
    ]);

    $res = $this->getJson('/api/v1/maintenance/db-backups?limit=5');
    $res->assertOk()
        ->assertJsonPath('data.0.uuid', '00000000-0000-0000-0000-000000090001')
        ->assertJsonPath('data.0.description', 'Test backup');
});

it('creates database backup with description', function (): void {
    $this->mock(DatabaseBackupManager::class, function ($mock): void {
        $mock->shouldReceive('create')
            ->once()
            ->andReturn(new DatabaseBackup([
                'uuid' => '00000000-0000-0000-0000-000000090010',
                'driver' => 'mysql',
                'filename' => 'x.sql',
                'storage_path' => 'backups/x.sql',
                'description' => 'Manual backup',
                'created_by' => 'manual',
            ]));
    });

    $res = $this->postJson('/api/v1/maintenance/db-backups', ['description' => 'Manual backup']);
    $res->assertStatus(201)->assertJsonPath('data.uuid', '00000000-0000-0000-0000-000000090010');
});

it('returns 404 when restoring unknown backup uuid', function (): void {
    $this->mock(DatabaseBackupRepository::class, function ($mock): void {
        $mock->shouldReceive('findByUuidOrFail')
            ->andThrow(new ModelNotFoundException);
    });

    $this->mock(DatabaseRestore::class, function ($mock): void {
        $mock->shouldNotReceive('restore');
    });

    $res = $this->postJson('/api/v1/maintenance/db-backups/restore', [
        'backup_uuid' => '00000000-0000-0000-0000-000000090099',
    ]);
    $res->assertStatus(404);
});

it('validates db backup create payload', function (): void {
    $res = $this->postJson('/api/v1/maintenance/db-backups', ['created_by' => 'nope']);
    $res->assertStatus(422);
});
