<?php

declare(strict_types=1);

use Stashd\PluginSdk\AcquisitionOptions;
use Stashd\PluginSdk\ArtifactRole;
use Stashd\PluginSdk\HelperResult;
use Stashd\PluginSdk\HelperRunner;
use Stashd\PluginSdk\HttpClient;
use Stashd\PluginSdk\HttpResponse;
use Stashd\PluginSdk\InputOption;
use Stashd\PluginSdk\MediaKind;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\ProgressReporter;
use Stashd\PluginSdk\StagedArtifact;
use Stashd\PluginSdk\StagingArea;
use Stashd\PluginSdk\SourceDescriptor;
use YouTube\YouTubeInput;

spl_autoload_register(static function (string $class): void {
    foreach (['YouTube\\' => dirname(__DIR__, 2) . '/src/', 'Stashd\\PluginSdk\\' => dirname(__DIR__, 3) . '/plugin-sdk/src/'] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        }
    }
    expect(true)->toBeTrue();
});

it('preserves the YouTube provider contract', function (): void {
    function ytAssert(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new RuntimeException($message);
        }
    }

    final class YtHttp implements HttpClient
    {
        public bool $feedMissing = false;
        public bool $playlistFeedMissing = false;

        public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
        {
            if (str_contains($url, 'feeds/videos.xml')) {
                if ($this->feedMissing || $this->playlistFeedMissing && str_contains($url, 'playlist_id=')) {
                    return new HttpResponse(404);
                }

                if (str_contains($url, 'playlist_id=')) {
                    return new HttpResponse(200, inlineBody: '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015"><entry><title>Playlist item</title><published>2026-01-03T00:00:00Z</published><yt:videoId>playlist1</yt:videoId></entry></feed>');
                }

                return new HttpResponse(200, inlineBody: '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/"><entry><title>One</title><published>2026-01-01T00:00:00Z</published><yt:videoId>vid1</yt:videoId><media:group><media:description>Description</media:description><media:thumbnail url="https://i.ytimg.com/vid1.jpg" /></media:group></entry><entry><title>Two</title><published>2026-01-02T00:00:00Z</published><yt:videoId>vid2</yt:videoId></entry></feed>');
            }

            if (str_contains($url, 'oembed')) {
                return new HttpResponse(200, inlineBody: '{"title":"Video","thumbnail_url":"https://i.ytimg.com/x.jpg"}');
            }

            if (str_contains($url, 'playlist')) {
                return new HttpResponse(200, inlineBody: '<meta property="og:title" content="Playlist"><script>"avatar":{"avatarViewModel":{"image":{"sources":[{"url":"https://yt3.ggpht.com/channel-avatar"}]}}}</script>');
            }

            if (str_contains($url, 'channel/') || str_contains($url, '@fixture')) {
                return new HttpResponse(200, inlineBody: '<meta property="og:title" content="Channel"><script>"channelId":"UCfixture123"</script>');
            }

            if (str_contains($url, 'googleapis.com/youtube/v3/playlistItems')) {
                return new HttpResponse(404);
            }

            if (str_contains($url, 'googleapis.com/youtube/v3/videos')) {
                return new HttpResponse(200, inlineBody: '{"items":[{"id":"vid1","snippet":{"title":"One","publishedAt":"2026-01-01T00:00:00Z"},"contentDetails":{"duration":"PT1H15M"}}]}');
            }

            return new HttpResponse(200, inlineBody: '{"items":[],"nextPageToken":null}');
        }
    }

    final class YtStage implements StagingArea
    {
        /** @var list<string> */ public array $paths = [];
        public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact
        {
            return new StagedArtifact($relativePath, $mediaType ?? 'application/octet-stream', strlen($content));
        }
        public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact
        {
            $this->paths[] = $relativePath;

            return new StagedArtifact($relativePath, $mediaType ?? 'application/octet-stream', 10);
        }
    }

    final class YtHelper implements HelperRunner
    {
        /** @var list<string> */ public array $args = [];
        public int $completeExitCode = 0;
        public string $completeStderr = '';
        public bool $captionMissing = false;
        public string $captionPath = 'youtube-vid1.en.vtt';
        /** @var list<list<string>> */
        public array $metadataRequests = [];
        /** @var array<string, string> */
        public array $captionPaths = ['en' => 'youtube-vid1.en.vtt'];
        public function run(string $name, array $arguments = [], ?callable $onOutput = null): HelperResult
        {
            ytAssert($name === 'yt-dlp', 'wrong helper');
            $this->args = $arguments;

            if (in_array('--dump-json', $arguments, true)) {
                $this->metadataRequests[] = $arguments;

                return new HelperResult(0, json_encode(['id' => 'backfill1', 'title' => 'Backfill item', 'upload_date' => '20260101', 'duration' => 321, 'filesize_approx' => 1234], JSON_THROW_ON_ERROR));
            }

            if ($onOutput !== null && in_array('--progress-template', $arguments, true)) {
                $onOutput('err', "download:progress=35.0%\n");
            }

            if (str_contains((string) end($arguments), 'playlist?list=')) {
                return new HelperResult($this->completeExitCode, json_encode(['entries' => [
                    ['id' => 'backfill1', 'title' => 'Backfill item', 'upload_date' => '20260101', 'filesize_approx' => 1234],
                ]], JSON_THROW_ON_ERROR), $this->completeStderr);
            }

            if (str_contains((string) end($arguments), '/channel/')) {
                $entries = $this->completeExitCode === 0
                    ? [['id' => 'vid1', 'title' => 'One', 'duration' => 45], ['id' => 'vid2', 'title' => 'Two', 'duration' => 181]]
                    : [['id' => 'backfill1', 'title' => 'Backfill item', 'upload_date' => '20260101', 'filesize_approx' => 1234]];

                return new HelperResult($this->completeExitCode, json_encode(['entries' => $entries], JSON_THROW_ON_ERROR), $this->completeStderr);
            }

            if (in_array('--skip-download', $arguments, true)) {
                $print = array_values(array_filter($arguments, static fn(string $argument): bool => str_starts_with($argument, 'after_video:')));
                ytAssert($print === ['after_video:%(.{requested_subtitles,thumbnails,infojson_filename})j'], 'caption metadata print template was not configured correctly');

                return new HelperResult(0, json_encode([
                    'requested_subtitles' => $this->captionMissing ? [] : array_map(static fn(string $filepath): array => ['filepath' => $filepath], $this->captionPaths),
                ], JSON_THROW_ON_ERROR));
            }

            if (in_array('--write-auto-subs', $arguments, true)) {
                $print = array_values(array_filter($arguments, static fn(string $argument): bool => str_starts_with($argument, 'after_video:')));
                ytAssert($print === ['after_video:%(.{requested_subtitles,thumbnails,infojson_filename})j'], 'caption metadata print template was not configured correctly');

                return new HelperResult(0, "/staging/youtube-vid1.mp4\n/staging/youtube-vid1.info.json\n/staging/youtube-vid1.jpg\n" . json_encode([
                    'requested_subtitles' => [
                        ...array_map(static fn(string $filepath): array => ['filepath' => $filepath], $this->captionPaths),
                    ],
                ], JSON_THROW_ON_ERROR));
            }

            return new HelperResult(0, "/staging/youtube-vid1.mp4\n/staging/youtube-vid1.info.json\n/staging/youtube-vid1.jpg\n");
        }
    }

    final class YtProgress implements ProgressReporter
    {
        /** @var list<float|null> */ public array $fractions = [];
        /** @var list<string> */ public array $discovered = [];
        public function report(string $stage, ?float $fraction = null): void
        {
            $this->fractions[] = $fraction;
        }
        public function discovered(\Stashd\PluginSdk\DiscoveredItem $item): void
        {
            $this->discovered[] = $item->id;
        }
    }

    $http = new YtHttp();
    $stage = new YtStage();
    $helper = new YtHelper();
    $progress = new YtProgress();
    $plugin = new YouTubeInput(new PluginContext(http: $http, progress: $progress, staging: $stage, helpers: $helper));
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://youtu.be/abc123')]))->id === 'video:abc123', 'short URL identity failed');
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://www.youtube.com/playlist?list=PL123')]))->id === 'playlist:PL123', 'playlist identity failed');
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://www.youtube.com/show/VLPLT4CnSLg99ng?season=1&sbp=ignored')]))->id === 'playlist:PLT4CnSLg99ng', 'show URL identity failed');
    $channel = $plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://www.youtube.com/@fixture')]));
    ytAssert($channel->id === 'UCfixture123' && $channel->title === 'Channel', 'handle resolution failed');
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://www.youtube.com/playlist?list=PL123')]))->title === 'Playlist', 'playlist title resolution failed');
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://www.youtube.com/playlist?list=PL123')]))->artworkReference === 'https://yt3.ggpht.com/channel-avatar', 'playlist channel avatar resolution failed');
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://youtu.be/abc123')]))->title === 'Video', 'video title resolution failed');
    $items = $plugin->discover('UCfixture123', \Stashd\PluginSdk\DiscoveryIntent::Refresh);
    ytAssert(count($items) === 2 && $items[0]->id === 'vid1' && $items[0]->durationSeconds === null, 'Atom refresh should remain lightweight');
    ytAssert($items[0]->description === 'Description' && $items[0]->artworkReference === 'https://i.ytimg.com/vid1.jpg', 'Atom metadata was not preserved');
    ytAssert($progress->discovered === ['vid1', 'vid2'], 'discovery items were not reported incrementally');
    $http->feedMissing = true;
    $refreshFailed = false;

    try {
        $plugin->discover('UCfixture123', \Stashd\PluginSdk\DiscoveryIntent::Refresh);
    } catch (RuntimeException) {
        $refreshFailed = true;
    }

    ytAssert($refreshFailed, 'refresh should not escalate to broad discovery');
    $http->feedMissing = false;
    $playlistItems = $plugin->discover('playlist:PL123', \Stashd\PluginSdk\DiscoveryIntent::Refresh);
    ytAssert(count($playlistItems) === 1 && $playlistItems[0]->id === 'playlist1', 'playlist feed refresh failed');
    $helper->completeExitCode = 1;
    $helper->completeStderr = 'ERROR: [youtube] blocked1: The uploader has not made this video available in your country';
    $backfill = $plugin->discover('UCfixture123', \Stashd\PluginSdk\DiscoveryIntent::Complete);
    ytAssert(count($backfill) === 2 && $backfill[0]->id === 'backfill1' && $backfill[1]->upstreamState === 'region_blocked', 'yt-dlp incomplete discovery item failed');
    ytAssert($backfill[0]->publishedAt === '2026-01-01T00:00:00+00:00' && $backfill[0]->durationSeconds === null, 'complete discovery performed full metadata enrichment');
    $helper->completeExitCode = 0;
    $helper->completeStderr = '';
    $withoutShorts = $plugin->discover('UCfixture123', \Stashd\PluginSdk\DiscoveryIntent::Complete, [new InputOption('include_shorts', OptionValue::boolean(false))]);
    ytAssert(count($withoutShorts) === 1 && $withoutShorts[0]->id === 'vid2', 'short videos were not filtered from complete discovery');
    ytAssert($helper->metadataRequests === [], 'complete discovery performed full metadata enrichment');
    $withShorts = $plugin->discover('UCfixture123', \Stashd\PluginSdk\DiscoveryIntent::Complete, [new InputOption('include_shorts', OptionValue::boolean(true))]);
    ytAssert(count($withShorts) === 2 && $withShorts[0]->id === 'vid1', 'short videos were not restored when enabled');
    $acquired = $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video));
    ytAssert(count($acquired->artifacts) === 3, 'helper artifacts were not classified');
    ytAssert($acquired->artifacts[0]->role === 'primary' && in_array('--format', $helper->args, true), 'video acquisition strategy failed');
    $ffmpegLocation = array_search('--ffmpeg-location', $helper->args, true);
    ytAssert($ffmpegLocation !== false && ($helper->args[$ffmpegLocation + 1] ?? null) === '/plugin/stashd-plugin/helpers', 'bundled ffmpeg path was not configured');
    ytAssert(in_array('--write-subs', $helper->args, true) && ! in_array('--write-auto-subs', $helper->args, true), 'creator captions were not enabled by default');
    ytAssert(in_array(0.35, $progress->fractions, true), 'yt-dlp progress was not translated');
    $automatic = $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video, [new InputOption('include_auto_captions', OptionValue::boolean(true))]));
    ytAssert(in_array('--write-subs', $helper->args, true) && in_array('--write-auto-subs', $helper->args, true), 'automatic captions opt-in was not passed to yt-dlp');
    ytAssert(count(array_filter($automatic->artifacts, static fn(StagedArtifact $artifact): bool => $artifact->role === 'captions' && $artifact->mediaType === 'text/vtt')) === 1, 'automatic caption acquisition did not return a staged VTT artifact');
    $captionOnly = $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video, [new InputOption('include_captions', OptionValue::boolean(true))], [ArtifactRole::Captions]));
    ytAssert(in_array('--skip-download', $helper->args, true) && in_array('--write-subs', $helper->args, true), 'role-scoped caption acquisition was not passed to yt-dlp');
    ytAssert(count($captionOnly->artifacts) === 1 && $captionOnly->artifacts[0]->role === 'captions' && $captionOnly->artifacts[0]->mediaType === 'text/vtt' && str_ends_with($captionOnly->artifacts[0]->reference, '.vtt'), 'caption-only acquisition did not return a staged VTT artifact');
    ytAssert($captionOnly->artifacts[0]->language === 'en', 'caption language metadata was not preserved');
    $helper->captionPaths = ['en' => 'youtube-vid1.en.vtt', 'fr' => 'youtube-vid1.fr.vtt'];
    $multilingual = $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video, [new InputOption('include_captions', OptionValue::boolean(true))], [ArtifactRole::Captions]));
    ytAssert(array_map(static fn(StagedArtifact $artifact): ?string => $artifact->language, $multilingual->artifacts) === ['en', 'fr'], 'caption language associations were not preserved');
    $helper->captionPath = '../youtube-vid1.en.vtt';
    $helper->captionPaths = ['en' => $helper->captionPath];
    $unsafe = $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video, [new InputOption('include_captions', OptionValue::boolean(true))], [ArtifactRole::Captions]));
    ytAssert($unsafe->artifacts === [] && count($unsafe->unavailable) === 1 && $unsafe->unavailable[0]->role === ArtifactRole::Captions, 'caption path traversal was accepted');
    $helper->captionMissing = true;
    $missing = $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video, [new InputOption('include_captions', OptionValue::boolean(true))], [ArtifactRole::Captions]));
    ytAssert($missing->artifacts === [] && count($missing->unavailable) === 1 && $missing->unavailable[0]->role === ArtifactRole::Captions && $missing->unavailable[0]->permanent, 'missing requested captions did not remain unavailable');
    expect(true)->toBeTrue();
});
