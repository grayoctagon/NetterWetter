<?php

/**
 * Simple example script to fetch daily and hourly weather data from Tomorrow.io
 * for one or more locations, storing the responses plus a minimized record.
 *
 * Schedule it hourly via cron, for example:
 *    0 * * * * /usr/bin/php /path/to/your_script.php
 *
 * NOTE: This is a bare-bones example. In production, add proper error handling,
 *       logging, and robust checks around JSON/file operations.
 * 
 * settings.json might look like:
 * {
 *   "apiKey": "abcdabcdabcdabcdabcdabcdabcdabcd",
 *   "locations": ["48.2556667,16.3995556"],
 *   "sleepSeconds": 2
 * }
 * 
 * manage Keys on: https://app.tomorrow.io/development/keys
 */

// -----------------------------------------------------------------------------
// 1. Load settings from JSON
// -----------------------------------------------------------------------------
$settingsFile = __DIR__ . '/data/settings.json';
if (!file_exists($settingsFile)) {
    die("Missing settings.json!\n");
}
$settings = json_decode(file_get_contents($settingsFile), true);
if (!$settings) {
    die("Could not parse settings.json!\n");
}

$apiKey       = $settings['apiKey']       ?? '';
$locations    = $settings['locations']    ?? [];
$sleepSeconds = $settings['sleepSeconds'] ?? 5;

if (!$apiKey || empty($locations)) {
    die("API key or locations are not configured properly in settings.json!\n");
}

// -----------------------------------------------------------------------------
// 2. Check if we've already done the "daily" request for sunrise/sunset today
// -----------------------------------------------------------------------------
$lastDailyFile = __DIR__ . '/last_daily_check.txt';
$today = gmdate('Y-m-d'); // Or local date('Y-m-d') if you prefer local time
$doDaily = false;

if (!file_exists($lastDailyFile)) {
    // If file doesn't exist, we haven't done daily yet
    $doDaily = true;
} else {
    $lastDaily = trim(file_get_contents($lastDailyFile));
    if ($lastDaily !== $today) {
        // If the stored date is different than today, do daily again
        $doDaily = true;
    }
}

// -----------------------------------------------------------------------------
// 3. Prepare file paths for the input + minimized data JSON files
//    e.g. data/2025/weather2025_03_input.json
// -----------------------------------------------------------------------------
$year  = gmdate('Y'); // or date('Y')
$month = gmdate('m'); // or date('m')

@mkdir(__DIR__ . "/data/$year", 0777, true); // Ensure directory exists

$inputFilePath     = __DIR__ . "/data/$year/weather{$year}_{$month}_input.json";
$minimizedFilePath = __DIR__ . "/data/$year/weather{$year}_{$month}_minimized.json";

// -----------------------------------------------------------------------------
// 4. Load existing data from input + minimized JSON, or initialize
// -----------------------------------------------------------------------------
$inputData = [
    'responses' => []
];
if (file_exists($inputFilePath)) {
    $json = json_decode(file_get_contents($inputFilePath), true);
    if (is_array($json)) {
        $inputData = $json;
    }
}

// The minimized structure looks like:
// { "dataforlocations": { "lat,lon": [ {startTime: "...", ...}, ... ] } }
$minimizedData = [
    'dataforlocations' => []
];
if (file_exists($minimizedFilePath)) {
    $json = json_decode(file_get_contents($minimizedFilePath), true);
    if (is_array($json)) {
        $minimizedData = $json;
    }
}

// -----------------------------------------------------------------------------
// 5. Helper function to merge intervals from an API response into the
//    minimized structure, only overwriting the new attributes
// -----------------------------------------------------------------------------
function mergeApiData(array &$minimizedData, string $location, array $apiResponse): void
{
    // Ensure the sub-array for this location exists
    if (!isset($minimizedData['dataforlocations'][$location])) {
        $minimizedData['dataforlocations'][$location] = [];
    }

    // Navigate to "timelines" => [0 or more] => "intervals"
    // Example path: $apiResponse["data"]["timelines"][0]["intervals"]
    if (
        !isset($apiResponse['data']['timelines']) ||
        !is_array($apiResponse['data']['timelines'])
    ) {
        return; // No timeline data
    }

    foreach ($apiResponse['data']['timelines'] as $timeline) {
        if (!isset($timeline['intervals']) || !is_array($timeline['intervals'])) {
            continue;
        }
        foreach ($timeline['intervals'] as $interval) {
            if (!isset($interval['startTime']) || !isset($interval['values'])) {
                continue;
            }
            $startTime = $interval['startTime'];
            $values    = $interval['values']; // array of attributes (e.g. temperature, sunriseTime, etc.)

            // Check if we already have an entry for this startTime
            $existingIndex = null;
            foreach ($minimizedData['dataforlocations'][$location] as $idx => $entry) {
                if (isset($entry['startTime']) && $entry['startTime'] === $startTime) {
                    $existingIndex = $idx;
                    break;
                }
            }

            // If no existing entry, create one
            if ($existingIndex === null) {
                $newEntry = ['startTime' => $startTime];
                // Merge all non-null new values
                foreach ($values as $k => $v) {
                    // If the new value is not null, store it
                    if ($v !== null) {
                        $newEntry[$k] = $v;
                    }
                }
                $minimizedData['dataforlocations'][$location][] = $newEntry;
            } else {
                // Overwrite only the new attributes in the existing entry
                foreach ($values as $k => $v) {
                    if ($v !== null) {
                        $minimizedData['dataforlocations'][$location][$existingIndex][$k] = $v;
                    }
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 6. Helper function to make an API request
// -----------------------------------------------------------------------------
function makeApiRequest(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $httpCode >= 400) {
        // In a real script, log an error or throw an exception
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        // Could not decode JSON
        return null;
    }

    return $decoded;
}

// -----------------------------------------------------------------------------
// 7. Main logic: for each location, do daily request (if needed), do hourly request
// -----------------------------------------------------------------------------
$nowIso = gmdate('Y-m-d\TH:i:s\Z'); // e.g. "2025-04-02T03:00:00Z"

foreach ($locations as $location) {

    // If we haven't done daily today, do that first
    if ($doDaily) {
        $dailyFields = 'sunriseTime,sunsetTime';
        $dailyUrl = sprintf(
            'https://api.tomorrow.io/v4/timelines?location=%s&fields=%s&timesteps=1d&units=metric&startTime=now&endTime=nowPlus5d&apikey=%s',
            urlencode($location),
            urlencode($dailyFields),
            urlencode($apiKey)
        );

        $dailyResponse = makeApiRequest($dailyUrl);
        if ($dailyResponse !== null) {
            // Add to input file structure
            $inputData['responses'][] = [
                'requesttime'        => $nowIso,
                'querriedAttributes' => $dailyFields,
                'receiveddata'       => $dailyResponse,
            ];
            // Merge to minimized
            mergeApiData($minimizedData, $location, $dailyResponse);
        }

        // Wait a bit between requests (if you have multiple locations or next request)
        sleep($sleepSeconds);
    }

    // Then do the hourly request
    $hourlyFields = 'temperature,temperatureApparent,humidity,windGust,windSpeed,uvIndex,rainIntensity,precipitationIntensity';
    $hourlyUrl = sprintf(
        'https://api.tomorrow.io/v4/timelines?location=%s&fields=%s&timesteps=1h&units=metric&startTime=now&endTime=nowPlus5d&apikey=%s',
        urlencode($location),
        urlencode($hourlyFields),
        urlencode($apiKey)
    );

    $hourlyResponse = makeApiRequest($hourlyUrl);
    if ($hourlyResponse !== null) {
        // Add to input file structure
        $inputData['responses'][] = [
            'requesttime'        => $nowIso,
            'querriedAttributes' => $hourlyFields,
            'receiveddata'       => $hourlyResponse,
        ];
        // Merge to minimized
        mergeApiData($minimizedData, $location, $hourlyResponse);
    }

    // Sleep between each location's requests
    sleep($sleepSeconds);
}

// -----------------------------------------------------------------------------
// 8. If we performed the daily request, mark it done for today
// -----------------------------------------------------------------------------
if ($doDaily) {
    file_put_contents($lastDailyFile, $today);
}

// -----------------------------------------------------------------------------
// 9. Save back to disk
// -----------------------------------------------------------------------------
file_put_contents($inputFilePath, json_encode($inputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($minimizedFilePath, json_encode($minimizedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Done.\n";
?>