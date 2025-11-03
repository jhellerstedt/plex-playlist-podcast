<?php
/*------------------------------------------------------------------------------
 *  Plex → Podcast RSS  (token-per-URL edition)
 *----------------------------------------------------------------------------*/
// Disable output buffering for streaming
if (ob_get_level()) ob_end_clean();

include 'settings.php';          // defines: $baseurl, $plex_url  (NO TOKEN)

/*  ----------  common helper: read token once  ----------  */
$plex_token = $_GET['token'] ?? '';


/*  ----------  ROUTING  ----------  */
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

    // Proxy mode: each track is a separate episode
    $totalTracks = count($tracks);
    $episode = 1;
    // Calculate playlist offset: each playlist gets its own month offset based on playlist ID
    $playlistOffset = (int)$playlistId % 120; // Modulo by 120 months (10 years) to avoid going too far back
    foreach ($tracks as $t) {
        $item = $dom->createElement('item');
        $item->appendChild($dom->createElement('title', htmlspecialchars($t['title'])));
        $guid = $dom->createElement('guid', $playlistId.'-'.$episode);
        $guid->setAttribute('isPermaLink', 'false');
        $item->appendChild($guid);
        $item->appendChild($dom->createElement('pubDate', date('r', strtotime("-{$episode} minutes -10 years -{$playlistOffset} months"))));
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
    $rss->appendChild($channel); 
    $dom->appendChild($rss);
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo $dom->saveXML();
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

    /* ---------- 2. get track metadata and prepare for scrobbling ---------- */
    $scrobbleKey = ($ratingKey && ctype_digit($ratingKey)) ? $ratingKey : $partId;

    // Get track duration by fetching metadata
    $trackCtx = stream_context_create([
        'http' => ['ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $trackUrl = "{$plex_url}/library/metadata/{$scrobbleKey}?X-Plex-Token={$plex_token}";
    $trackXmlStr = @file_get_contents($trackUrl, false, $trackCtx);
    $trackXml = @simplexml_load_string($trackXmlStr);
    if ($trackXml) {
        // Try to get duration from Track->Media->duration
        if (isset($trackXml->Track)) {
            $durationMs = (int)($trackXml->Track->Media['duration'] ?? 0);
        } else {
            $durationMs = (int)($trackXml->Media['duration'] ?? 0);
        }
    } else {
        $durationMs = 0;
    }
    
    // Prepare timeline context for use after streaming
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

    // Register timeline updates to run after streaming completes
    register_shutdown_function(function() use ($plex_url, $scrobbleKey, $durationMs, $plex_token, $timelineCtx) {
        // Send timeline updates: first playing, then stopped to properly mark as played
        $timelineUrlPlaying = "{$plex_url}/:/timeline?ratingKey={$scrobbleKey}&key={$scrobbleKey}"
                             . "&state=playing&time=0&duration={$durationMs}"
                             . "&X-Plex-Token={$plex_token}";
        $respPlaying = @file_get_contents($timelineUrlPlaying, false, $timelineCtx);
        
        $timelineUrlStopped = "{$plex_url}/:/timeline?ratingKey={$scrobbleKey}&key={$scrobbleKey}"
                             . "&state=stopped&time={$durationMs}&duration={$durationMs}"
                             . "&X-Plex-Token={$plex_token}";
        $respStopped = @file_get_contents($timelineUrlStopped, false, $timelineCtx);
        
        if ($respPlaying === false || $respStopped === false) {
            error_log('[proxy-timeline] FAIL track='.$scrobbleKey.' playing='.($respPlaying ? 'OK' : 'FAIL').' stopped='.($respStopped ? 'OK' : 'FAIL'));
        } else {
            error_log('[proxy-timeline] SUCCESS track='.$scrobbleKey.' playing_len='.strlen($respPlaying).' stopped_len='.strlen($respStopped));
        }
    });

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


    /* ---------- 4. all done ---------- */
    exit;
}

?>
