<?php
/* -------------  CONFIG  ------------- */
include 'settings.php';          // <--  must define $plex_url, $plex_token, $baseurl
// (no getenv() calls any more)

const PODCAST_MODE = 'concat';   // 'concat' = single episode, anything else = per-track


/* -------------  HELPERS  ------------- */
function plexGet(string $path): SimpleXMLElement
{
    global $plex_url, $plex_token;
    $url = $plex_url.'/library'.$path.'?X-Plex-Token='.$plex_token;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $xml = @file_get_contents($url, false, $ctx);
    if ($xml === false) throw new RuntimeException('Plex unreachable');
    return simplexml_load_string($xml) ?: throw new RuntimeException('Bad XML');
}

function listPlaylists(): void
{
    global $baseurl;
    try {
        $xml = plexGet('/playlists');
    } catch (RuntimeException $e) { exit('Error: '.$e->getMessage()); }

    $rows = '';
    foreach ($xml->Playlist as $pl) {
        if ((string)$pl['playlistType'] !== 'audio') continue;
        $key = (string)$pl['ratingKey'];
        try { $items = plexGet('/playlists/'.$key.'/items'); if (!count($items->Track)) continue; }
        catch (RuntimeException) { continue; }

        $title = htmlspecialchars($pl['title']);
        $rows .= "<tr><td>{$title}</td>
                  <td><a href='{$baseurl}?plexKey={$key}&randomize=false'>⬇️</a></td>
                  <td><a href='{$baseurl}?plexKey={$key}&randomize=true'>🔀</a></td>
                  <td><a href='{$baseurl}?validate={$key}'>🤖</a></td></tr>";
    }
    echo '<h1>Plex Playlists</h1><table><thead><tr><td>Playlist</td><td>Ordered</td><td>Random</td><td>Validate</td></tr></thead><tbody>'.
         ($rows ?: '<tr><td colspan="4">No playable playlists</td></tr>').'</tbody></table>';
}

function processPlaylist(string $plexKey, bool $randomize, bool $validate): void
{
    try { $xml = plexGet('/playlists/'.$plexKey.'/items'); }
    catch (RuntimeException $e) { echo '<li>Plex error: '.htmlspecialchars($e->getMessage()).'</li>'; return; }

    $seen = []; $tracks = [];
    foreach ($xml->Track as $t) {
        $media = $t->Media; $part = $media->Part;
        $id = (int)$part['id'];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $tracks[] = [
            'title'    => (string)($t['grandparentTitle'].' - '.$t['title']),
            'partId'   => $id,
            'fileName' => basename((string)$part['file']),
            'duration' => (int)($media['duration'] ?? 0),
        ];
    }
    if ($randomize) shuffle($tracks);
    if ($validate) { foreach ($tracks as $tr) echo '✅ '.htmlspecialchars($tr['title']).'<br>'; return; }
    buildRssFeed($tracks, (string)$xml['title'], $plexKey);
}

function concatPlaylist(string $playlistId): void
{
    global $plex_url, $plex_token;
    $noVerify = stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);

    /* 1. size calculation */
    $size = 0;
    try {
        $xml = plexGet('/playlists/'.$playlistId.'/items');
        foreach ($xml->Track as $t) {
            $media = $t->Media; $part = $media->Part;
            $url  = "{$plex_url}/library/parts/{$part['id']}/".rawurlencode(basename($part['file'])).'?download=1&X-Plex-Token='.$plex_token;
            $hdr  = @get_headers($url, true, $noVerify);
            $size += (int)($hdr['Content-Length'] ?? 0);
        }
    } catch (RuntimeException) { http_response_code(404); exit('Playlist not found'); }

    /* 2. Apple headers */
    header('Content-Type: audio/mpeg');
    header('Accept-Ranges: bytes');
    header('Content-Length: '.$size);
    header('Cache-Control: no-cache');

    /* 3. stream & scrobble each track */
    foreach ($xml->Track as $t) {
        $media = $t->Media; $part = $media->Part;
        $url   = "{$plex_url}/library/parts/{$part['id']}/".rawurlencode(basename($part['file'])).'?download=1&X-Plex-Token='.$plex_token;
        @readfile($url, false, $noVerify);

        $scrobbleUrl = "{$plex_url}/:/scrobble?identifier=com.plexapp.plugins.library&key={$part['id']}&X-Plex-Token={$plex_token}";
        $scrobbleCtx = stream_context_create([
            'http' => ['method'=>'POST','header'=>['Content-Length: 0','User-Agent: PlexPlaylistPodcast/1.0'],'ignore_errors'=>true],
            'ssl'  => ['verify_peer'=>false,'verify_peer_name'=>false],
        ]);
        @file_get_contents($scrobbleUrl, false, $scrobbleCtx);
    }
}

function streamSongProxy(string $partId, string $fileName, int $offsetMs = 0): void
{
    global $plex_url, $plex_token;
    $partId   = trim($partId);
    if ($partId === '' || !ctype_digit($partId)) { http_response_code(400); exit('Bad request'); }
    $fileName = trim(urldecode($fileName));
    if ($fileName === '') { http_response_code(400); exit('Bad request'); }

    $url = "{$plex_url}/library/parts/{$partId}/".rawurlencode($fileName)."?download=1&X-Plex-Token={$plex_token}";
    $ctx = stream_context_create([
        'http' => ['ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $fh = @fopen($url,'rb',false,$ctx);
    if (!$fh) { http_response_code(500); exit('Plex unreachable'); }

    foreach (stream_get_meta_data($fh)['wrapper_data'] as $h) {
        if (stripos($h,'HTTP/')===0||stripos($h,'Content-Type')===0) header($h);
    }
    fpassthru($fh);
    fclose($fh);

    $scrobbleUrl = "{$plex_url}/:/scrobble?identifier=com.plexapp.plugins.library&key={$partId}&X-Plex-Token={$plex_token}";
    $scrobbleCtx = stream_context_create([
        'http' => ['method'=>'POST','header'=>['Content-Length: 0','User-Agent: PlexPlaylistPodcast/1.0'],'ignore_errors'=>true],
        'ssl'  => ['verify_peer'=>false,'verify_peer_name'=>false],
    ]);
    @file_get_contents($scrobbleUrl, false, $scrobbleCtx);
    exit;
}

function buildRssFeed(array $tracks, string $title, string $plexKey): void
{
    global $baseurl;
    $now = date(DATE_RSS);
    $selfLink = htmlspecialchars($baseurl.'/?plexKey='.$plexKey);
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
  <channel>
    <title><?= htmlspecialchars($title) ?></title>
    <link><?= $selfLink ?></link>
    <description>Plex playlist as podcast</description>
    <lastBuildDate><?= $now ?></lastBuildDate>
    <generator>PlexPlaylistPodcast</generator>
<?php if (PODCAST_MODE === 'concat'): ?>
    <item>
      <title><?= htmlspecialchars($title) ?></title>
      <link><?= $selfLink ?></link>
      <guid isPermaLink="false"><?= $plexKey.'-concat' ?></guid>
      <pubDate><?= $now ?></pubDate>
      <enclosure url="<?= htmlspecialchars($baseurl.'/?stream='.$plexKey.'.mp3') ?>" type="audio/mpeg" length="0"/>
      <itunes:duration><?= array_sum(array_column($tracks,'duration'))/1000 ?></itunes:duration>
    </item>
<?php else: foreach ($tracks as $t): ?>
    <item>
      <title><?= htmlspecialchars($t['title']) ?></title>
      <link><?= $selfLink ?></link>
      <guid isPermaLink="false"><?= $t['partId'] ?></guid>
      <pubDate><?= $now ?></pubDate>
      <enclosure url="<?= htmlspecialchars($baseurl.'/?song='.$t['partId'].'&file='.urlencode($t['fileName'])) ?>" type="audio/mpeg"/>
      <itunes:duration><?= $t['duration']/1000 ?></itunes:duration>
    </item>
<?php endforeach; endif; ?>
  </channel>
</rss>
<?php }

/* -------------  ROUTER  ------------- */
if (isset($_GET['stream'])) {
    $key = preg_replace('/\..*$/','', (string)$_GET['stream']);
    concatPlaylist($key);
    exit;
}
if (isset($_GET['song'],$_GET['file'])) {
    streamSongProxy((string)$_GET['song'], (string)$_GET['file']);
    exit;
}
if (isset($_GET['validate'],$_GET['plexKey'])) {
    processPlaylist((string)$_GET['plexKey'], false, true);
    exit;
}
if (isset($_GET['plexKey'])) {
    processPlaylist(
        (string)$_GET['plexKey'],
        isset($_GET['randomize']) && $_GET['randomize'] === 'true',
        false
    );
    exit;
}
listPlaylists();
