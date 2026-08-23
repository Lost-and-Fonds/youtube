<?php

declare(strict_types=1);

namespace YouTube;

use DateTimeImmutable;
use RuntimeException;
use Stashd\PluginSdk\AcquisitionOptions;
use Stashd\PluginSdk\AcquisitionResult;
use Stashd\PluginSdk\DiscoveredItem;
use Stashd\PluginSdk\DiscoveryIntent;
use Stashd\PluginSdk\HttpResponse;
use Stashd\PluginSdk\InputOption;
use Stashd\PluginSdk\InputPlugin;
use Stashd\PluginSdk\ItemResource;
use Stashd\PluginSdk\MediaKind;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\ResolvedInput;
use Stashd\PluginSdk\StagedArtifact;

final class YouTubeInput implements InputPlugin
{
    public function __construct(private readonly PluginContext $context) {}

    public function resolve(string $source): ResolvedInput
    {
        $parsed = $this->parse($source);
        if ($parsed['kind'] === 'channel-page') {
            $response = $this->http('GET', $source);
            if ($response->status === 404) {
                throw new RuntimeException('input not found');
            }
            if ($response->status < 200 || $response->status >= 300) {
                throw new RuntimeException('input page unavailable');
            }
            $html = $response->body();
            preg_match('/"channelId":"(UC[\w-]+)"/', $html, $match);
            $id = $match[1] ?? null;
            if ($id === null) {
                throw new RuntimeException('channel identity was not found');
            }
            $title = $this->meta($html, 'og:title');
            $artwork = $this->meta($html, 'og:image');

            return new ResolvedInput($id, "https://www.youtube.com/channel/{$id}", 'channel', $title, $artwork);
        }
        $id = $parsed['id'];
        return new ResolvedInput($parsed['kind'] === 'video' ? "video:{$id}" : "{$parsed['kind']}:{$id}", $parsed['canonical'], $parsed['kind'], $parsed['kind'] === 'video' ? "YouTube Video {$id}" : null);
    }

    public function discover(string $inputId, DiscoveryIntent $intent, array $options = []): array
    {
        [$kind, $id] = str_contains($inputId, ':') ? explode(':', $inputId, 2) : ['channel', $inputId];
        if ($kind === 'video') {
            return $this->video($id);
        }
        if ($intent === DiscoveryIntent::Refresh) {
            $feed = $kind === 'playlist'
                ? "https://www.youtube.com/feeds/videos.xml?playlist_id=" . rawurlencode($id)
                : "https://www.youtube.com/feeds/videos.xml?channel_id=" . rawurlencode($id);
            return $this->filter($this->feed($feed), $options);
        }
        $this->requireApiCredential();
        if ($kind === 'channel') {
            $channel = $this->json($this->http('GET', 'https://www.googleapis.com/youtube/v3/channels?part=contentDetails&id=' . rawurlencode($id), credential: 'youtube-data-api'));
            $id = (string) ($channel['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '');
            if ($id === '') {
                throw new RuntimeException('channel uploads playlist was not found');
            }
            $kind = 'playlist';
        }
        $items = [];
        $token = null;
        do {
            $url = 'https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=50&playlistId=' . rawurlencode($id);
            if ($token !== null) {
                $url .= '&pageToken=' . rawurlencode($token);
            }
            $payload = $this->json($this->http('GET', $url, credential: 'youtube-data-api'));
            foreach ($payload['items'] ?? [] as $entry) {
                $snippet = is_array($entry['snippet'] ?? null) ? $entry['snippet'] : [];
                $videoId = $kind === 'playlist' ? ($snippet['resourceId']['videoId'] ?? null) : ($entry['id']['videoId'] ?? null);
                if (is_string($videoId) && $videoId !== '') {
                    $items[] = $this->item($videoId, $snippet);
                }
            }
            $token = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while ($token !== null);

        return $this->filter($items, $options);
    }

    public function acquire(DiscoveredItem $item, AcquisitionOptions $options): AcquisitionResult
    {
        if ($this->context->staging === null || $this->context->helpers === null) {
            throw new RuntimeException('acquisition capabilities are unavailable');
        }
        $output = 'youtube-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $item->id);
        $args = ['--no-playlist', '--newline', '--no-progress', '--no-warnings', '--restrict-filenames', '--print', 'after_move:filepath', '--output', $output . '.%(ext)s', '--write-info-json', '--write-thumbnail'];
        if ($options->mediaKind === MediaKind::Audio) {
            array_push($args, '--extract-audio', '--audio-format', 'mp3', '--audio-quality', '128K');
        } else {
            array_push($args, '--format', 'bestvideo[height<=1080]+bestaudio/best[height<=1080]', '--merge-output-format', 'mp4');
        }
        if ($this->bool($options->options, 'include_captions')) {
            array_push($args, '--write-subs', '--sub-format', 'vtt', '--sub-langs', $this->text($options->options, 'caption_languages') ?? 'en');
        }
        $args[] = $item->reference;
        $result = $this->context->helpers->run('yt-dlp', $args);
        if ($result->exitCode !== 0) {
            throw new RuntimeException($result->exitCode === 124 ? 'acquisition timed out' : 'acquisition helper failed');
        }
        $artifacts = [];
        foreach (preg_split('/\R+/', $result->stdout) ?: [] as $line) {
            $path = trim($line);
            if ($path === '' || str_contains($path, '/') === false) {
                continue;
            }
            $name = basename($path);
            $role = $this->role($name, $options->mediaKind);
            if ($role === null) {
                continue;
            }
            $staged = $this->context->staging->stage($name, $this->mediaType($name));
            $artifacts[] = new StagedArtifact($staged->reference, $staged->mediaType, $staged->sizeBytes, $role);
        }
        if (! array_filter($artifacts, static fn(StagedArtifact $artifact): bool => $artifact->role === 'primary')) {
            throw new RuntimeException('acquisition produced no primary media artifact');
        }

        return new AcquisitionResult($artifacts);
    }

    /** @return list<InputOption> */
    public function options(): array
    {
        return [new InputOption('include_shorts', OptionValue::boolean(false)), new InputOption('include_live', OptionValue::boolean(false)), new InputOption('include_captions', OptionValue::boolean(false)), new InputOption('caption_languages', OptionValue::text('en'))];
    }

    /** @return array{kind:string,id:string,canonical:string} */
    private function parse(string $source): array
    {
        $url = parse_url($source);
        $host = strtolower((string) ($url['host'] ?? ''));
        $path = (string) ($url['path'] ?? '');
        parse_str((string) ($url['query'] ?? ''), $query);
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        if ($host === 'youtu.be') {
            $id = trim($path, '/');
            return $this->videoRef($id);
        }
        if (! in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            throw new RuntimeException('unsupported YouTube URL');
        }
        if (isset($query['v']) && is_string($query['v']) && $query['v'] !== '') {
            return $this->videoRef($query['v']);
        }
        if (isset($query['list']) && is_string($query['list']) && $query['list'] !== '') {
            return ['kind' => 'playlist', 'id' => $query['list'], 'canonical' => 'https://www.youtube.com/playlist?list=' . rawurlencode($query['list'])];
        }
        if (str_starts_with($path, '/channel/')) {
            $id = trim(substr($path, 9), '/');
            if ($id !== '') return ['kind' => 'channel', 'id' => $id, 'canonical' => 'https://www.youtube.com/channel/' . rawurlencode($id)];
        }
        foreach (['c', 'user'] as $prefix) {
            if (str_starts_with($path, '/' . $prefix . '/')) {
                return ['kind' => 'channel-page', 'id' => trim(substr($path, strlen($prefix) + 2), '/'), 'canonical' => $source];
            }
        }
        if (str_starts_with($path, '/@')) {
            return ['kind' => 'channel-page', 'id' => trim($path, '/'), 'canonical' => $source];
        }
        if (str_starts_with($path, '/shorts/')) {
            return $this->videoRef(explode('/', trim(substr($path, 8), '/'))[0], true);
        }
        throw new RuntimeException('unsupported YouTube URL');
    }

    /** @return array{kind:string,id:string,canonical:string} */
    private function videoRef(string $id, bool $short = false): array
    {
        if ($id === '') {
            throw new RuntimeException('video ID is missing');
        }
        return ['kind' => 'video', 'id' => $id, 'canonical' => 'https://www.youtube.com/watch?v=' . rawurlencode($id)];
    }

    /** @return list<DiscoveredItem> */
    private function feed(string $url): array
    {
        $xml = $this->http('GET', $url)->body();
        $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($root === false) {
            throw new RuntimeException('YouTube feed was invalid');
        }
        $root->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
        $items = [];
        foreach ($root->xpath('//a:entry') ?: [] as $entry) {
            $entry->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
            $entry->registerXPathNamespace('media', 'http://search.yahoo.com/mrss/');
            $id = (string) (($entry->xpath('yt:videoId')[0] ?? ''));
            if ($id === '') continue;
            $description = (string) (($entry->xpath('media:group/media:description')[0] ?? ''));
            $thumbnail = $entry->xpath('media:group/media:thumbnail');
            $artwork = isset($thumbnail[0]['url']) ? (string) $thumbnail[0]['url'] : null;
            $items[] = new DiscoveredItem($id, 'https://www.youtube.com/watch?v=' . $id, (string) ($entry->title ?? $id), $description !== '' ? $description : null, (string) ($entry->published ?? null), $artwork);
        }
        return $items;
    }

    /** @return list<DiscoveredItem> */
    private function video(string $id): array
    {
        $url = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id);
        $payload = $this->json($this->http('GET', $url));
        return [new DiscoveredItem($id, 'https://www.youtube.com/watch?v=' . $id, (string) ($payload['title'] ?? $id), artworkReference: is_string($payload['thumbnail_url'] ?? null) ? $payload['thumbnail_url'] : null)];
    }

    private function item(string $id, array $snippet): DiscoveredItem
    {
        return new DiscoveredItem($id, 'https://www.youtube.com/watch?v=' . $id, (string) ($snippet['title'] ?? $id), $snippet['description'] ?? null, $snippet['publishedAt'] ?? null, $snippet['thumbnails']['high']['url'] ?? null);
    }

    /** @param list<InputOption> $options @param list<DiscoveredItem> $items */
    private function filter(array $items, array $options): array
    {
        $shorts = $this->bool($options, 'include_shorts');
        $live = $this->bool($options, 'include_live');
        return array_values(array_filter($items, static function (DiscoveredItem $item) use ($shorts, $live): bool {
            if ($item->kind === 'short' && ! $shorts) return false;
            return ! in_array($item->kind, ['live', 'premiere'], true) || $live;
        }));
    }

    private function http(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        $response = $this->context->http->request($method, $url, $headers, $body, $credential);
        if ($response->status === 404) throw new RuntimeException('YouTube resource not found');
        if ($response->status === 429) throw new RuntimeException('YouTube rate limit reached');
        if ($response->status < 200 || $response->status >= 300) throw new RuntimeException('YouTube request failed');
        return $response;
    }

    private function requireApiCredential(): void
    {
        $this->http('GET', 'https://www.googleapis.com/youtube/v3/channels?part=id&id=invalid', credential: 'youtube-data-api');
    }

    private function json(HttpResponse $response): array
    {
        $value = json_decode($response->body(), true);
        if (! is_array($value)) throw new RuntimeException('YouTube response was invalid JSON');
        return $value;
    }

    private function meta(string $html, string $name): ?string
    {
        return preg_match('/<meta[^>]+property=["\']' . preg_quote($name, '/') . '["\'][^>]+content=["\']([^"\']+)/i', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
    }

    private function bool(array $options, string $key): bool
    {
        foreach ($options as $option) if ($option->key === $key) return $option->value->kind === 'boolean' && $option->value->value;
        return false;
    }

    private function text(array $options, string $key): ?string
    {
        foreach ($options as $option) if ($option->key === $key) return (string) $option->value->value;
        return null;
    }

    private function role(string $name, MediaKind $kind): ?string
    {
        $lower = strtolower($name);
        if (str_ends_with($lower, '.info.json')) return 'metadata';
        if (str_ends_with($lower, '.vtt')) return 'captions';
        if (preg_match('/\.(jpe?g|png|webp)$/', $lower)) return 'artwork';
        if (preg_match('/\.(mp4|mkv|webm|mp3|m4a|opus)$/', $lower)) return 'primary';
        return null;
    }

    private function mediaType(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'opus' => 'audio/ogg', 'vtt' => 'text/vtt', 'json' => 'application/json', 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => 'application/octet-stream',
        };
    }
}
