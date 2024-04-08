<?php

include_once "/opt/fpp/www/common.php";

define("DEFAULT_DELAY", 2);
define("MAX_DELAY", 15);
define("IDLE_DELAY", 15);
define("FPP_API_BASE_URL", "http://localhost");
define("GUARD_API_BASE_URL", "https://guard.rthservices.net");

function httpRequest($url, $method = "GET", $data = null, $headers = array())
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if ($method === "POST" || $method === "PUT") {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);

    if ($response === false) {
        throw new Exception("cURL error: " . curl_error($ch));
    }

    curl_close($ch);
    return $response;
}

function exponentialBackoffSleep($attempt)
{
    $delay = min(pow(2, $attempt) * DEFAULT_DELAY, MAX_DELAY);
    sleep($delay);
}

$attempt = 0;
while (true) {
    try {
        $apiKey = getSetting("LSG_API_KEY");
        if (empty($apiKey)) {
            throw new Exception("API key setting is not saved");
        }

        $status = json_decode(httpRequest(FPP_API_BASE_URL . "/api/status"), true);

        // Check if the playlist name contains "test" or "offline"
        $currentPlaylist = strtolower($status['current_playlist']);
        if (strpos($currentPlaylist, 'test') !== false || strpos($currentPlaylist, 'offline') !== false) {
            sleep(IDLE_DELAY);
            continue;
        }

        $postData = json_encode($status);
        $guardHeaders = array(
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        );

        // Send status to guard.rthservices.net/shows route via PUT method
        $result = httpRequest(GUARD_API_BASE_URL . "/shows", "PUT", $postData, $guardHeaders);

        $insertCommand = "Insert Playlist After Current";
        if ($result['shutdown'] === true || $result['restart'] === true) {
            $playlistName = $result['shutdown'] === true ? "Shutdown" : "Restart";
            executeFppCommand($insertCommand, array($playlistName, "-1", "-1", "false"));
        } elseif (!empty($result['next_song'])) {
            $nextSong = $result['next_song'];
            $delay = $status['delay'] ?? DEFAULT_DELAY;
            sleep($delay);
            executeFppCommand($insertCommand, array($nextSong, "-1", "-1", "false"));
        } else {
            sleep(IDLE_DELAY);
        }

        $attempt = 0;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exponentialBackoffSleep($attempt);
        $attempt++;
    }
}
