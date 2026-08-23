#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
OUT=${1:?output OCI directory}
rm -rf "$OUT"
mkdir -p "$OUT/blobs/sha256"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
cp -a "$ROOT" "$tmp/plugin"
rm -rf "$tmp/plugin/.git" "$tmp/plugin/vendor" "$tmp/plugin/tests" "$tmp/plugin/tools"
COMPOSER_VENDOR_DIR="$tmp/plugin/vendor" /usr/bin/php /usr/bin/composer install --working-dir="$tmp/plugin" --no-dev --no-interaction --no-progress --optimize-autoloader
case "$(basename "$ROOT")" in
  podcast) required='ffmpeg' ;;
  youtube) required='yt-dlp ffmpeg' ;;
  *) required='' ;;
esac
if [ -n "$required" ]; then
  helpers=${PLUGIN_HELPERS_DIR:?set PLUGIN_HELPERS_DIR to pinned helper payloads}
  mkdir -p "$tmp/plugin/stashd-plugin/helpers"
  for helper in $required; do
    test -x "$helpers/$helper" || { echo "missing pinned helper: $helpers/$helper" >&2; exit 1; }
    cp "$helpers/$helper" "$tmp/plugin/stashd-plugin/helpers/$helper"
  done
fi
(cd "$tmp/plugin" && tar --format=ustar -cf "$tmp/layer.tar" *)
layer=$(sha256sum "$tmp/layer.tar" | cut -d' ' -f1)
cp "$tmp/layer.tar" "$OUT/blobs/sha256/$layer"
plugin_id=$(basename "$ROOT")
config_json=$(printf '{"architecture":"%s","os":"linux","rootfs":{"type":"layers","diff_ids":["sha256:%s"]},"config":{"Labels":{"org.opencontainers.image.title":"%s","org.opencontainers.image.version":"%s","io.stashd.plugin.id":"%s","io.stashd.plugin.manifest":"stashd-plugin/plugin.json"}}}' "${OCI_ARCH:-amd64}" "$layer" "$plugin_id" "${OCI_VERSION:-unknown}" "$plugin_id")
config=$(printf '%s' "$config_json" | sha256sum | cut -d' ' -f1)
printf '%s' "$config_json" > "$OUT/blobs/sha256/$config"
manifest_json=$(printf '{"schemaVersion":2,"config":{"mediaType":"application/vnd.oci.image.config.v1+json","digest":"sha256:%s","size":%s},"layers":[{"mediaType":"application/vnd.oci.image.layer.v1.tar","digest":"sha256:%s","size":%s}]}' "$config" "$(wc -c < "$OUT/blobs/sha256/$config")" "$layer" "$(wc -c < "$tmp/layer.tar")")
manifest=$(printf '%s' "$manifest_json" | sha256sum | cut -d' ' -f1)
printf '%s' "$manifest_json" > "$OUT/blobs/sha256/$manifest"
printf '{"imageLayoutVersion":"1.0.0"}' > "$OUT/oci-layout"
printf '{"schemaVersion":2,"manifests":[{"mediaType":"application/vnd.oci.image.manifest.v1+json","digest":"sha256:%s","size":%s,"platform":{"os":"linux","architecture":"%s"}}]}' "$manifest" "$(wc -c < "$OUT/blobs/sha256/$manifest")" "${OCI_ARCH:-amd64}" > "$OUT/index.json"
printf 'sha256:%s\n' "$manifest"
