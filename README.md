# Stashd YouTube plugin

`stashd/youtube` provides a YouTube Input for Stashd. It accepts channel,
handle, playlist, video, Shorts, mobile, and YouTube Music URLs, discovers
items from Atom feeds during routine refreshes, and uses the YouTube Data API
only for complete enumeration when a `youtube-data-api` credential is granted.

Acquisition runs through the declared `yt-dlp` helper and preserves primary
media, `.info.json` metadata, thumbnails, and optional VTT captions. Video
downloads are capped at 1080p; audio mode extracts MP3 at 128 kbps. Shorts and
live/premiere items are excluded unless enabled by input options.

Install with Composer using `stashd/youtube`. The package requires PHP 8.5,
`yt-dlp`, and FFmpeg in the plugin runtime image. Run `./tests/run.sh` for
offline fixture tests. Stashd remains authoritative for identity, Vault state,
and promotion; this package owns YouTube protocol behavior only.
