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
if (isset($_GET['m3u'])) {                        // 1️⃣  NEW: M3U playlist
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
} elseif (isset($_GET['proxy'])) {               // 2️⃣  existing proxy
    streamSongProxy($_GET['proxy'], $_GET['f'] ?? '', $_GET['ts'] ?? 0);
} else {
    outputHtml();
    listPlaylists();
}

/* ---------- helpers unchanged ---------- */
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
    try {
        $xml = plexGet('/playlists');
    } catch (RuntimeException $e) { exit('Error: '.$e->getMessage()); }
    echo '<h1>Plex Playlists</h1><table>
          <thead><td>Playlist</td><td>Ordered</td><td>Random</td><td>Validate</td></thead><tbody>';
    $link = "<a href='%s'>%s</a>";
    foreach ($xml->Playlist as $pl) {
        if ((string)$pl['playlistType'] !== 'audio') continue;
        $key = (string)$pl['ratingKey'];
        try { plexGet('/playlists/'.$key.'/items'); } catch (RuntimeException) { continue; }
        $title = htmlspecialchars($pl['title']);
        echo '<tr>
                <td>'.$title.'</td>
                <td>'.sprintf($link, '?plexKey='.$key.'&randomize=false', '⬇️').'</td>
                <td>'.sprintf($link, '?plexKey='.$key.'&randomize=true',  '🔀').'</td>
                <td>'.sprintf($link, '?validate='.$key, '🤖').'</td>
              </tr>';
    }
    echo '</tbody></table>';
}

/* ---------- playlist processor ---------- */
function processPlaylist(string $plexKey, bool $randomize, bool $validate): void
{
    try {
        $plXml       = plexGet('/playlists/' . $plexKey);
        $playlistNode= $plXml->Playlist[0];
        $playlistTitle=(string)($playlistNode['title']);
        $xml         = plexGet('/playlists/' . $plexKey . '/items');
    } catch (RuntimeException $e) {
        echo "<li>Skipping playlist $plexKey (Plex error: ".$e->getMessage().')</li>'; return;
    }

    $seen = []; $tracks = [];
    foreach ($xml->Track as $t) {
        $id = (string)$t['ratingKey'];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $media = $t->Media; $part = $media->Part;
        $tracks[] = [
            'title'    => (string)($t['grandparentTitle'].' - '.$t['title']),
            'partId'   => (int)$part['id'],
            'fileName' => basename((string)$part['file']),
            'duration' => (int)($media['duration'] ?? 0),
        ];
    }
    if ($randomize) shuffle($tracks);
    if ($validate) { foreach ($tracks as $t) echo '✅ '.htmlspecialchars($t['title']).'<br>'; exit; }
    buildRssFeed($tracks, $playlistTitle, $plexKey);
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

    if (PODCAST_MODE === 'concat') {              // 3️⃣  SINGLE EPISODE
        $item = $dom->createElement('item');
        $item->appendChild($dom->createElement('title', htmlspecialchars($playlistTitle)));
        $guid = $dom->createElement('guid', $playlistId);
        $guid->setAttribute('isPermaLink', 'false');
        $item->appendChild($guid);
        $item->appendChild($dom->createElement('pubDate', date('r')));
        $enclosure = $dom->createElement('enclosure');
        $enclosure->setAttribute('url', $baseurl.'?m3u='.$playlistId);
        $enclosure->setAttribute('type', 'audio/x-mpegurl');
        $item->appendChild($enclosure);
        $channel->appendChild($item);
    } else {                                      // classic per-track
        $episode = 1;
        foreach ($tracks as $t) {
            $item = $dom->createElement('item');
            $item->appendChild($dom->createElement('title', htmlspecialchars($t['title'])));
            $guid = $dom->createElement('guid', $t['partId']);
            $guid->setAttribute('isPermaLink', 'false');
            $item->appendChild($guid);
            $item->appendChild($dom->createElement('pubDate', date('r', strtotime("-$episode days"))));
            $item->appendChild($dom->createElement('itunes:episode', $episode));
            $enc = $dom->createElement('enclosure');
            $enc->setAttribute('url', $baseurl.'?proxy='.$t['partId'].'&f='.urlencode($t['fileName']));
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

/* ---------- M3U builder ---------- */
function buildM3u(string $playlistId): string
{
    $tracks = []; $offsetMs = 0;
    try {
        $xml = plexGet('/playlists/'.$playlistId.'/items');
        foreach ($xml->Track as $t) {
            $media = $t->Media; $part = $media->Part;
            $dur   = (int)($media['duration'] ?? 0);
            $tracks[] = [
                'title'    => (string)($t['grandparentTitle'].' - '.$t['title']),
                'partId'   => (int)$part['id'],
                'fileName' => basename((string)$part['file']),
                'duration' => $dur,
                'offset'   => $offsetMs,
            ];
            $offsetMs += $dur;
        }
    } catch (RuntimeException $e) { exit('#EXTM3U'); }

    $out = "#EXTM3U\n";
    foreach ($tracks as $t) {
        $url = '?proxy='.$t['partId'].'&f='.urlencode($t['fileName']).'&ts='.$t['offset'];
        $out.= "#EXTINF:".($t['duration']/1000).",".$t['title']."\n".$url."\n";
    }
    return $out;
}

/* ---------- proxy + scrobble ---------- */
function streamSongProxy(string $partId, string $fileName, int $offsetMs = 0): void
{
    global $plex_url, $plex_token;

    $partId   = trim($partId);
    if ($partId === '' || !ctype_digit($partId)) { http_response_code(400); exit('Bad request'); }
    $fileName = trim(urldecode($fileName));
    if ($fileName === '') { http_response_code(400); exit('Bad request'); }

    $url = "{$plex_url}/library/parts/{$partId}/".rawurlencode($fileName)."?download=1&X-Plex-Token={$plex_token}";
    $ctx = stream_context_create(['http'=>['ignore_errors'=>true],'ssl'=>['verify_peer'=>false]]);
    $fh  = @fopen($url,'rb',false,$ctx);
    if (!$fh) { http_response_code(500); exit('Plex unreachable'); }

    foreach (stream_get_meta_data($fh)['wrapper_data'] as $h) {
        if (stripos($h,'HTTP/')===0||stripos($h,'Content-Type')===0) header($h);
    }
    fpassthru($fh); fclose($fh);

    /* scrobble this single track */
    $mark = "{$plex_url}/:/scrobble?identifier=com.plexapp.plugins.library&key={$partId}&X-Plex-Token={$plex_token}";
    @file_get_contents($mark, false, $ctx);
    exit;
}
?>
