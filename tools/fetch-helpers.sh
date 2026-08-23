#!/bin/sh
set -eu
VERSION=2026.08.19
ARCH=${OCI_ARCH:-amd64}
case "$ARCH" in amd64) asset=yt-dlp_linux; sha=58162f9bfdc27458ea47bfcb311cf47028f17d8154a8bf7d689861d46399230a;; arm64) asset=yt-dlp_linux_aarch64; sha=b16e4dab368a816cd05d477d698a605a6ae87ccee1c8ffd38fa21d7254141fcc;; *) echo "unsupported architecture: $ARCH" >&2; exit 2;; esac
out=${PLUGIN_HELPERS_DIR:?set PLUGIN_HELPERS_DIR}/yt-dlp
mkdir -p "$(dirname "$out")"
curl -fsSL "https://github.com/yt-dlp/yt-dlp/releases/download/$VERSION/$asset" -o "$out"
printf '%s  %s\n' "$sha" "$out" | sha256sum -c -
chmod 0555 "$out"
test -n "${FFMPEG_PATH:-}" || { echo 'set FFMPEG_PATH to a pinned, verified ffmpeg payload' >&2; exit 1; }
cp "$FFMPEG_PATH" "$(dirname "$out")/ffmpeg"
chmod 0555 "$(dirname "$out")/ffmpeg"
