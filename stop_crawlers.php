<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';

$messages = [];
$stoppedSomething = false;

/**
 * Send a signal to a PID (POSIX if available; fallback to shell kill).
 */
function send_signal($pid, $signal)
{
    $pid = (int)$pid;
    $signal = (int)$signal;
    if ($pid <= 1) return false;

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, $signal);
    }

    // Fallback (works if exec/kill is permitted)
    $cmd = 'kill -' . $signal . ' ' . $pid . ' 2>&1';
    @exec($cmd, $out, $code);
    return ($code === 0);
}

/**
 * Check if PID appears alive.
 */
function pid_alive($pid)
{
    $pid = (int)$pid;
    if ($pid <= 1) return false;

    if (function_exists('posix_kill')) {
        // Signal 0 = existence check
        return @posix_kill($pid, 0);
    }

    $cmd = 'ps -p ' . $pid . ' 2>&1';
    @exec($cmd, $out, $code);
    return ($code === 0 && count($out) >= 2);
}

// Attempt to stop crawler by PID stored in crawler.lock
$lockFile = __DIR__ . '/crawler.lock';
if (is_file($lockFile)) {
    $pidRaw = trim((string)@file_get_contents($lockFile));
    $pid = (int)$pidRaw;

    if ($pid > 1) {
        $messages[] = "Found crawler.lock PID: {$pid}";

        // Try graceful stop (SIGTERM=15)
        if (send_signal($pid, 15)) {
            $stoppedSomething = true;
            $messages[] = "Sent SIGTERM to PID {$pid}. Waiting...";
            usleep(300000); // 0.3s

            // If still alive, try SIGKILL (9)
            if (pid_alive($pid)) {
                $messages[] = "PID {$pid} still alive; sending SIGKILL.";
                if (send_signal($pid, 9)) {
                    $messages[] = "Sent SIGKILL to PID {$pid}.";
                } else {
                    $messages[] = "Failed to send SIGKILL to PID {$pid}.";
                }
            } else {
                $messages[] = "PID {$pid} stopped.";
            }
        } else {
            $messages[] = "Failed to send SIGTERM to PID {$pid} (may already be stopped or permissions).";
        }
    } else {
        $messages[] = "crawler.lock exists but PID invalid: '{$pidRaw}'";
    }
} else {
    $messages[] = "crawler.lock not found (no PID to stop).";
}

// Remove marker files used by status indicators
$markerFiles = [
    __DIR__ . '/crawler.running',
    __DIR__ . '/crawler.getemails.running',
    __DIR__ . '/getURLS.running',
    __DIR__ . '/addtomautic.running',
];

foreach ($markerFiles as $f) {
    if (is_file($f)) {
        @unlink($f);
        $stoppedSomething = true;
    }
}

// Optionally remove lock file (safe if process is stopped)
if (is_file($lockFile)) {
    @unlink($lockFile);
}

$msg = implode(' ', $messages);
$qs = http_build_query([
    'stopped' => $stoppedSomething ? 1 : 0,
    'msg' => $msg,
]);
header('Location: index.php?' . $qs);
exit;