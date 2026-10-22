<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Services\Operators\OffsiteBackup;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller as BaseController;

/**
 * The backups on the other server: where they go, when, and how the last
 * runs went. Restoring is done on the command line (xgp:backup:restore).
 */
class BackupsController extends BaseController
{
    public function __construct(private OffsiteBackup $backup)
    {
    }

    public function index(): View
    {
        $repository = config('offsite.repository');
        $time = config('offsite.time');

        return view('operator.backups', [
            'configured' => $this->backup->configured(),
            // without the password: an s3:/sftp: address names no secret
            'repository' => is_string($repository) ? $repository : '',
            'time' => is_string($time) ? $time : '',
            'databases' => $this->backup->databases(),
            'runs' => $this->backup->latestRuns(),
        ]);
    }
}
