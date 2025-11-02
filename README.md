# What is it?

I host all my media (including music) on a local **Plex** server. I also use a **LightPhone 3**.  
This project publishes every Plex playlist as a podcast RSS feed so you can subscribe to them via the LightPhone’s podcast app and stream your own music continuously—even though the phone’s music app only supports manual file uploads.

## Features

- **All Plex audio playlists** are exposed as separate podcast feeds  
- **Ordered or shuffled playback** – each feed can be fetched in original order or randomized every time the feed is refreshed  
- **Per-track episodes** – each song is served as its own episode  
- **Scrobbling** – tracks are automatically marked as played in Plex when streamed  
- **Validation page** – quickly see which tracks in a playlist are missing from disk  
- **Immediate updates** – any change you make to a Plex playlist is reflected the next time the podcast feed is refreshed

## Assumptions

- Plex is reachable from the host running this script  
- Media files must be readable by Plex (the script only proxies, it doesn’t re-encode)  
- PHP ≥ 7.4 with `simplexml` and `dom` extensions  
- Playlist names should be ASCII-friendly (spaces are fine)  
- Only audio playlists are listed; video or mixed playlists are ignored
- KEEP YOUR TOKEN PRIVATE – anyone with the full URL can stream your music

## Quick Start

1. Clone the repo

2. cp settings.php.sample settings.php – edit only the public URL & Plex address

3. Drop the folder in your web root (or use the built-in Docker image)

4. Visit the site, copy any playlist link, append
`&token=YOUR_PLEX_TOKEN`
and paste that full URL into your podcast app.

5Hit subscribe—your personal music now arrives as a never-ending podcast.

## Security Note

The Plex token grants read access to your server.
Treat feed URLs like passwords—never share them publicly.