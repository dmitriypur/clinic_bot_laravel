<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackupService;
use Illuminate\Http\Request;

class DatabaseBackupDownloadController extends Controller
{
    public function __invoke(Request $request, DatabaseBackupService $backupService)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return $backupService->download((string) $request->query('path'));
    }
}
