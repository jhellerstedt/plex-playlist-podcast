# What is it?

I host all my media (including music) on a local **Plex** server. I also use a **LightPhone 3**.  
This project publishes every Plex playlist as a podcast RSS feed so you can subscribe to them via the LightPhone’s podcast app and stream your own music continuously—even though the phone’s music app only supports manual file uploads.

## Features

- **All Plex audio playlists** are exposed as separate podcast feeds  
- **Ordered or shuffled playback** – each feed can be fetched in original order or randomized every time the feed is refreshed  
- **“Concat” mode** – delivers the entire playlist as **one long MP3 file**; LP3 podcast tool won't play autoplay episodes from a single podcast AFAIK 
- **Per-track proxy** – classic mode still serves every song as its own episode; both modes transparently proxy and **scrobble** the play-back to Plex once the first byte of a track is streamed  
- **Validation page** – quickly see which tracks in a playlist are missing from disk  
- **Immediate updates** – any change you make to a Plex playlist is reflected the next time the podcast feed is refreshed

## Assumptions

- Plex is reachable from the host running this script  
- Media files must be readable by Plex (the script only proxies, it doesn’t re-encode)  
- PHP ≥ 7.4 with `simplexml` and `dom` extensions  
- Playlist names should be ASCII-friendly (spaces are fine)  
- Only audio playlists are listed; video or mixed playlists are ignored

## Quick Start

1. Clone the repo  
2. `cp settings.php.sample settings.php` and fill in your Plex URL + token  
3. Point your web server to the project root (or run the built-in Docker setup)  
4. Visit the site – copy any playlist feed URL into your favourite podcast app or the LightPhone dashboard
