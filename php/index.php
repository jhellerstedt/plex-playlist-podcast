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

/* ---------- pre-validate playlists before we show them ---------- */
function listPlaylists(): void
{
    try {
        $xml = plexGet('/playlists');
    } catch (RuntimeException $e) {
        exit('Error: '.$e->getMessage());
    }

    echo '<h1>Plex Playlists</h1><table>
          <thead><td>Playlist</td><td>Ordered</td><td>Random</td><td>Validate</td></thead><tbody>';
    $link = "<a href='%s'>%s</a>";
    foreach ($xml->Playlist as $pl) {
        if ((string)$pl['playlistType'] !== 'audio') { continue; } // music only
        $key = (string)$pl['ratingKey'];
        /* ---- pre-flight the items endpoint ---- */
        try {
            plexGet('/playlists/'.$key.'/items');
        } catch (RuntimeException $e) {
            continue; // skip any playlist that 500s
        }
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

/*  CORE: BUILD RSS OR VALIDATE  --------------------------------------------*/
function processPlaylist(string $plexKey, bool $randomize, bool $validate)
{
    try {
        $xml = plexGet('/playlists/' . $plexKey . '/items');
    } catch (RuntimeException $e) {
        echo "<li>Skipping playlist $plexKey (Plex error: " . $e->getMessage() . ')</li>';
        return;
    }

    $seen = [];          // ratingKey already added
    $tracks = [];
    foreach ($xml->Track as $t) {
        $id = (string)$t['ratingKey'];
        if (isset($seen[$id])) { continue; } // skip duplicate
        $seen[$id] = true;

        /* 1️⃣  NEW: grab the Plex identifiers we need for streaming */
        $media      = $t->Media;               // first <Media>
        $part       = $media->Part;            // first <Part>
        $partId     = (int)$part['id'];       // Plex part identifier
        $fileName   = basename((string)$part['file']);
        $title      = (string)($t['grandparentTitle'] . ' - ' . $t['title']);

        $tracks[] = [
            'title'    => $title,
            'partId'   => $partId,
            'fileName' => $fileName,
        ];
    }
    if ($randomize) { shuffle($tracks); }

    if ($validate) {
        foreach ($tracks as $t) {
            echo '✅ ' . htmlspecialchars($t['title']) . "<br>";
        }
        exit;
    }
    buildRssFeed($tracks);
}

/* ---- Build RSS standalone function ------------------------------------- */
function buildRssFeed(array $tracks): void
{
    global $baseurl, $plex_url, $plex_token;

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

        /* 2️⃣  NEW: Plex stream URL instead of local path */
        $streamUrl = "{$plex_url}/library/parts/{$it['partId']}/{$it['fileName']}?download=1&X-Plex-Token={$plex_token}";
        $enclosure = $dom->createElement('enclosure');
        $enclosure->setAttribute('url', htmlspecialchars($streamUrl));
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
