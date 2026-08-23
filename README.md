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

## Release artifact

Run `tools/build-oci.sh out/plugin.oci` after installing production dependencies. The output is an OCI image layout; helper-bearing plugins require pinned executable payloads through `PLUGIN_HELPERS_DIR`.

Helper release inputs are deliberate: set `PLUGIN_HELPERS_DIR` to a directory containing the pinned, checksum-verified helper executables before running `tools/build-oci.sh`. The build refuses missing helpers and never uses host PATH.
