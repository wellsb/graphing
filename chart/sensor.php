<?php
/**
 * This script reads system information from /proc and system logs,
 * extracts key metrics, and returns them as a JSON object.
 */

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

/**
 * Reads the auth.log file and counts failed login attempts in the last hour.
 * @param int $tailLines The number of lines to read from the end of the file.
 * @return array An array containing the log lines and the count of failed logins.
 */
function getAuthLogInfo(int $tailLines = 50): array
{
    $logPath = '/var/log/auth.log';
    $result = [
        'authLog' => ["Log file '$logPath' is not readable. Check permissions."],
        'failedLoginsLastHour' => null
    ];

    if (!is_readable($logPath)) {
        return $result;
    }

    // Efficiently get the last N lines of the file
    $logLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $result['authLog'] = array_slice($logLines, -$tailLines);

    // Count failed logins in the last hour
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

    $result['failedLoginsLastHour'] = $failedCount;
    return $result;
}

// ... (The getCpuUsage function remains the same) ...
function getCpuUsage(): ?float
{
    // Read initial stats from /proc/stat
    $stat1 = @file_get_contents('/proc/stat');
    if ($stat1 === false) return null;

    // The first line is the aggregate CPU stats
    $lines1 = explode("\n", $stat1);
    $cpuLine1 = $lines1[0];
    // user, nice, system, idle, iowait, irq, softirq, steal, guest, guest_nice
    $fields1 = sscanf($cpuLine1, "cpu %f %f %f %f %f %f %f %f %f %f");
    if ($fields1 === false || count($fields1) < 4) return null;

    // Sleep for a short interval (e.g., 400ms). This is crucial for a meaningful reading.
    usleep(400000);

    // Read final stats
    $stat2 = @file_get_contents('/proc/stat');
    if ($stat2 === false) return null;
    $lines2 = explode("\n", $stat2);
    $cpuLine2 = $lines2[0];
    $fields2 = sscanf($cpuLine2, "cpu %f %f %f %f %f %f %f %f %f %f");
    if ($fields2 === false || count($fields2) < 4) return null;

    // Calculate deltas
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
    $meminfoPath = '/proc/meminfo';
    $loadavgPath = '/proc/loadavg';

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
        'failedLoginsLastHour' => null,
    ];

    // ... (The rest of the function is mostly the same, just adding the new call) ...

    // --- Get Auth Log Info ---
    $authInfo = getAuthLogInfo();
    $data['authLog'] = $authInfo['authLog'];
    $data['failedLoginsLastHour'] = $authInfo['failedLoginsLastHour'];

    // --- Get CPU Usage ---
    $data['cpuUsage'] = getCpuUsage();

    // --- Read Memory Info ---
    $meminfoContent = @file_get_contents($meminfoPath);
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
    $loadavgContent = @file_get_contents($loadavgPath);
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