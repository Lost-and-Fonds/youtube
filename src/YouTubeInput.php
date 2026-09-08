<?php

declare(strict_types=1);

namespace YouTube;

use DateTimeImmutable;
use RuntimeException;
use Stashd\PluginSdk\AcquisitionOptions;
use Stashd\PluginSdk\AcquisitionResult;
use Stashd\PluginSdk\ArtifactRole;
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
use Stashd\PluginSdk\SourceDescriptor;
use Stashd\PluginSdk\StagedArtifact;
use Stashd\PluginSdk\UnavailableArtifact;
use Throwable;
use Uri\Rfc3986\Uri;

final class YouTubeInput implements InputPlugin
{
    public function __construct(private readonly PluginContext $context) {}

    public function resolve(SourceDescriptor $source): ResolvedInput
    {
        $source = $source->text('url') ?? throw new RuntimeException('A YouTube URL is required');
        $parsed = $this->parse($source);

        if (in_array($parsed['kind'], ['channel-page', 'channel', 'playlist'], true)) {
            $html = $this->body($this->http('GET', $parsed['canonical']));
            $canonical = $this->https($this->meta($html, 'og:url') ?? $parsed['canonical']);
            $title = $this->meta($html, 'og:title');
            $artwork = $parsed['kind'] === 'playlist'
                ? ($this->playlistAvatar($html) ?? $this->meta($html, 'og:image'))
                : $this->meta($html, 'og:image');

            if ($parsed['kind'] === 'channel-page') {
                preg_match('~/channel/(UC[\\w-]+)~', $canonical, $match);
                $id = $match[1] ?? null;
                $id ??= preg_match('/"channelId":"(UC[\\w-]+)"/', $html, $match) === 1 ? $match[1] : null;

                if ($id === null) {
                    throw new RuntimeException('channel identity was not found');
                }
                $canonical = $this->url("https://www.youtube.com/channel/{$id}");
            } else {
                $id = $parsed['id'];
            }

            return new ResolvedInput($parsed['kind'] === 'playlist' ? "playlist:{$id}" : $id, $canonical, $parsed['kind'] === 'channel-page' ? 'channel' : $parsed['kind'], $title, $artwork);
        }
        $id = $parsed['id'];
        $title = null;

        if ($parsed['kind'] === 'video') {
            $payload = $this->json($this->http('GET', $this->url('https://www.youtube.com/oembed', ['format' => 'json', 'url' => $parsed['canonical']])));
            $title = is_string($payload['title'] ?? null) && $payload['title'] !== '' ? $payload['title'] : "YouTube Video {$id}";
        }

        [$sizeBytes, $sizeEstimated] = $parsed['kind'] === 'video' && $this->context->helpers !== null
            ? $this->sizeEstimate($parsed['canonical'])
            : [null, false];

        return new ResolvedInput("{$parsed['kind']}:{$id}", $parsed['canonical'], $parsed['kind'], $title, null, null, $sizeBytes, $sizeEstimated);
    }

    /**
     * @param list<InputOption> $options
     * @return list<DiscoveredItem>
     */
    public function discover(string $inputId, DiscoveryIntent $intent, array $options = []): array
    {
        [$kind, $id] = str_contains($inputId, ':') ? explode(':', $inputId, 2) : ['channel', $inputId];

        if ($kind === 'video') {
            return $this->video($id);
        }

        if ($intent === DiscoveryIntent::Refresh && $kind === 'channel') {
            /** @var list<DiscoveredItem> $items */
            $items = $this->feed($this->url('https://www.youtube.com/feeds/videos.xml', ['channel_id' => $id]));

            try {
                $items = $this->enrich($items);
            } catch (Throwable) {
                // The API key is optional; Atom discovery remains the fallback.
            }

            return $this->filter($items, $options);
        }

        try {
            return $this->completeWithApi($kind, $id, $options);
        } catch (Throwable) {
            return $this->completeWithYtDlp($kind, $id, $options);
        }
    }

    /**
     * @param list<InputOption> $options
     * @return list<DiscoveredItem>
     */
    private function completeWithApi(string $kind, string $id, array $options): array
    {

        if ($kind === 'channel') {
            $channel = $this->json($this->http('GET', $this->url('https://www.googleapis.com/youtube/v3/channels', ['part' => 'contentDetails', 'id' => $id]), credential: 'youtube-data-api'));
            $channelItems = is_array($channel['items'] ?? null) ? $channel['items'] : [];
            $channelItem = is_array($channelItems[0] ?? null) ? $channelItems[0] : [];
            $contentDetails = is_array($channelItem['contentDetails'] ?? null) ? $channelItem['contentDetails'] : [];
            $relatedPlaylists = is_array($contentDetails['relatedPlaylists'] ?? null) ? $contentDetails['relatedPlaylists'] : [];
            $id = is_string($relatedPlaylists['uploads'] ?? null) ? $relatedPlaylists['uploads'] : '';

            if ($id === '') {
                throw new RuntimeException('channel uploads playlist was not found');
            }
            $kind = 'playlist';
        }
        /** @var list<DiscoveredItem> $items */
        $items = [];
        $token = null;

        do {
            $query = ['part' => 'snippet', 'maxResults' => 50, 'playlistId' => $id];

            if ($token !== null) {
                $query['pageToken'] = $token;
            }
            $url = $this->url('https://www.googleapis.com/youtube/v3/playlistItems', $query);
            $payload = $this->json($this->http('GET', $url, credential: 'youtube-data-api'));

            $entries = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $snippet = $this->object($entry['snippet'] ?? null) ?? [];
                $resourceId = $this->object($snippet['resourceId'] ?? null) ?? [];
                $entryId = $this->object($entry['id'] ?? null) ?? [];
                $videoId = $kind === 'playlist' ? $this->string($resourceId['videoId'] ?? null) : $this->string($entryId['videoId'] ?? null);

                if (is_string($videoId) && $videoId !== '') {
                    $items[] = $this->item($videoId, $snippet);
                }
            }
            $token = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while ($token !== null);

        $items = $this->enrich($items);

        return $this->filter($this->bool($options, 'skip_size_enrichment') ? $items : $this->enrichSizes($items), $options);
    }

    /**
     * @param list<InputOption> $options
     * @return list<DiscoveredItem>
     */
    private function completeWithYtDlp(string $kind, string $id, array $options): array
    {
        if ($this->context->helpers === null) {
            throw new RuntimeException('YouTube complete discovery requires an API key or yt-dlp');
        }
        $url = $kind === 'playlist'
            ? $this->url('https://www.youtube.com/playlist', ['list' => $id])
            : $this->url('https://www.youtube.com/channel/' . rawurlencode($id));
        $result = $this->context->helpers->run('yt-dlp', ['--ignore-errors', '--flat-playlist', '--dump-single-json', '--skip-download', '--no-warnings', $url]);

        $payload = json_decode($result->stdout, true);

        if (! is_array($payload)) {
            throw new RuntimeException('yt-dlp returned invalid discovery data');
        }
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];

        if ($result->exitCode !== 0 && $entries === []) {
            throw new RuntimeException('yt-dlp complete discovery failed');
        }
        /** @var list<DiscoveredItem> $items */
        $items = [];

        foreach ($this->objects($entries) as $entry) {
            if (! is_string($entry['id'] ?? null) || $entry['id'] === '') {
                continue;
            }
            $videoId = $entry['id'];
            $published = null;

            if (is_int($entry['timestamp'] ?? null)) {
                $published = (new DateTimeImmutable('@' . $entry['timestamp']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339);
            } elseif (is_string($entry['upload_date'] ?? null) && preg_match('/^\d{8}$/', $entry['upload_date']) === 1) {
                $date = DateTimeImmutable::createFromFormat('!Ymd', $entry['upload_date'], new \DateTimeZone('UTC'));
                $published = $date instanceof DateTimeImmutable ? $date->format(DATE_RFC3339) : null;
            }
            [$sizeBytes, $sizeEstimated] = $this->sizeFromEntry($entry);
            $items[] = new DiscoveredItem($videoId, $this->url('https://www.youtube.com/watch', ['v' => $videoId]), $this->string($entry['title'] ?? null) ?? $videoId, $this->string($entry['description'] ?? null), $published, $this->string($entry['thumbnail'] ?? null), is_int($entry['duration'] ?? null) ? $entry['duration'] : null, $this->string($entry['live_status'] ?? null), $sizeBytes, $sizeEstimated);
        }

        foreach ($this->incompleteItems($result->stderr) as $item) {
            $items[] = $item;
        }

        return $this->filter($items, $options);
    }

    /** @return list<DiscoveredItem> */
    private function incompleteItems(string $stderr): array
    {
        $items = [];

        foreach (preg_split('/\R+/', $stderr) ?: [] as $line) {
            if (preg_match('/ERROR: \[youtube\] ([\w-]+): .*?(?:country|region)/i', $line, $match) !== 1) {
                continue;
            }
            $id = $match[1];

            $items[] = new DiscoveredItem($id, $this->url('https://www.youtube.com/watch', ['v' => $id]), "Unavailable YouTube item ({$id})", upstreamState: 'region_blocked');
        }

        return $items;
    }

    /**
     * @param list<DiscoveredItem> $items
     * @return list<DiscoveredItem>
     */
    private function enrichSizes(array $items): array
    {
        if ($this->context->helpers === null || $items === []) {
            return $items;
        }

        try {
            $sizes = [];
            $batches = array_chunk($items, 20);

            foreach ($batches as $index => $batch) {
                $this->context->progress->report(sprintf('Inspecting video metadata (%d of %d)', $index + 1, count($batches)), $index / max(1, count($batches)));
                $arguments = ['--ignore-errors', '--dump-json', '--skip-download', '--no-warnings', '--format', 'bestvideo+bestaudio/best', ...array_map(static fn(DiscoveredItem $item): string => $item->reference, $batch)];
                $result = $this->context->helpers->run('yt-dlp', $arguments);

                foreach (preg_split('/\R+/', $result->stdout) ?: [] as $line) {
                    $entry = $this->object(json_decode(trim($line), true));

                    if (is_array($entry) && is_string($entry['id'] ?? null)) {
                        $sizes[$entry['id']] = $entry;
                    }
                }
                $this->context->progress->report(sprintf('Inspected video metadata (%d of %d)', $index + 1, count($batches)), ($index + 1) / max(1, count($batches)));
            }

            return array_map(function (DiscoveredItem $item) use ($sizes): DiscoveredItem {
                $metadata = $sizes[$item->id] ?? [];
                $sizeBytes = $item->sizeBytes;
                $sizeEstimated = $item->sizeEstimated;

                if ($metadata !== []) {
                    [$sizeBytes, $sizeEstimated] = $this->sizeFromEntry($metadata);
                }
                $published = $item->publishedAt;

                if (is_int($metadata['timestamp'] ?? null)) {
                    $published = (new DateTimeImmutable('@' . $metadata['timestamp']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_RFC3339);
                } elseif (is_string($metadata['upload_date'] ?? null) && preg_match('/^\d{8}$/', $metadata['upload_date']) === 1) {
                    $date = DateTimeImmutable::createFromFormat('!Ymd', $metadata['upload_date'], new \DateTimeZone('UTC'));
                    $published = $date instanceof DateTimeImmutable ? $date->format(DATE_RFC3339) : null;
                }

                $duration = is_int($metadata['duration'] ?? null) || is_float($metadata['duration'] ?? null) ? (int) $metadata['duration'] : $item->durationSeconds;

                return new DiscoveredItem($item->id, $item->reference, $this->string($metadata['title'] ?? null) ?? $item->title, $this->string($metadata['description'] ?? null) ?? $item->description, $published, $this->string($metadata['thumbnail'] ?? null) ?? $item->artworkReference, $duration, $this->string($metadata['live_status'] ?? null) ?? $item->kind, $sizeBytes, $sizeEstimated, $item->upstreamState);
            }, $items);
        } catch (Throwable) {
            return $items;
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{0:?int,1:bool}
     */
    private function sizeFromEntry(array $entry): array
    {
        $formats = $this->objects($entry['requested_formats'] ?? null);
        $formats = $formats === [] ? [$entry] : $formats;
        $total = 0;
        $estimated = false;

        foreach ($formats as $format) {
            $exact = $format['filesize'] ?? null;
            $approx = $format['filesize_approx'] ?? null;
            $size = is_int($exact) || is_float($exact) ? $exact : $approx;

            if (! is_int($size) && ! is_float($size)) {
                return [null, false];
            }
            $total += (int) $size;
            $estimated = $estimated || ! (is_int($exact) || is_float($exact));
        }

        return [$total > 0 ? $total : null, $total > 0 && $estimated];
    }

    public function acquire(DiscoveredItem $item, AcquisitionOptions $options): AcquisitionResult
    {
        if ($this->context->staging === null || $this->context->helpers === null) {
            throw new RuntimeException('acquisition capabilities are unavailable');
        }
        $requested = $options->requestedRoles;
        $roles = $requested === null ? [ArtifactRole::Primary, ArtifactRole::Captions, ArtifactRole::Artwork, ArtifactRole::Metadata] : $requested;
        $wantsPrimary = in_array(ArtifactRole::Primary, $roles, true);
        $output = 'youtube-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $item->id);
        $args = ['--no-playlist', '--newline', '--no-warnings', '--progress', '--restrict-filenames', '--progress-template', 'download:progress=%(progress._percent_str)s', '--ffmpeg-location', '/plugin/stashd-plugin/helpers', '--print', 'after_move:filepath', '--output', $output . '.%(ext)s'];

        if (! $wantsPrimary) {
            $args[] = '--skip-download';
        }

        if (in_array(ArtifactRole::Metadata, $roles, true)) {
            $args[] = '--write-info-json';
        }

        if (in_array(ArtifactRole::Artwork, $roles, true)) {
            $args[] = '--write-thumbnail';
        }

        if ($wantsPrimary) {
            if ($options->mediaKind === MediaKind::Audio) {
                array_push($args, '--extract-audio', '--audio-format', 'mp3', '--audio-quality', '128K');
            } else {
                array_push($args, '--format', 'bestvideo+bestaudio/best', '--merge-output-format', 'mp4');
            }
        }

        if (in_array(ArtifactRole::Captions, $roles, true) && $this->bool($options->options, 'include_captions', true)) {
            array_push($args, '--write-subs', '--sub-format', 'vtt', '--sub-langs', $this->text($options->options, 'caption_languages') ?? 'en');

            if ($this->bool($options->options, 'include_auto_captions') || $this->bool($options->options, 'include_auto')) {
                $args[] = '--write-auto-subs';
            }
        }
        $args[] = $item->reference;
        $result = $this->context->helpers->run('yt-dlp', $args, function (string $channel, string $buffer): void {
            if (preg_match('/progress=\s*([0-9]+(?:\.[0-9]+)?)%/', $buffer, $match) !== 1) {
                return;
            }

            $fraction = min(1.0, max(0.0, (float) $match[1] / 100));
            $this->context->progress->report('Downloading', $fraction);
        });

        if ($result->exitCode !== 0 && $requested !== null && preg_match('/(?:country|region|not available in your country)/i', $result->stderr) === 1) {
            return new AcquisitionResult(unavailable: array_map(static fn(ArtifactRole $role): UnavailableArtifact => new UnavailableArtifact($role, true, 'The requested asset is unavailable in this region.'), $roles));
        }

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

        if ($requested === null && ! array_filter($artifacts, static fn(StagedArtifact $artifact): bool => $artifact->role === 'primary')) {
            throw new RuntimeException('acquisition produced no primary media artifact');
        }

        if ($requested !== null) {
            $found = array_values(array_filter(array_map(static fn(StagedArtifact $artifact): ?ArtifactRole => $artifact->role === null ? null : ArtifactRole::tryFrom($artifact->role), $artifacts)));
            $unavailable = [];

            foreach ($roles as $role) {
                if (! in_array($role, $found, true)) {
                    $unavailable[] = new UnavailableArtifact($role, true, 'The requested asset was not provided by YouTube.');
                }
            }

            return new AcquisitionResult($artifacts, $unavailable);
        }

        return new AcquisitionResult($artifacts);
    }

    /** @return list<InputOption> */
    public function options(): array
    {
        return [new InputOption('include_shorts', OptionValue::boolean(false)), new InputOption('include_live', OptionValue::boolean(false)), new InputOption('include_captions', OptionValue::boolean(true)), new InputOption('include_auto_captions', OptionValue::boolean(false)), new InputOption('caption_languages', OptionValue::text('en'))];
    }

    /** @return array{kind:string,id:string,canonical:string} */
    private function parse(string $source): array
    {
        $url = Uri::parse($this->https($source));

        if ($url === null) {
            throw new RuntimeException('unsupported YouTube URL');
        }
        $host = strtolower((string) ($url->getHost() ?? ''));
        $path = $url->getPath();
        parse_str($url->getQuery() ?? '', $query);
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
            return ['kind' => 'playlist', 'id' => $query['list'], 'canonical' => $this->url('https://www.youtube.com/playlist', ['list' => $query['list']])];
        }

        if (str_starts_with($path, '/channel/')) {
            $id = trim(substr($path, 9), '/');

            if ($id !== '') {
                return ['kind' => 'channel', 'id' => $id, 'canonical' => $this->url('https://www.youtube.com/channel/' . rawurlencode($id))];
            }
        }

        foreach (['c', 'user'] as $prefix) {
            if (str_starts_with($path, '/' . $prefix . '/')) {
                return ['kind' => 'channel-page', 'id' => trim(substr($path, strlen($prefix) + 2), '/'), 'canonical' => $this->https($source)];
            }
        }

        if (str_starts_with($path, '/@')) {
            return ['kind' => 'channel-page', 'id' => trim($path, '/'), 'canonical' => $this->https($source)];
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

        return ['kind' => 'video', 'id' => $id, 'canonical' => $this->url('https://www.youtube.com/watch', ['v' => $id])];
    }

    private function https(string $url): string
    {
        $uri = Uri::parse($url);

        if ($uri === null) {
            throw new RuntimeException('invalid YouTube URL');
        }

        return strcasecmp($uri->getScheme() ?? '', 'http') === 0 ? $uri->withScheme('https')->toString() : $uri->toString();
    }

    /** @return list<DiscoveredItem> */
    private function feed(string $url): array
    {
        $xml = $this->body($this->http('GET', $url));
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

            if ($id === '') {
                continue;
            }
            $description = (string) (($entry->xpath('media:group/media:description')[0] ?? ''));
            $thumbnail = $entry->xpath('media:group/media:thumbnail');
            $artwork = isset($thumbnail[0]['url']) ? (string) $thumbnail[0]['url'] : null;
            $items[] = new DiscoveredItem($id, $this->url('https://www.youtube.com/watch', ['v' => $id]), (string) ($entry->title ?? $id), $description !== '' ? $description : null, (string) ($entry->published ?? null), $artwork);
        }

        return $items;
    }

    /** @return list<DiscoveredItem> */
    private function video(string $id): array
    {
        $reference = $this->url('https://www.youtube.com/watch', ['v' => $id]);
        $url = $this->url('https://www.youtube.com/oembed', ['format' => 'json', 'url' => $reference]);
        $payload = $this->json($this->http('GET', $url));

        [$sizeBytes, $sizeEstimated] = $this->context->helpers !== null ? $this->sizeEstimate($reference) : [null, false];

        return [new DiscoveredItem($id, $reference, $this->string($payload['title'] ?? null) ?? $id, artworkReference: $this->string($payload['thumbnail_url'] ?? null), sizeBytes: $sizeBytes, sizeEstimated: $sizeEstimated)];
    }

    /** @param array<string, mixed> $snippet */
    private function item(string $id, array $snippet): DiscoveredItem
    {
        $thumbnails = $this->object($snippet['thumbnails'] ?? null);
        $high = $this->object($thumbnails['high'] ?? null);

        return new DiscoveredItem($id, $this->url('https://www.youtube.com/watch', ['v' => $id]), $this->string($snippet['title'] ?? null) ?? $id, $this->string($snippet['description'] ?? null), $this->string($snippet['publishedAt'] ?? null), $this->string($high['url'] ?? null));
    }

    /**
     * @param list<DiscoveredItem> $items
     * @return list<DiscoveredItem>
     */
    private function enrich(array $items): array
    {
        /** @var array<string, DiscoveredItem> $byId */
        $byId = [];

        foreach (array_chunk($items, 50) as $batch) {
            $ids = implode(',', array_map(static fn(DiscoveredItem $item): string => $item->id, $batch));
            $payload = $this->json($this->http('GET', $this->url('https://www.googleapis.com/youtube/v3/videos', ['part' => 'snippet,contentDetails,liveStreamingDetails', 'id' => $ids]), credential: 'youtube-data-api'));

            foreach ($this->objects($payload['items'] ?? null) as $entry) {
                $id = $this->string($entry['id'] ?? null) ?? '';

                if ($id === '') {
                    continue;
                }
                $snippet = $this->object($entry['snippet'] ?? null) ?? [];
                $thumbnails = $this->object($snippet['thumbnails'] ?? null);
                $high = $this->object($thumbnails['high'] ?? null);
                $contentDetails = $this->object($entry['contentDetails'] ?? null) ?? [];
                $byId[$id] = new DiscoveredItem($id, $this->url('https://www.youtube.com/watch', ['v' => $id]), $this->string($snippet['title'] ?? null) ?? $id, $this->string($snippet['description'] ?? null), $this->string($snippet['publishedAt'] ?? null), $this->string($high['url'] ?? null), $this->duration($contentDetails['duration'] ?? null), isset($entry['liveStreamingDetails']) ? 'live' : null);
            }
        }

        return array_map(static fn(DiscoveredItem $item): DiscoveredItem => $byId[$item->id] ?? $item, $items);
    }

    private function duration(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $value, $match) !== 1) {
            return null;
        }

        return ((int) ($match[1] ?? 0) * 3600) + ((int) ($match[2] ?? 0) * 60) + (int) ($match[3] ?? 0);
    }

    /**
     * @param list<DiscoveredItem> $items
     * @param list<InputOption> $options
     * @return list<DiscoveredItem>
     */
    private function filter(array $items, array $options): array
    {
        $shorts = $this->bool($options, 'include_shorts');
        $live = $this->bool($options, 'include_live');

        return array_values(array_filter($items, static function (DiscoveredItem $item) use ($shorts, $live): bool {
            if ($item->kind === 'short' && ! $shorts) {
                return false;
            }

            return ! in_array($item->kind, ['live', 'premiere'], true) || $live;
        }));
    }

    /** @param array<string, string> $headers */
    private function http(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
    {
        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; Stashd/1.0)', ...$headers];
        $response = $this->context->http->request($method, $url, $headers, $body, $credential);

        if ($response->status === 404) {
            throw new RuntimeException('YouTube resource not found');
        }

        if ($response->status === 429) {
            throw new RuntimeException('YouTube rate limit reached');
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw new RuntimeException('YouTube request failed');
        }

        return $response;
    }

    /** @param array<string, scalar> $query */
    private function url(string $base, array $query = []): string
    {
        $uri = Uri::parse($base);

        if ($uri === null) {
            throw new RuntimeException('invalid URL');
        }

        return $query === [] ? $uri->toString() : $uri->withQuery(http_build_query($query))->toString();
    }

    /** @return array<string, mixed> */
    private function json(HttpResponse $response): array
    {
        $value = $this->object(json_decode($this->body($response), true));

        if ($value === null) {
            throw new RuntimeException('YouTube response was invalid JSON');
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function object(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $object = [];

        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $object[$key] = $entry;
            }
        }

        return $object;
    }

    /** @return list<array<string, mixed>> */
    private function objects(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $objects = [];

        foreach ($value as $entry) {
            $object = $this->object($entry);

            if ($object !== null) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function body(HttpResponse $response): string
    {
        if ($response->inlineBody !== null) {
            return $response->inlineBody;
        }

        if ($response->resource === null) {
            throw new RuntimeException('YouTube response body was unavailable');
        }
        $body = '';

        while (! $response->resource->isEof()) {
            $body .= $response->resource->read();
        }
        $response->resource->close();

        return $body;
    }

    private function meta(string $html, string $name): ?string
    {
        return preg_match('/<meta[^>]+property=["\']' . preg_quote($name, '/') . '["\'][^>]+content=["\']([^"\']+)/i', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
    }

    private function playlistAvatar(string $html): ?string
    {
        return preg_match('/"avatar".*?"url":"([^"]+)/s', $html, $m) === 1
            ? html_entity_decode(str_replace('\\u0026', '&', $m[1]))
            : null;
    }

    /** @param list<InputOption> $options */
    private function bool(array $options, string $key, bool $default = false): bool
    {
        foreach ($options as $option) {
            if ($option->key === $key) {
                return $option->value->kind === 'boolean' && $option->value->value;
            }
        }

        return $default;
    }

    /** @param list<InputOption> $options */
    private function text(array $options, string $key): ?string
    {
        foreach ($options as $option) {
            if ($option->key === $key) {
                return (string) $option->value->value;
            }
        }

        return null;
    }

    private function role(string $name, MediaKind $kind): ?string
    {
        $lower = strtolower($name);

        if (str_ends_with($lower, '.info.json')) {
            return 'metadata';
        }

        if (str_ends_with($lower, '.vtt')) {
            return 'captions';
        }

        if (preg_match('/\.(jpe?g|png|webp)$/', $lower)) {
            return 'artwork';
        }

        if (preg_match('/\.(mp4|mkv|webm|mp3|m4a|opus)$/', $lower)) {
            return 'primary';
        }

        return null;
    }

    private function mediaType(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'opus' => 'audio/ogg', 'vtt' => 'text/vtt', 'json' => 'application/json', 'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => 'application/octet-stream',
        };
    }

    /** @return array{0:?int,1:bool} */
    private function sizeEstimate(string $reference): array
    {
        if ($this->context->helpers === null) {
            return [null, false];
        }

        try {
            $result = $this->context->helpers->run('yt-dlp', ['--no-playlist', '--no-warnings', '--dump-single-json', '--skip-download', '--format', 'bestvideo+bestaudio/best', $reference]);
        } catch (Throwable) {
            return [null, false];
        }

        $data = $this->object(json_decode(trim($result->stdout), true));

        if ($data === null) {
            foreach (array_reverse(preg_split('/\R+/', $result->stdout) ?: []) as $line) {
                $data = $this->object(json_decode(trim($line), true));

                if ($data !== null) {
                    break;
                }
            }
        }

        if ($result->exitCode !== 0 || $data === null) {
            return [null, false];
        }
        $formats = is_array($data['requested_formats'] ?? null) ? $data['requested_formats'] : [$data];
        $total = 0;
        $estimated = false;

        foreach ($formats as $format) {
            if (! is_array($format)) {
                return [null, false];
            }
            $exact = $format['filesize'] ?? null;
            $approx = $format['filesize_approx'] ?? null;
            $size = is_int($exact) || is_float($exact) ? $exact : $approx;

            if (! is_int($size) && ! is_float($size)) {
                return [null, false];
            }
            $total += (int) $size;
            $estimated = $estimated || ! (is_int($exact) || is_float($exact));
        }

        return [$total > 0 ? $total : null, $total > 0 && $estimated];
    }
}
