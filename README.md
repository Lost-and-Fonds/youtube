# Stashd YouTube plugin

`stashd/youtube` provides a YouTube Input for Stashd. It accepts channel,
handle, playlist, video, Shorts, mobile, and YouTube Music URLs, discovers
items from Atom feeds during routine refreshes, and uses the YouTube Data API
only for complete enumeration when a `youtube-data-api` credential is granted.

Acquisition runs through the declared `yt-dlp` helper and preserves primary
media, `.info.json` metadata, thumbnails, and optional VTT captions. Video
downloads are capped at 1080p; audio mode extracts MP3 at 128 kbps. Shorts and
live/premiere items are excluded unless enabled by input options.

Optional authenticated-download credentials are configured under Stashd's
plugin credentials: upload a Netscape-format `cookies.txt` file and, when
needed, enter a PO token. A bare token is sent as a `web.gvs` token; an explicit
context can be supplied as `web.gvs+TOKEN`. Credentials are encrypted by
Stashd, passed only to this plugin during acquisition, and the temporary cookie
file is removed before acquired files are staged.

Production installation uses Stashd's OCI installer (`stashd:plugin-install
ghcr.io/lost-and-fonds/youtube:<version>`). Composer is for local development
only. The package includes Deno for yt-dlp's YouTube challenge solving, plus
`yt-dlp` and FFmpeg. Stashd remains authoritative for identity, Vault state,
and promotion; this package owns YouTube protocol behavior only.

## Release artifact

`stashd-plugin/helpers.lock.json` pins Deno, yt-dlp, and FFmpeg payloads for
linux/amd64 and linux/arm64. Core verifies and materializes them; host PATH is
not used.
