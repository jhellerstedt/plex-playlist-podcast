<?php
/*------------------------------------------------------------------------------
 *  Plex → Podcast RSS  (refactored from the Jellyfin version)
 *  Reads Plex playlists from API and emits
 *  an iTunes-compatible RSS feed or validates the contained paths.
 *----------------------------------------------------------------------------*/
include 'settings.php';          // defines: $baseurl, $plex_url, $plex_token
define('PODCAST_MODE', 'concat');   // 'concat' = single long episode
                                     // anything else = classic per-track feed

/*  ROUTING  ------------------------------------------------------------------*/
if (isset($_GET['stream'])) {                     // 1️⃣  NEW: continuous MP3
    $id = strtok($_GET['stream'], '.');           // strip fake ".mp3"
    concatPlaylist($id);
    exit;
}
if (isset($_GET['m3u'])) {                        // 2️⃣  segmented M3U (kept)
    header('Content-Type: audio/x-mpegurl');
    echo buildM3u($_GET['m3u']);
    exit;
}
if (isset($_GET['plexKey'])) {
    $key       = urldecode($_GET['plexKey']);
    $randomize = urldecode($_GET['randomize']) === 'true';
    processPlaylist($key, $randomize, false);
} elseif (isset($_GET['validate'])) {
    $key = urldecode($_GET['validate']);
    outputHtml();
    processPlaylist($key, false, true);
} elseif (isset($_GET['proxy'])) {               // 3️⃣  per-track proxy
    streamSongProxy($_GET['proxy'], $_GET['f'] ?? '', $_GET['ts'] ?? 0, $_GET['r'] ?? '');
} else {
    outputHtml();
    listPlaylists();
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
    global $baseurl;
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
                    <td><a href='{$baseurl}?plexKey={$key}&randomize=false'>⬇️</a></td>
                    <td><a href='{$baseurl}?plexKey={$key}&randomize=true'>🔀</a></td>
                    <td><a href='{$baseurl}?validate={$key}'>🤖</a></td>
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

/* ---------- RSS builder ---------- */
function buildRssFeed(array $tracks, string $playlistTitle, string $playlistId): void
{
    global $baseurl;
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->formatOutput = true;
    $rss = $dom->createElement('rss');
    $rss->setAttribute('version', '2.0');
    $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
    $channel = $dom->createElement('channel');
    $channel->appendChild($dom->createElement('title', htmlspecialchars($playlistTitle)));
    $channel->appendChild($dom->createElement('itunes:author', 'Plex'));
    $channel->appendChild($dom->createElement('lastBuildDate', date('r')));

    if (PODCAST_MODE === 'concat') {              // single long episode
        $item = $dom->createElement('item');
        $item->appendChild($dom->createElement('title', htmlspecialchars($playlistTitle)));
        $guid = $dom->createElement('guid', $playlistId);
        $guid->setAttribute('isPermaLink', 'false');
        $item->appendChild($guid);
        $item->appendChild($dom->createElement('pubDate', date('r')));
        $enc = $dom->createElement('enclosure');
        $enc->setAttribute('url', $baseurl.'?stream='.$playlistId.'.mp3'); // fake .mp3
        $enc->setAttribute('type', 'audio/mpeg');
        $item->appendChild($enc);
        $channel->appendChild($item);
    } else {                                      // classic per-track
        $totalTracks = count($tracks);
        $episode = 1;
        foreach ($tracks as $t) {
            $item = $dom->createElement('item');
            $item->appendChild($dom->createElement('title', htmlspecialchars($t['title'])));
            $guid = $dom->createElement('guid', $playlistId.'-'.$episode);
            $guid->setAttribute('isPermaLink', 'false');
            $item->appendChild($guid);

            // Sequential dates (same day for continuous playback)
            $item->appendChild($dom->createElement('pubDate', date('r', strtotime("-{$episode} minutes"))));

            // iTunes episode metadata for series/season
            $item->appendChild($dom->createElement('itunes:episode', $totalTracks - $episode + 1)); // newest first
            $item->appendChild($dom->createElement('itunes:season', 1));

            $enc = $dom->createElement('enclosure');
            $enc->setAttribute('url', $baseurl.'?proxy='.$t['partId'].'&f='.urlencode($t['fileName']).'&r='.$t['ratingKey']);
            $enc->setAttribute('type', 'audio/mpeg');
            $item->appendChild($enc);
            $channel->appendChild($item);
            $episode++;
        }
    }
    $rss->appendChild($channel); $dom->appendChild($rss);
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

/* ---------- NEW: continuous MP3 for Apple Podcasts ---------- */
function concatPlaylist(string $playlistId): void
{
    global $plex_url, $plex_token;

    $noVerify = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

    /* 1. Build track index with byte offsets */
    $tracks = [];
    $totalSize = 0;
    try {
        $xml = plexGet('/playlists/' . $playlistId . '/items');
        foreach ($xml->Track as $t) {
            $media = $t->Media;
            $part  = $media->Part;
            $url   = "{$plex_url}/library/parts/{$part['id']}/" .
                     rawurlencode(basename($part['file'])) .
                     '?download=1&X-Plex-Token=' . $plex_token;
            $hdr   = @get_headers($url, true, $noVerify);
            $trackSize = (int) ($hdr['Content-Length'] ?? 0);

            $tracks[] = [
                'ratingKey' => (string)$t['ratingKey'],
                'duration' => (int)($media['duration'] ?? 0),
                'startByte' => $totalSize,
                'endByte' => $totalSize + $trackSize - 1,
                'size' => $trackSize,
                'size' => $trackSize,
                'url' => $url,
            ];
            $totalSize += $trackSize;
        }
    } catch (RuntimeException) {
        http_response_code(404);
        exit('Playlist not found');
    }

    /* 2. Parse HTTP Range header */
    $rangeStart = 0;
    $rangeEnd = $totalSize - 1;
    if (isset($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
        $rangeStart = (int)$matches[1];
        $rangeEnd = $matches[2] !== '' ? (int)$matches[2] : $totalSize - 1;
        http_response_code(206);
        header("Content-Range: bytes {$rangeStart}-{$rangeEnd}/{$totalSize}");
    }

    /* 3. Send headers */
    header('Content-Type: audio/mpeg');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($rangeEnd - $rangeStart + 1));
    header('Cache-Control: no-cache');

    /* 4. Stream only requested range and track which tracks were sent */
    $currentPos = 0;

    foreach ($tracks as $track) {
        if ($currentPos + $track['size'] <= $rangeStart) {
            /* Skip this track - it's before requested range */
            $currentPos += $track['size'];
            continue;
        }

        if ($currentPos > $rangeEnd) {
            /* Stop - we're past requested range */
            break;
        }

        /* Track is partially or fully in range - stream it */
        $trackStartInRange = max(0, $rangeStart - $currentPos);
        $trackEndInRange = min($track['size'] - 1, $rangeEnd - $currentPos);
        $bytesToSend = $trackEndInRange - $trackStartInRange + 1;

        /* Open track file and seek to start position */
        $handle = @fopen($track['url'], 'rb', false, $noVerify);
        if ($handle && $trackStartInRange > 0) {
            fseek($handle, $trackStartInRange);
        }

        /* Send only the requested bytes */
        $sent = 0;
        $scrobbledThisTrack = false;
        while ($sent < $bytesToSend && !feof($handle)) {
            $chunk = @fread($handle, min(8192, $bytesToSend - $sent));
            if ($chunk === false) break;
            echo $chunk;
            $sent += strlen($chunk);
            if (!$scrobbledThisTrack) {
                scrobbleOnce($track['ratingKey'], $track['duration']);
                $scrobbledThisTrack = true;
            }
        }
        fclose($handle);

        $currentPos += $track['size'];

        if ($currentPos > $rangeEnd) {
            break; /* Past requested range */
        }
    }
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
