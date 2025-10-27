<?php
/*------------------------------------------------------------------------------
 *  Plex → Podcast RSS  (refactored from the Jellyfin version)
 *  Reads Plex playlists from API and emits
 *  an iTunes-compatible RSS feed or validates the contained paths.
 *----------------------------------------------------------------------------*/
include 'settings.php';          // defines: $baseurl, $plex_url, $plex_token

/*  ROUTING  ------------------------------------------------------------------*/
if (isset($_GET['plexKey'])) {
    $key       = urldecode($_GET['plexKey']);
    $randomize = urldecode($_GET['randomize']) === 'true';
    processPlaylist($key, $randomize, false);
} elseif (isset($_GET['validate'])) {
    $key = urldecode($_GET['validate']);
    outputHtml();
    processPlaylist($key, false, true);
} elseif (isset($_GET['song'])) {                 // NEW: direct MP3 stream
    streamSong($_GET['song']);
} else {
    outputHtml();
    listPlaylists();
}

/*  HELPER: HTML boiler-plate  ----------------------------------------------*/
function outputHtml()
{
    echo "<html><head><link rel='stylesheet' href='styles.css'></head><body>";
}

/*  HELPER: Plex API GET (with local SSL workaround)  ------------------------*/
function plexGet(string $endpoint): \SimpleXMLElement
{
    global $plex_url, $plex_token;

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $url  = $plex_url . $endpoint . '?X-Plex-Token=' . $plex_token;
    $resp = file_get_contents($url, false, $ctx);

    if ($resp === false) {
        throw new RuntimeException('Plex unreachable at ' . $url);
    }

    $xml = simplexml_load_string($resp);
    if ($xml === false) {
        throw new RuntimeException('Plex returned invalid XML: ' . $resp);
    }

    return $xml;
}

/*  LIST AVAILABLE PLAYLISTS  --------------------------------------------------*/
function listPlaylists()
{
    try {
        $xml = plexGet('/playlists');
    } catch (RuntimeException $e) {
        exit('Error: ' . $e->getMessage());
    }

    echo '<h1>Plex Playlists</h1><table>
          <thead><td>Playlist</td><td>Ordered</td><td>Random</td><td>Validate</td></thead><tbody>';
    $link = "<a href='%s'>%s</a>";
    foreach ($xml->Playlist as $pl) {
        if ((string)$pl['playlistType'] !== 'audio') { continue; }   // was $pl['type']
        if ((int)$pl['leafCount'] === 0) { continue; } // skip empty lists
        $key = (string)$pl['ratingKey'];
        $title = htmlspecialchars($pl['title']);
        echo '<tr>
                <td>' . $title . '</td>
                <td>' . sprintf($link, '?plexKey=' . $key . '&randomize=false', '⬇️') . '</td>
                <td>' . sprintf($link, '?plexKey=' . $key . '&randomize=true',  '🔀') . '</td>
                <td>' . sprintf($link, '?validate=' . $key, '🤖') . '</td>
              </tr>';
    }
    echo '</tbody></table>';
}

/*  CORE: BUILD RSS OR VALIDATE  --------------------------------------------*/
function processPlaylist(string $plexKey, bool $randomize, bool $validate)
{
    global $baseurl;
    try {
        $xml = plexGet('/playlists/' . $plexKey . '/items');
    } catch (RuntimeException $e) {
        exit('Error: ' . $e->getMessage());
    }

    $tracks = [];
    foreach ($xml->Track as $t) {
        $media = $t->Media->Part;          // first media/part
        $path  = (string)$media['file']; // absolute server path
        $title = (string)($t['grandparentTitle'] . ' - ' . $t['title']);
        $tracks[] = ['path'=>$path, 'title'=>$title];
    }
    if ($randomize) { shuffle($tracks); }

    if ($validate) {
        foreach ($tracks as $t) {
            $ok = file_exists($t['path']) ? '✅' : '❌';
            echo $ok . ' ' . htmlspecialchars($t['title']) . "<br>";
        }
        exit;
    }

    buildRssFeed($tracks);
}

/* ---- Build RSS standalone function ------------------------------------- */
function buildRssFeed(array $tracks): void
{
    global $baseurl;
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->formatOutput = true;

    $rss = $dom->createElement('rss');
    $rss->setAttribute('version', '2.0');
    $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
    $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');

    $channel = $dom->createElement('channel');
    $channel->appendChild($dom->createElement('title', 'Plex Playlist'));
    $channel->appendChild($dom->createElement('itunes:author', 'Plex'));
    $owner = $dom->createElement('itunes:owner');
    $owner->appendChild($dom->createElement('itunes:name', 'Plex'));
    $channel->appendChild($owner);
    $channel->appendChild($dom->createElement('lastBuildDate', date('r')));

    $episode = 1;
    foreach ($tracks as $it) {
        $itemNode = $dom->createElement('item');
        $itemNode->appendChild($dom->createElement('title', htmlspecialchars($it['title'])));
        $guid = $dom->createElement('guid', bin2hex(random_bytes(16)));
        $guid->setAttribute('isPermaLink', 'false');
        $itemNode->appendChild($guid);
        $itemNode->appendChild($dom->createElement('pubDate', date('r', strtotime("-$episode days"))));
        $itemNode->appendChild($dom->createElement('itunes:episode', $episode));
        $enclosure = $dom->createElement('enclosure');
        $enclosure->setAttribute('url', $baseurl . '?song=' . urlencode($it['path']));
        $enclosure->setAttribute('type', 'audio/mpeg');
        $itemNode->appendChild($enclosure);
        $channel->appendChild($itemNode);
        $episode++;
    }

    $rss->appendChild($channel);
    $dom->appendChild($rss);
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo $dom->saveXML();
}

/*  RANGE-AWARE STREAMING  --------------------------------------------------*/
function streamSong(string $file)
{
    if (!file_exists($file)) { http_response_code(404); exit('no file'); }
    $size = filesize($file);
    $mime = 'audio/mpeg';
    $fp = fopen($file, 'rb');

    $start = 0;
    $end = $size - 1;
    $length = $size;

    header("Content-Type: $mime");
    header('Accept-Ranges: bytes');
    if (isset($_SERVER['HTTP_RANGE'])) {
        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            exit;
        }
        if ($range[0] === '-') {
            $start = $size - substr($range, 1);
        } else {
            list($start, $end) = explode('-', $range);
            $start = intval($start);
            $end = intval($end) ?: $end;
        }
        if ($start > $end || $start >= $size) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            exit;
        }
        $end = min($end, $size - 1);
        $length = $end - $start + 1;
        header('HTTP/1.1 206 Partial Content');
    }
    header("Content-Range: bytes $start-$end/$size");
    header("Content-Length: $length");
    while (!feof($fp) && (ftell($fp) <= $end)) {
        echo fread($fp, 8192);
        flush();
    }
    fclose($fp);
    exit;
}
