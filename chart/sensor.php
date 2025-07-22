<?php
/**
 * This script reads system information from /proc and system logs,
 * extracts key metrics, and returns them as a JSON object.
 */

// Prevent direct access
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403); // Forbidden
    die('Direct access is not permitted.');
}

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

/**
 * Tail any log file.
 * @param string $logPath The full path to the log file.
 * @param int $tailLines The number of lines to read from the end.
 * @return array The last N lines of the file or an error message.
 */
function getLogTail(string $logPath, int $tailLines = 50): array
{
    if (!is_readable($logPath)) {
        return ["Log file '$logPath' is not readable. Check permissions."];
    }
    // Efficiently get the last N lines of the file
    return array_slice(file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$tailLines);
}

/**
 * Parses log lines to count failed login attempts in the last hour.
 * @param array $logLines The log lines to analyze.
 * @return int The count of failed logins.
 */
function countFailedLogins(array $logLines): int
{
    $failedCount = 0;
    $oneHourAgo = new DateTime('-1 hour');
    $failurePatterns = [
        'authentication failure',
        'failed password',
        'connection closed by authenticating user',
        'invalid user',
        'disconnected from authenticating user'
    ];
    $regex = '/' . implode('|', $failurePatterns) . '/i';

    foreach ($logLines as $line) {
        // Extract the timestamp (works with formats like "Jul 19 16:18:16" or ISO 8601)
        if (preg_match('/^([a-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2}|[0-9-T:.]+)/i', $line, $matches)) {
            try {
                $logTime = new DateTime($matches[1]);
                // Check if the log entry is recent and matches a failure pattern
                if ($logTime >= $oneHourAgo && preg_match($regex, $line)) {
                    $failedCount++;
                }
            } catch (Exception $e) {
                // Ignore lines with timestamps we can't parse
                continue;
            }
        }
    }
    return $failedCount;
}

// The getCpuUsage() function remains unchanged.
function getCpuUsage(): ?float
{
    $stat1 = @file_get_contents('/proc/stat');
    if ($stat1 === false) return null;
    $lines1 = explode("\n", $stat1);
    $cpuLine1 = $lines1[0];
    $fields1 = sscanf($cpuLine1, "cpu %f %f %f %f %f %f %f %f %f %f");
    if ($fields1 === false || count($fields1) < 4) return null;
    usleep(400000);
    $stat2 = @file_get_contents('/proc/stat');
    if ($stat2 === false) return null;
    $lines2 = explode("\n", $stat2);
    $cpuLine2 = $lines2[0];
    $fields2 = sscanf($cpuLine2, "cpu %f %f %f %f %f %f %f %f %f %f");
    if ($fields2 === false || count($fields2) < 4) return null;
    $total1 = array_sum($fields1);
    $idle1 = $fields1[3];
    $total2 = array_sum($fields2);
    $idle2 = $fields2[3];
    $deltaTotal = $total2 - $total1;
    $deltaIdle = $idle2 - $idle1;
    if ($deltaTotal > 0) {
        $cpuUsage = (1 - ($deltaIdle / $deltaTotal)) * 100;
        return round($cpuUsage, 2);
    }
    return 0.0;
}

/**
 * Main function to gather all system stats.
 * @return array An associative array with system data.
 */
function getSystemStats(): array
{
    $data = [
        'hostname'          => gethostname(),
        'timestamp'         => null,
        'cpuUsage'          => null,
        'memTotal'          => null,
        'memUsed'           => null,
        'memAvailable'      => null,
        'memCached'         => null,
        'memFree'           => null,
        'loadAvg1'          => null,
        'loadAvg5'          => null,
        'loadAvg15'         => null,
        'runningProcesses'  => null,
        'totalProcesses'    => null,
        'lastPid'           => null,
        'diskUsed'          => null,
        'diskFree'          => null,
        'authLog'           => [],
        'syslog'            => [],
        'failedLoginsLastHour' => null,
    ];

    // --- Get Log Info ---
    $authLogLines = getLogTail('/var/log/auth.log');
    $data['authLog'] = $authLogLines;
    $data['failedLoginsLastHour'] = countFailedLogins($authLogLines);
    $data['syslog'] = getLogTail('/var/log/syslog');

    // --- Get CPU Usage ---
    $data['cpuUsage'] = getCpuUsage();

    // --- Read Memory Info ---
    $meminfoContent = @file_get_contents('/proc/meminfo');
    if ($meminfoContent) {
        $memTotal = null;
        if (preg_match('/^MemTotal:\s+(\d+)/m', $meminfoContent, $matches)) {
            $data['memTotal'] = (int)$matches[1];
            $memTotal = $data['memTotal'];
        }
        if (preg_match('/^MemFree:\s+(\d+)/m', $meminfoContent, $matches)) {
            $data['memFree'] = (int)$matches[1];
        }
        if (preg_match('/^MemAvailable:\s+(\d+)/m', $meminfoContent, $matches)) {
            $data['memAvailable'] = (int)$matches[1];
        }
        if (preg_match('/^Cached:\s+(\d+)/m', $meminfoContent, $matches)) {
            $data['memCached'] = (int)$matches[1];
        }
        if ($memTotal !== null && $data['memAvailable'] !== null) {
            $data['memUsed'] = $memTotal - $data['memAvailable'];
        }
    }

    // --- Read Load Average and Process Info ---
    $loadavgContent = @file_get_contents('/proc/loadavg');
    if ($loadavgContent) {
        $parsedLoad = sscanf($loadavgContent, '%f %f %f %d/%d %d');
        if (is_array($parsedLoad) && count($parsedLoad) >= 6) {
            $data['loadAvg1']         = $parsedLoad[0];
            $data['loadAvg5']         = $parsedLoad[1];
            $data['loadAvg15']        = $parsedLoad[2];
            $data['runningProcesses'] = $parsedLoad[3];
            $data['totalProcesses']   = $parsedLoad[4];
            $data['lastPid']          = $parsedLoad[5];
        }
    }

    // --- Read Disk Usage for Root Partition ('/') ---
    $diskTotalBytes = @disk_total_space('/');
    $diskFreeBytes = @disk_free_space('/');
    if ($diskTotalBytes !== false && $diskFreeBytes !== false) {
        $diskUsedBytes = $diskTotalBytes - $diskFreeBytes;
        $data['diskUsed']  = round($diskUsedBytes / 1024);
        $data['diskFree']  = round($diskFreeBytes / 1024);
    }

    // Add a server-generated timestamp
    $data['timestamp'] = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

    return $data;
}

// Execute and output
echo json_encode(getSystemStats(), JSON_PRETTY_PRINT);