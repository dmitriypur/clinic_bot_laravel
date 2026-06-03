<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    public function create(): array
    {
        $connectionName = config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Дамп БД поддерживается только для MySQL/MariaDB.');
        }

        $disk = (string) config('database_backups.disk', 'local');
        $directory = trim((string) config('database_backups.directory', 'backups/database'), '/');
        $fileName = 'db-backup-'.now()->format('Y-m-d-H-i-s').'.sql.gz';
        $relativePath = "{$directory}/{$fileName}";
        $tempSqlPath = Storage::disk($disk)->path("{$directory}/.{$fileName}.tmp.sql");
        $targetPath = Storage::disk($disk)->path($relativePath);

        Storage::disk($disk)->makeDirectory($directory);

        $command = $this->buildDumpCommand($connection, $tempSqlPath);

        $process = Process::fromShellCommandline(
            $command,
            base_path(),
            ['MYSQL_PWD' => (string) ($connection['password'] ?? '')],
            null,
            (float) config('database_backups.timeout', 300)
        );

        try {
            $process->mustRun();
            $this->gzipFile($tempSqlPath, $targetPath);
        } catch (ProcessFailedException $exception) {
            throw new RuntimeException('Не удалось создать дамп БД: '.$exception->getProcess()->getErrorOutput(), 0, $exception);
        } finally {
            if (is_file($tempSqlPath)) {
                @unlink($tempSqlPath);
            }
        }

        return [
            'disk' => $disk,
            'path' => $relativePath,
            'file_name' => $fileName,
            'size' => Storage::disk($disk)->size($relativePath),
            'created_at' => now(),
        ];
    }

    public function list(): array
    {
        $disk = (string) config('database_backups.disk', 'local');
        $directory = trim((string) config('database_backups.directory', 'backups/database'), '/');

        return collect(Storage::disk($disk)->files($directory))
            ->filter(fn (string $path): bool => Str::endsWith($path, '.sql.gz'))
            ->map(fn (string $path): array => [
                'disk' => $disk,
                'path' => $path,
                'file_name' => basename($path),
                'size' => Storage::disk($disk)->size($path),
                'last_modified' => Storage::disk($disk)->lastModified($path),
            ])
            ->sortByDesc('last_modified')
            ->values()
            ->all();
    }

    public function exists(string $path): bool
    {
        return Storage::disk((string) config('database_backups.disk', 'local'))->exists($this->normalizePath($path));
    }

    public function download(string $path)
    {
        $disk = (string) config('database_backups.disk', 'local');
        $normalizedPath = $this->normalizePath($path);

        if (! Storage::disk($disk)->exists($normalizedPath)) {
            abort(404, 'Дамп БД не найден.');
        }

        return Storage::disk($disk)->download($normalizedPath, basename($normalizedPath));
    }

    protected function buildDumpCommand(array $connection, string $outputPath): string
    {
        $arguments = [
            (string) config('database_backups.mysqldump_path', '/usr/bin/mysqldump'),
            '--no-tablespaces',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '-h',
            (string) ($connection['host'] ?? '127.0.0.1'),
            '-P',
            (string) ($connection['port'] ?? '3306'),
            '-u',
            (string) ($connection['username'] ?? ''),
        ];

        if (! empty($connection['unix_socket'])) {
            $arguments[] = '--socket';
            $arguments[] = (string) $connection['unix_socket'];
        }

        $arguments[] = (string) ($connection['database'] ?? '');

        return collect($arguments)
            ->map(fn (string $argument): string => escapeshellarg($argument))
            ->implode(' ')
            .' > '.escapeshellarg($outputPath);
    }

    protected function gzipFile(string $sourcePath, string $targetPath): void
    {
        $source = fopen($sourcePath, 'rb');
        $target = gzopen($targetPath, 'wb9');

        if (! $source || ! $target) {
            throw new RuntimeException('Не удалось сжать дамп БД.');
        }

        while (! feof($source)) {
            gzwrite($target, (string) fread($source, 1024 * 1024));
        }

        fclose($source);
        gzclose($target);
    }

    protected function normalizePath(string $path): string
    {
        $directory = trim((string) config('database_backups.directory', 'backups/database'), '/');
        $path = trim($path, '/');

        abort_unless(Str::startsWith($path, "{$directory}/") && Str::endsWith($path, '.sql.gz'), 404);

        return $path;
    }
}
