<?php
/*------------------------------------------------------------------------------
 *  Plex → Podcast RSS  (token-per-URL edition)
 *----------------------------------------------------------------------------*/
include 'settings.php';          // defines: $baseurl, $plex_url  (NO TOKEN)
define('PODCAST_MODE', 'concat');   // 'concat' = single long episode

/*  ----------  common helper: read token once  ----------  */
$plex_token = $_GET['token'] ?? '';

/* ---------- Global Configuration & Scrobble Management ---------- */
$scrobble_config = ['deferred_enabled' => true];
$scrobbleQueue = [];

/*  ----------  ROUTING  ----------  */
if (isset($_GET['stream'])) {                     // 1️⃣  continuous MP3
    $id = strtok($_GET['stream'], '.');           // strip fake ".mp3"
    concatPlaylist($id, $plex_token);
    exit;
}
if (isset($_GET['m3u'])) {                        // 2️⃣  segmented M3U
    header('Content-Type: audio/x-mpegurl');
    echo buildM3u($_GET['m3u'], $plex_token);
    exit;
}
if (isset($_GET['plexKey'])) {
    $key       = urldecode($_GET['plexKey']);
    $randomize = urldecode($_GET['randomize']) === 'true';
    processPlaylist($key, $randomize, false, $plex_token);
} elseif (isset($_GET['validate'])) {
    $key = urldecode($_GET['validate']);
    outputHtml();
    processPlaylist($key, false, true, $plex_token);
} elseif (isset($_GET['proxy'])) {               // 3️⃣  per-track proxy
    streamSongProxy($_GET['proxy'], $_GET['f'] ?? '', $_GET['ts'] ?? 0,
                    $_GET['r'] ?? '', $plex_token);
} else {
    outputHtml();
    listPlaylists($plex_token);                    // list page
}


/* ---------- helpers ---------- */
function outputHtml() { echo "<html><head><link rel='stylesheet' href='styles.css'></head><body>"; }

function plexGet(string $endpoint): \SimpleXMLElement
{
    global $plex_url, $plex_token;
    $ctx = stream_context_create([
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        'http' => ['ignore_errors' => true],
    ]);
    $url  = $plex_url . $endpoint . '?X-Plex-Token=' . $plex_token;
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) throw new RuntimeException('Plex unreachable');
    $xml = simplexml_load_string($resp);
    if ($xml === false) throw new RuntimeException('Invalid XML from Plex');
    return $xml;
}

function listPlaylists(): void
{
    global $baseurl, $plex_token;
    try {
        $xml = plexGet('/playlists');
    } catch (RuntimeException $e) {
        exit('Error: '.$e->getMessage());
    }

    $rows = '';                                         // collect rows first
    foreach ($xml->Playlist as $pl) {
        if ((string)$pl['playlistType'] !== 'audio') continue;

        $key = (string)$pl['ratingKey'];
        /*  make sure the playlist has items before we list it  */
        try {
            $items = plexGet('/playlists/'.$key.'/items');
            if (!count($items->Track)) continue;        // empty playlist
        } catch (RuntimeException) { continue; }        // Plex error

        $title = htmlspecialchars($pl['title']);
        $rows .= "<tr>
                    <td>{$title}</td>
                    <td><a href='{$baseurl}?plexKey={$key}&randomize=false&token=".htmlspecialchars($plex_token)."'>⬇️</a></td>
                    <td><a href='{$baseurl}?plexKey={$key}&randomize=true&token=".htmlspecialchars($plex_token)."'>🔀</a></td>
                    <td><a href='{$baseurl}?validate={$key}&token=".htmlspecialchars($plex_token)."'>🤖</a></td>
                  </tr>";
        
    }

    echo '<h1>Plex Playlists</h1><table>
          <thead><tr><td>Playlist</td><td>Ordered</td><td>Random</td><td>Validate</td></tr></thead>
          <tbody>'.($rows ?: '<tr><td colspan="4">No playable playlists</td></tr>').'</tbody></table>';
}

/* ---------- playlist processor ---------- */
function processPlaylist(string $plexKey, bool $randomize, bool $validate): void
{
    try {
        $xml = plexGet('/playlists/'.$plexKey.'/items');
    } catch (RuntimeException $e) {
        echo '<li>Plex error: '.htmlspecialchars($e->getMessage()).'</li>'; return;
    }

    $seen = []; $tracks = [];
    foreach ($xml->Track as $t) {
        /* use *part* id (not track id) to avoid duplicates inside this playlist */
        $media = $t->Media; $part = $media->Part;
        $partId = (int)$part['id'];
        if (isset($seen[$partId])) continue;
        $seen[$partId] = true;

        $tracks[] = [
            'title'     => (string)($t['grandparentTitle'].' - '.$t['title']),
            'ratingKey' => (int)$t['ratingKey'],
            'partId'    => $partId,
            'fileName'  => basename((string)$part['file']),
            'duration'  => (int)($media['duration'] ?? 0),
        ];
    }
    if ($randomize) shuffle($tracks);
    if ($validate) {
        foreach ($tracks as $tr) echo '✅ '.htmlspecialchars($tr['title']).'<br>';
        return;
    }
    buildRssFeed($tracks, (string)$xml['title'], $plexKey);
}

/* ---------- RSS builder with corrected total duration calculation ---------- */
function buildRssFeed(array $tracks, string $playlistTitle, string $playlistId): void
{
    global $baseurl, $plex_token;
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->formatOutput = true;
    $rss = $dom->createElement('rss');
    $rss->setAttribute('version', '2.0');
    $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');

    $channel = $dom->createElement('channel');
    $channel->appendChild($dom->createElement('title', htmlspecialchars($playlistTitle)));
    $channel->appendChild($dom->createElement('itunes:author', 'Plex'));
    $channel->appendChild($dom->createElement('lastBuildDate', date('r')));

    if (PODCAST_MODE === 'concat') {           
        // Calculate total duration using integer division to avoid floats
        $totalDuration = 0;
        foreach ($tracks as $t) {
            $totalDuration += $t['duration']; // Use integer values directly
        }
        $hours = floor($totalDuration / 3600000);
        $minutes = floor(($totalDuration % 3600000) / 60000);
        $seconds = floor(($totalDuration % 60000) / 1000);
        $totalDurationHMS = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
        

        $item = $dom->createElement('item');
        $item->appendChild($dom->createElement('title', htmlspecialchars($playlistTitle)));
        $guid = $dom->createElement('guid', $playlistId);
        $guid->setAttribute('isPermaLink', 'false');
        $item->appendChild($guid);
        $item->appendChild($dom->createElement('pubDate', date('r')));

        /* Add itunes:duration element with total duration */
        $duration = $dom->createElement('itunes:duration', $totalDurationHMS);
        $item->appendChild($duration);

        $enc = $dom->createElement('enclosure');
        $enc->setAttribute('url', $baseurl.'?stream='.$playlistId.'.mp3'.'&token='.urlencode($plex_token));
        $enc->setAttribute('type', 'audio/mpeg');

        /* Optionally: Set Content-Length attribute if totalSize is available */
        if (isset($totalSize)) {
            $enc->setAttribute('length', $totalSize);
        }

        $item->appendChild($enc);
        $channel->appendChild($item);
    } else {                                    
        $totalTracks = count($tracks);
        $episode = 1;
        foreach ($tracks as $t) {
            $item = $dom->createElement('item');
            $item->appendChild($dom->createElement('title', htmlspecialchars($t['title'])));
            $guid = $dom->createElement('guid', $playlistId.'-'.$episode);
            $guid->setAttribute('isPermaLink', 'false');
            $item->appendChild($guid);
            $item->appendChild($dom->createElement('pubDate', date('r', strtotime("-{$episode} minutes"))));
            $item->appendChild($dom->createElement('itunes:episode', $totalTracks - $episode + 1));
            $item->appendChild($dom->createElement('itunes:season', 1));
            $enc = $dom->createElement('enclosure');
            $enc->setAttribute(
                'url',
                $baseurl.'?proxy='.$t['partId'].
                '&f='.urlencode($t['fileName']).
                '&r='.$t['ratingKey'].
                '&token='.urlencode($plex_token)
            );
            $enc->setAttribute('type', 'audio/mpeg');
            $item->appendChild($enc);
            $channel->appendChild($item);
            $episode++;
        }
    }
    $rss->appendChild($channel); 
    $dom->appendChild($rss);
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo $dom->saveXML();
}


/* ---------- M3U builder (kept for backward compat) ---------- */
function buildM3u(string $playlistId): string
{
    global $baseurl;
    $tracks = []; $offsetMs = 0;
    try {
        $xml = plexGet('/playlists/'.$playlistId.'/items');
        foreach ($xml->Track as $t) {
            $media = $t->Media; $part = $media->Part;
            $dur   = (int)($media['duration'] ?? 0);
            $tracks[] = [
                'title'     => (string)($t['grandparentTitle'].' - '.$t['title']),
                'ratingKey' => (int)$t['ratingKey'],
                'partId'    => (int)$part['id'],
                'fileName'  => basename((string)$part['file']),
                'duration'  => $dur,
                'offset'    => $offsetMs,
            ];
            $offsetMs += $dur;
        }
    } catch (RuntimeException) { return "#EXTM3U"; }

    $out = "#EXTM3U\n";
    foreach ($tracks as $t) {
        $url = $baseurl.'?proxy='.$t['partId']
                       .'&f='.urlencode($t['fileName'])
                       .'&ts='.$t['offset']
                       .'&r='.$t['ratingKey'];
        $out.= "#EXTINF:".($t['duration']/1000).",".$t['title']."\n".$url."\n";
    }
    return $out;
}


/* ---------- Scrobble Management Functions ---------- */
// New signature: include duration in ms, start time in seconds, and position offset
// Queue a scrobble with detailed timing information
function queueScrobble(string $ratingKey, int $durationMs, int $startSec, int $positionMs): void
{
    global $scrobbleQueue;
    
    // Check if this track is already in the queue
    foreach ($scrobbleQueue as $existing) {
        if ($existing['key'] === $ratingKey) {
            // Already queued, don't add duplicate
            return;
        }
    }
    
    $scrobbleQueue[] = [
        'key' => $ratingKey,
        'durationMs' => $durationMs,
        'startSec' => $startSec,
        'positionMs' => $positionMs
    ];
}

// Process the scrobble queue with timing logic
function processScrobbleQueue(): void
{
    global $scrobbleQueue, $scrobble_config;
    if (empty($scrobble_config['deferred_enabled'])) return;

    $now = time();
    
    foreach ($scrobbleQueue as $index => $item) {
        // Convert Plex duration (ms) to seconds for API
        $durationSec = (int) ceil($item['durationMs'] / 1000);
        
        // Always scrobble immediately - let the lock file prevent duplicates
        scrobbleOnce($item['key'], $durationSec, $item['positionMs'], $item['startSec']);
        unset($scrobbleQueue[$index]);
    }
}





function concatPlaylist(string $playlistId): void
{
    global $plex_url, $plex_token;

    // Send headers immediately for device compatibility (Light Phone fix)
    header('Content-Type: audio/mpeg');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache');

    $noVerifyCtx = stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $tracks = []; 
    $totalSize = 0;

    try {
        $xml = plexGet('/playlists/' . $playlistId . '/items');
        foreach ($xml->Track as $t) {
            $media = $t->Media;
            $part  = $media->Part;
            $url = "{$plex_url}/library/parts/{$part['id']}/" .
                   rawurlencode(basename((string)$part['file'])) .
                   '?download=1&X-Plex-Token=' . $plex_token;
            $hdr = @get_headers($url, true, $noVerifyCtx);
            $size = (int)($hdr['Content-Length'] ?? 0);

            $tracks[] = [
                'ratingKey' => (string)$t['ratingKey'],
                'duration'  => (int)($media['duration'] ?? 0), // ms
                'size'      => $size,
                'url'       => $url
            ];
            $totalSize += $size;
        }
    } catch (RuntimeException) { 
        http_response_code(404);
        exit('Playlist not found');
    }

    // Setup range handling
    $rangeStart = 0; 
    $rangeEnd = $totalSize - 1;
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $rangeStart = (int)$matches[1];
            $rangeEnd = $matches[2] !== '' ? (int)$matches[2] : $totalSize - 1;
            http_response_code(206);
            header("Content-Range: bytes {$rangeStart}-{$rangeEnd}/{$totalSize}");
        }
    }

    header('Content-Length: ' . ($rangeEnd - $rangeStart + 1));

    $currentPos = 0; 
    $bytesSent = 0;
    $offsetMs = 0;          // cumulative duration in ms

    foreach ($tracks as $track) { 
        $bytes_written = 0;

        $trackSize = $track['size'];
        if ($currentPos + $trackSize <= $rangeStart) {
            $currentPos += $trackSize;
            $offsetMs += (int)($track['duration'] ?? 0);
            continue;
        }

        if ($currentPos > $rangeEnd) break;

        $trackOffset = max($rangeStart - $currentPos, 0);
        $trackEndOffset = min($rangeEnd - $currentPos, $trackSize - 1);
        $trackBytes = $trackEndOffset - $trackOffset + 1;

        if ($trackBytes > 0) {
            $rangeCtx = stream_context_create([
                'http' => ['method' => 'GET', 'header' => "Range: bytes={$trackOffset}-{$trackEndOffset}\r\n"],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);

            $handle = @fopen($track['url'], 'rb', false, $rangeCtx);
            if ($handle) {
                while ($bytes_written < $trackBytes && !feof($handle)) {
                    $chunkSize = min(8192, $trackBytes - $bytes_written);
                    $chunk = fread($handle, $chunkSize);
                    if ($chunk === false) break;
                    echo $chunk;
                    $bytes_written += $chunkSize;
                    @flush();
                    @ob_flush();
                }
                fclose($handle);
            }
        }

        // Queue scrobble for any partially delivered tracks (scrobble on first byte)
        if ($bytes_written > 0) {
            queueScrobble(
                $track['ratingKey'],
                (int)$track['duration'],  // Still in milliseconds (Plex format)
                time(),                   // Current time in seconds (PHP format)
                $offsetMs                 // Position within stream (ms)
            );
        }

        $offsetMs += (int)($track['duration'] ?? 0);
        $bytesSent += $bytes_written;
        $currentPos += $track['size'];
    }

    // Process scrobbles immediately after streaming
    processScrobbleQueue();
}





/* ---------- helper: scrobble a single ratingKey exactly once ---------- */
function scrobbleOnce(string $ratingKey, int $durationSec, int $positionMs = 0, int $startSec = 0): void
{
    static $done = [];
    if (isset($done[$ratingKey])) return;
    
    global $plex_url, $plex_token;
    
    // Use file lock to prevent duplicate scrobbles across parallel requests
    $lockFile = sys_get_temp_dir() . '/plexpod_scrobble_' . md5($plex_url . $plex_token . $ratingKey) . '.lock';
    $lockHandle = @fopen($lockFile, 'c+');
    if (!$lockHandle) {
        error_log('[concat-scrobble] FAILED to open lock file: ' . $lockFile);
        return;
    }
    
    if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        // Another process is scrobbling this track concurrently
        fclose($lockHandle);
        return;
    }
    
    // Check if already scrobbled (with expiry)
    @rewind($lockHandle);
    $lockContent = @fread($lockHandle, 64); // Read first 64 bytes (more than enough for a timestamp)
    if ($lockContent !== false && $lockContent !== '') {
        $scrobbleTime = (int)trim($lockContent);
        // Expire after 3 minutes to allow re-scrobbling same track later
        if ($scrobbleTime > 0 && (time() - $scrobbleTime) < 180) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            error_log('[concat-scrobble] SKIP already-scrobbled track=' . $ratingKey);
            return;
        }
    }
    
    $done[$ratingKey] = true;

    $clientId = 'plex-playlist-podcast-' . md5($plex_url . $plex_token);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Length: 0\r\n"
                       . "User-Agent: PlexPlaylistPodcast/1.0\r\n"
                       . "Accept: */*\r\n"
                       . "X-Plex-Client-Identifier: {$clientId}\r\n",
            'ignore_errors' => true,
            'timeout' => 2,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $posSec = (int) floor($positionMs / 1000);
    // Plex timeline - use seconds for time/duration
    $url = "{$plex_url}/:/timeline?ratingKey={$ratingKey}&key={$ratingKey}"
         . "&state=stopped&time={$durationSec}&duration={$durationSec}"
         . "&X-Plex-Token={$plex_token}"
         . ($positionMs > 0 ? "&position={$posSec}" : "");

    $resp = @file_get_contents($url, false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        preg_match('/HTTP\/[\d.]+\s+(\d+)/', $http_response_header[0], $matches);
        $httpCode = $matches[1] ?? 0;
    }
    error_log('[concat-scrobble] track=' . $ratingKey . ' http=' . $httpCode . ' response=' . substr($resp, 0, 50));
    
    // Write scrobbled marker to prevent duplicate scrobbles
    @rewind($lockHandle);
    @ftruncate($lockHandle, 0);
    @fwrite($lockHandle, time() . "\n");
    
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}



/* ---------- proxy + scrobble ---------- */
function streamSongProxy(string $partId, string $fileName, int $offsetMs = 0, string $ratingKey = ''): void
{
    global $plex_url, $plex_token;

    /* ---------- 1. validate parameters ---------- */
    $partId   = trim($partId);
    if ($partId === '' || !ctype_digit($partId)) { http_response_code(400); exit('Bad request'); }
    $fileName = trim(urldecode($fileName));
    if ($fileName === '') { http_response_code(400); exit('Bad request'); }

    /* ---------- 2. mark as played BEFORE streaming (use Timeline API) ---------- */
    $scrobbleKey = ($ratingKey && ctype_digit($ratingKey)) ? $ratingKey : $partId;

    // Get track duration by fetching metadata
    $trackCtx = stream_context_create([
        'http' => ['ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $trackUrl = "{$plex_url}/library/metadata/{$scrobbleKey}?X-Plex-Token={$plex_token}";
    $trackXml = @simplexml_load_string(@file_get_contents($trackUrl, false, $trackCtx));
    $duration = $trackXml ? (int)($trackXml->Media[0]['duration'] ?? 0) : 0;

    // Send timeline update to mark as played
    $timelineUrl = "{$plex_url}/:/timeline?ratingKey={$scrobbleKey}&key={$scrobbleKey}"
                  . "&state=stopped&time={$duration}&duration={$duration}"
                  . "&X-Plex-Token={$plex_token}";

    $clientId = 'plex-playlist-podcast-' . md5($plex_url . $plex_token);
    $timelineCtx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Length: 0\r\n"
                       . "User-Agent: PlexPlaylistPodcast/1.0\r\n"
                       . "Accept: */*\r\n"
                       . "X-Plex-Client-Identifier: {$clientId}\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $resp = @file_get_contents($timelineUrl, false, $timelineCtx);
    if ($resp === false) {
        error_log('[proxy-timeline] FAIL track='.$scrobbleKey);
    } else {
        $httpCode = isset($http_response_header) ? 
            (preg_match('/HTTP\/[\d.]+\s+(\d+)/', $http_response_header[0], $matches) ? $matches[1] : 'unknown') : 'no headers';
        error_log('[proxy-timeline] track='.$scrobbleKey.' response='.substr($resp, 0, 100).' httpCode='.$httpCode);
    }

    /* ---------- 3. stream the track ---------- */
    $url = "{$plex_url}/library/parts/{$partId}/".rawurlencode($fileName)."?download=1&X-Plex-Token={$plex_token}";
    $ctx = stream_context_create([
        'http' => ['ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $fh  = @fopen($url,'rb',false,$ctx);
    if (!$fh) { http_response_code(500); exit('Plex unreachable'); }

    foreach (stream_get_meta_data($fh)['wrapper_data'] as $h) {
        if (stripos($h,'HTTP/')===0||stripos($h,'Content-Type')===0) header($h);
    }
    fpassthru($fh);
    fclose($fh);


    /* ---------- 3. all done ---------- */
    exit;
}

?>
