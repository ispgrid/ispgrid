<?php

namespace App\Actions\Radius;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Restarts FreeRADIUS with the command from config/radius.php. Logins in the 1 to 2 seconds of a restart
 * fail and the routers retry, so call it once per save, after the commit.
 */
final class RestartFreeRadius
{
    /**
     * @return string|null null when FreeRADIUS restarted, otherwise the reason, for staff
     */
    public function handle(): ?string
    {
        $command = trim((string) config('radius.restart_command'));

        if ($command === '') {
            return __('Restarting FreeRADIUS is switched off (RADIUS_RESTART_COMMAND is empty). Restart it by hand.');
        }

        try {
            $result = Process::timeout(30)->run($command);
        } catch (ProcessTimedOutException) {
            return __('FreeRADIUS did not restart within 30 seconds.');
        }

        if ($result->failed()) {
            $detail = trim($result->errorOutput()) ?: trim($result->output()) ?: 'exit code '.$result->exitCode();

            return __('FreeRADIUS did not restart: :detail', ['detail' => Str::limit($detail, 200)]);
        }

        return null;
    }
}
