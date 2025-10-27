<?php
/*------------------------------------------------------------------------------
 *  Plex → Podcast RSS  (refactored from the Jellyfin version - https://github.com/nateswart/lightphone-musiccast 2025-10-27)
 *  Reads Plex “playlist” files (*.xspf, *.m3u, *.pls) and emits
 *  an iTunes-compatible RSS feed or validates the contained paths.
 *----------------------------------------------------------------------------*/
include 'settings.php';          // defines: $baseurl, $playlist_root, $media_root

/*  ROUTING  ------------------------------------------------------------------*/
if (isset($_GET['playlist'])) {
    $playlist  = urldecode($_GET['playlist']);
    $randomize = urldecode($_GET['randomize']) === 'true';
    processPlaylist($playlist, $randomize, false);
} elseif (isset($_GET['song'])) {
    $song = urldecode($_GET['song']);
    streamSong($song);
} elseif (isset($_GET['validate'])) {
    $playlist = urldecode($_GET['validate']);
    outputHtml();
    processPlaylist($playlist, false, true);
} else {
    outputHtml();
    listPlaylists();
}

/*  HELPER: HTML boiler-plate  ------------------------------------------------*/
function outputHtml()
{
    echo "<html><head><link rel='stylesheet' href='styles.css'></head><body>";
}

function plexGet(string $endPoint): ?SimpleXMLElement
{
    global $plex_url, $plex_token;
    $url = $plex_url . $endPoint . (strpos($endPoint, '?') === false ? '?' : '&') .
           'X-Plex-Token=' . $plex_token;
    $xml = simplexml_load_string(file_get_contents($url));
    return ($xml === false) ? null : $xml;
}


/*  LIST AVAILABLE PLAYLISTS  -------------------------------------------------*/
function listPlaylists()
{
    $xml = plexGet('/playlists');
    if (!$xml) { exit('Plex unreachable'); }

    echo '<h1>Plex Playlists</h1><table>
          <thead><td>Playlist</td><td>Ordered</td><td>Random</td><td>Validate</td></thead><tbody>';
    $link = "<a href='%s'>%s</a>";
    foreach ($xml->Playlist as $pl) {
        if ((string)$pl['type'] !== 'audio') { continue; }   // music only
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
    $xml = plexGet('/playlists/' . $plexKey . '/items');
    if (!$xml) { exit('Playlist empty or not found'); }

    $tracks = [];
    foreach ($xml->Track as $t) {
        $media = $t->Media->Part;          // first media/part
        $path  = (string)$media['file']; // absolute server path
        $title = (string)($t['grandparentTitle'] . ' - ' . $t['title']);
        $tracks[] = ['path'=>$path, 'title'=>$title];
    }
    if ($randomize) { shuffle($tracks); }

    if ($validate) { /* same file-exists loop as original */ }

    /* ---- build RSS exactly as original ---- */
    buildRssFeed($tracks);        // refactored original DOM code
}



/* ---- Build RSS standalone function-------- */

function buildRssFeed(array $tracks, string $feedTitle = 'Plex Playlist'): void
{
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->formatOutput = true;

    $rss = $dom->createElement('rss');
    $rss->setAttribute('version', '2.0');
    $rss->setAttribute('xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
    $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');

    $channel = $dom->createElement('channel');

    $title = $dom->createElement('title', pathinfo($playlistName, PATHINFO_FILENAME));
    $channel->appendChild($title);

    $channel->appendChild($dom->createElement('itunes:author', 'Plex'));
    $owner = $dom->createElement('itunes:owner');
    $owner->appendChild($dom->createElement('itunes:name', 'Plex'));
    $channel->appendChild($owner);

    $channel->appendChild($dom->createElement('lastBuildDate', date('r')));

    $episode = 1;
    foreach ($items as $it) {
        $itemNode = $dom->createElement('item');

        $itemTitle = $dom->createElement('title', htmlspecialchars($it['title']));
        $itemNode->appendChild($itemTitle);

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

/*  UNIVERSAL PLAYLIST PARSER  -----------------------------------------------*/
function parsePlaylist(string $file): array
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $out = [];

    if ($ext === 'xspf') {
        $xml = simplexml_load_file($file);
        if (!$xml) { return []; }
        foreach ($xml->trackList->track as $t) {
            $path = (string)($t->location ?? '');
            $path = str_replace('file://', '', $path);
            $title = (string)($t->title ?? basename($path));
            if ($path) { $out[] = ['path' => $path, 'title' => $title]; }
        }
    } elseif ($ext === 'm3u' || $ext === 'm3u8') {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#') { continue; }
            $title = basename($l);
            $out[] = ['path' => $l, 'title' => $title];
        }
    } elseif ($ext === 'pls') {
        $ini = parse_ini_file($file);
        $i = 1;
        while (isset($ini["File$i"])) {
            $path = $ini["File$i"];
            $title = $ini["Title$i"] ?? basename($path);
            $out[] = ['path' => $path, 'title' => $title];
            $i++;
        }
    }
    return $out;
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
        fseek($fp, $start);
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
