<?php

namespace Tests\Feature;

use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    public function test_it_creates_gzipped_database_dump_with_configured_mysqldump(): void
    {
        Storage::fake('local');

        $mysqldump = storage_path('framework/testing/fake-mysqldump');
        if (! is_dir(dirname($mysqldump))) {
            mkdir(dirname($mysqldump), 0777, true);
        }

        file_put_contents($mysqldump, <<<'SH'
#!/usr/bin/env sh
printf '%s\n' "-- fake dump"
printf '%s\n' "CREATE TABLE doctors (id int);"
SH);
        chmod($mysqldump, 0755);

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'medical_center',
            'database.connections.mysql.username' => 'db_user',
            'database.connections.mysql.password' => 'secret-password',
            'database_backups.mysqldump_path' => $mysqldump,
        ]);

        $backup = app(DatabaseBackupService::class)->create();

        Storage::disk('local')->assertExists($backup['path']);
        $this->assertStringStartsWith('backups/database/db-backup-', $backup['path']);
        $this->assertStringEndsWith('.sql.gz', $backup['path']);

        $contents = gzdecode(Storage::disk('local')->get($backup['path']));

        $this->assertStringContainsString('CREATE TABLE doctors', $contents);
        $this->assertStringNotContainsString('secret-password', $contents);
    }
}
