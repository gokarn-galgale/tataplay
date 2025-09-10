<?php

include 'app/functions.php';
// Include the streaming library, e.g., via Composer's autoloader
// require 'vendor/autoload.php';

use Streaming\FFMpeg;
use Streaming\Representation;
use FFMpeg\FFMpeg as BaseFFMpeg;
use FFMpeg\Format\Video\X264;

// Your existing login and authentication logic
if (!$id) {http_response_code(400);echo 'Missing content ID.';exit;}
if (!file_exists($loginFilePath)) {http_response_code(401);echo 'Login required.';exit;}
$loginData = json_decode(file_get_contents($loginFilePath), true);
if (!isset($loginData['data']['subscriberId']) || !isset($loginData['data']['userAuthenticateToken'])) {http_response_code(403);echo 'Invalid login data.';exit;}
$subscriberId = $loginData['data']['subscriberId'];
$userToken = $loginData['data']['userAuthenticateToken'];
$cacheData = file_exists($cachePath) ? json_decode(file_get_contents($cachePath), true) : [];
$useCache = false;
$is_timeshift = isset($_GET['timeshift']);

// --- Begin more robust stream fetching logic ---

// Determine the correct content API URL based on timeshift
if ($is_timeshift) {
    $start_time = $_GET['start'] ?? '';
    $end_time = $_GET['end'] ?? '';
    if (!$start_time || !$end_time) {
        http_response_code(400);
        echo "Missing start or end time for timeshift.";
        exit;
    }
    $content_api = "https://tb.tapi.videoready.tv/content-detail/api/partner/cdn/player/details/chotiluli/$id?type=timeshift&time_start=$start_time&time_end=$end_time";
    $cache_key = $id . '_timeshift_' . $start_time . '_' . $end_time;
} else {
    $content_api = 'https://tb.tapi.videoready.tv/content-detail/api/partner/cdn/player/details/chotiluli/' . $id;
    $cache_key = $id;
}

// Check cache for a valid stream URL before making an API call
if (isset($cacheData[$cache_key])) {
    $cachedUrl = $cacheData[$cache_key]['url'];
    $parsedUrl = parse_url($cachedUrl, PHP_URL_QUERY);
    parse_str($parsedUrl, $queryParams);
    $exp = $queryParams['hdntl']['exp'] ?? ($queryParams['exp'] ?? null); // Handle different token formats
    if ($exp && is_numeric($exp) && time() < (int)$exp) {
        $mpdurl = $cachedUrl;
        $useCache = true;
    }
}

// If no valid URL is in the cache, fetch a new one
if (!$useCache) {
    // Set up headers for the API request
    $headers = ['Authorization: Bearer ' . $userToken,'subscriberId: ' . $subscriberId,];
    $context = stream_context_create(['http' => ['method' => 'GET','header' => implode("\r\n", $headers),],]);
    $response = @file_get_contents($content_api, false, $context);
    
    // Check if the API request was successful
    if ($response === false) { 
        http_response_code(500); 
        echo 'Failed to fetch content data from API.'; 
        exit; 
    }
    
    $responseData = json_decode($response, true);
    if (!isset($responseData['data']['dashPlayreadyPlayUrl'])) { 
        http_response_code(404); 
        echo 'dashPlayreadyPlayUrl not found in API response.'; 
        exit;
    }
    
    // Decrypt the URL and resolve the redirect to get the final MPD URL
    $encrypteddashUrl = $responseData['data']['dashPlayreadyPlayUrl'];
    $decryptedUrl = decryptUrl($encrypteddashUrl, $aesKey);
    $decryptedUrl = str_replace('bpaicatchupta', 'bpaita', $decryptedUrl);
    
    // Perform a HEAD request to get the final redirected URL
    $getheaders = get_headers($decryptedUrl, 1, stream_context_create(['http' => ['method' => 'HEAD','header' => "User-Agent: $ua\r\n",'follow_location' => 0,'ignore_errors' => true]]));
    
    if (!$getheaders || !isset($getheaders['Location'])) {
        header("Location: $decryptedUrl", true, 302);
        exit;
    }
    $location = is_array($getheaders['Location']) ? end($getheaders['Location']) : $getheaders['Location'];
    $mpdurl = strpos($location, '&') !== false ? substr($location, 0, strpos($location, '&')) : $location;
    
    // Save the new URL to the cache
    $cacheData[$cache_key] = ['url' => $mpdurl, 'updated_at' => time()];
    file_put_contents($cachePath, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Use FFmpeg library to process the manifest and get PSSH
// Note: This part assumes you have FFmpeg and FFprobe binaries installed on your server
// and have configured the PHP library to find them.
try {
    // Instead of using simple file_get_contents, a library can open the stream more reliably
    // and provide more options for processing.
    $ffmpeg = FFMpeg::create(); // Or with config: FFMpeg::create($config);
    $stream = $ffmpeg->open($mpdurl);
    
    // The library's internal functions would handle manifest parsing and PSSH extraction
    $GetPssh = $stream->get_pssh(); // Fictional method to get PSSH data from the stream
    
    // Get the manifest content
    $mpdContent = file_get_contents($mpdurl);
    if ($mpdContent === false) {http_response_code(500);echo 'Failed to fetch MPD content.';exit;}
    
    $baseUrl = dirname($mpdurl);
    $processedManifest = str_replace('dash/', "$baseUrl/dash/", $mpdContent);
    
    if ($GetPssh) {
        // Embed the PSSH data into the manifest
        $processedManifest = str_replace('mp4protection:2011', 'mp4protection:2011" cenc:default_KID="' . $GetPssh['kid'], $processedManifest);
        $processedManifest = str_replace('" value="PlayReady"/>', '"><cenc:pssh>' . $GetPssh['pr_pssh'] . '</cenc:pssh></ContentProtection>', $processedManifest);
        $processedManifest = str_replace('" value="Widevine"/>', '"><cenc:pssh>' . $GetPssh['pssh'] . '</cenc:pssh></ContentProtection>', $processedManifest);
    }
    
    // Set headers and output the manifest
    header('Content-Type: application/dash+xml');
    header('Content-Disposition: attachment; filename="tp' . urlencode($id) . '.mpd"');
    echo $processedManifest;

} catch (\Exception $e) {
    // Log the error for debugging
    error_log("Streaming error: " . $e->getMessage());
    http_response_code(500);
    echo "An error occurred while processing the stream.";
    exit;
}
