<?php
/*------------------------------------------------------------------------------
 *  Plex → Podcast RSS  (token-per-URL edition)
 *----------------------------------------------------------------------------*/
include 'settings.php';          // defines: $baseurl, $plex_url  (NO TOKEN)
define('PODCAST_MODE', 'concat');   // 'concat' = single long episode

/*  ----------  common helper: read token once  ----------  */
$plex_token = $_GET['token'] ?? '';

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

/* ---------- Deferred Scrobble System - Updated ---------- */
$scrobbleQueue = []; 

function queueScrobble(string $ratingKey, int $duration, int $startTime): void
{
    global $scrobbleQueue;
    $scrobbleQueue[] = ['key' => $ratingKey, 'duration' => $duration, 'start' => $startTime];
}

function processScrobbleQueue(): void
{
    global $scrobbleQueue, $scrobble_config;
    if (empty($scrobble_config['deferred_enabled'] ?? true)) return;

    $now = time();
    $scrobbleQueue = array_filter($scrobbleQueue, function($item) use ($now) {
        $elapsed = $now - $item['start'];

        if ($elapsed >= $item['duration'] && !$item['scrobbled']) {
            try {
                scrobbleOnce($item['key'], $item['duration']);
                $item['scrobbled'] = true; 
                return false; 
            } catch (Exception) {  }
        }
        return true; 
    });
}

/* ---------- Main Playlist Concatenation - Modified ---------- */
function concatPlaylist(string $playlistId): void
{
    global $plex_url, $plex_token;
    $noVerify = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $tracks = []; 
    $totalSize = 0;

    try { 
        $xml = plexGet('/playlists/' . $playlistId . '/items');
        foreach ($xml->Track as $t) {
            $media = $t->Media; 
            $part = $media->Part;
            $url = "{$plex_url}/library/parts/{$part['id']}/" . rawurlencode(basename($part['file'])) . '?download=1&X-Plex-Token=' . $plex_token;
            $hdr = @get_headers($url, true, $noVerify);
            $tracks[] = [
                'ratingKey' => (string)$t['ratingKey'],
                'duration' => (int)($media['duration'] ?? 0),
                'startByte' => $totalSize,
                'endByte' => $totalSize + (int)($hdr['Content-Length'] ?? 0) - 1,
                'size' => (int)($hdr['Content-Length'] ?? 0),
                'url' => $url
            ];
            $totalSize += (int)($hdr['Content-Length'] ?? 0);
        }
    } catch (RuntimeException) { 
        http_response_code(404);
        exit('Playlist not found');
    }

    $rangeStart = 0; 
    $rangeEnd = $totalSize - 1;

    if (isset($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
        $rangeStart = (int)$matches[1];
        $rangeEnd = $matches[2] !== '' ? (int)$matches[2] : $totalSize - 1;
        http_response_code(206);
        header("Content-Range: bytes {$rangeStart}-{$rangeEnd}/{$totalSize}");
    }

    header('Content-Type: audio/mpeg');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($rangeEnd - $rangeStart + 1));
    header('Cache-Control: no-cache');

    $currentPos = 0; 
    $sent = 0;

    foreach ($tracks as $track) { 
        $start_time = time(); 

        if ($currentPos + $track['size'] <= $rangeStart) {
            $currentPos += $track['size'];
            continue;
        }

        if ($currentPos > $rangeEnd) break;

        $trackOffset = max($rangeStart - $currentPos, 0);
        $trackEndOffset = min($rangeEnd - $currentPos, $track['size'] - 1);
        $trackBytes = $trackEndOffset - $trackOffset + 1;

        if ($trackBytes > 0) {
            $rangeCtx = stream_context_create([
                'http' => ['method' => 'GET', 'header' => "Range: bytes={$trackOffset}-{$trackEndOffset}\r\n"],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);

            $handle = @fopen($track['url'], 'rb', false, $rangeCtx);
            if ($handle) {
                $bytes_written = 0;
                while ($bytes_written < $trackBytes && !feof($handle)) {
                    $chunkSize = min(8192, $trackBytes - $bytes_written);
                    $chunk = fread($handle, $chunkSize);
                    if ($chunk === false || $bytes_written >= $trackBytes) break;
                    echo $chunk;
                    $bytes_written += $chunkSize;
                }
                fclose($handle);
            }
        }

        /* Only queue for full track playback */
        if ($bytes_written >= $trackBytes) { 
            queueScrobble($track['ratingKey'], $track['duration'], $start_time);
        }

        $sent += $bytes_written;
        $currentPos += $track['size'];
    }

    register_shutdown_function('processScrobbleQueue'); 
}






/* ---------- helper: scrobble a single ratingKey exactly once ---------- */
function scrobbleOnce(string $ratingKey, int $durationMs): void
{
    static $done = [];                       // memory-of-fire
    if (isset($done[$ratingKey])) return;   // already scrobbled this request
    $done[$ratingKey] = true;

    global $plex_url, $plex_token;
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

    $url = "{$plex_url}/:/timeline?ratingKey={$ratingKey}&key={$ratingKey}"
         . "&state=stopped&time={$durationMs}&duration={$durationMs}"
         . "&X-Plex-Token={$plex_token}";

    @file_get_contents($url, false, $ctx);
    error_log('[concat-scrobble] track=' . $ratingKey);
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
