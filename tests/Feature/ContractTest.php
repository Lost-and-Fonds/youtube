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

$sdkRoot = dirname(__DIR__, 4) . '/stashd-php-sdk/src/';
$sdkRoot = is_dir($sdkRoot) ? $sdkRoot : dirname(__DIR__, 2) . '/vendor/stashd/php-sdk/src/';

spl_autoload_register(static function (string $class) use ($sdkRoot): void {
    foreach (['YouTube\\' => dirname(__DIR__, 2) . '/src/', 'Stashd\\PluginSdk\\' => $sdkRoot] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        }
    }
}, prepend: true);

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

            if (str_contains($url, 'googleapis.com/youtube/v3/playlistItems')) {
                if (str_contains($url, 'playlistId=PLBuckets')) {
                    return new HttpResponse(200, inlineBody: json_encode(['items' => array_map(static fn(string $id): array => ['snippet' => ['resourceId' => ['videoId' => $id], 'title' => $id]], ['bucket-sd', 'bucket-hd', 'bucket-fhd', 'bucket-qhd', 'bucket-uhd'])], JSON_THROW_ON_ERROR));
                }

                return new HttpResponse(200, inlineBody: '{"items":[{"id":"playlist1","snippet":{"resourceId":{"videoId":"vid1"},"title":"One","publishedAt":"2026-01-01T00:00:00Z"}},{"id":"playlist2","snippet":{"resourceId":{"videoId":"vid2"},"title":"Two","publishedAt":"2026-01-02T00:00:00Z"}}],"nextPageToken":null}');
            }

            if (str_contains($url, 'googleapis.com/youtube/v3/videos')) {
                if (str_contains($url, 'bucket-sd')) {
                    return new HttpResponse(200, inlineBody: '{"items":[{"id":"bucket-sd","snippet":{"title":"SD"},"contentDetails":{"duration":"PT181S","definition":"sd"}},{"id":"bucket-hd","snippet":{"title":"HD"},"contentDetails":{"duration":"PT181S","definition":"hd"}},{"id":"bucket-fhd","snippet":{"title":"FHD","thumbnails":{"fhd":{"url":"https://i.ytimg.com/fhd.jpg","width":1920}}},"contentDetails":{"duration":"PT181S","definition":"hd"}},{"id":"bucket-qhd","snippet":{"title":"QHD","thumbnails":{"qhd":{"url":"https://i.ytimg.com/qhd.jpg","width":2560}}},"contentDetails":{"duration":"PT181S","definition":"hd"}},{"id":"bucket-uhd","snippet":{"title":"UHD","thumbnails":{"uhd":{"url":"https://i.ytimg.com/uhd.jpg","width":3840}}},"contentDetails":{"duration":"PT181S","definition":"hd"}}]}');
                }

                return new HttpResponse(200, inlineBody: '{"items":[{"id":"vid1","snippet":{"title":"One","publishedAt":"2026-01-01T00:00:00Z"},"contentDetails":{"duration":"PT45S","definition":"sd"}},{"id":"vid2","snippet":{"title":"Two","publishedAt":"2026-01-02T00:00:00Z","thumbnails":{"fhd":{"url":"https://i.ytimg.com/fhd.jpg","width":1920,"height":1080}}},"contentDetails":{"duration":"PT3M1S","definition":"hd"}}]}');
            }

            if (str_contains($url, 'playlist')) {
                return new HttpResponse(200, inlineBody: '<meta property="og:title" content="Playlist"><script>"avatar":{"avatarViewModel":{"image":{"sources":[{"url":"https://yt3.ggpht.com/channel-avatar"}]}}}</script>');
            }

            if (str_contains($url, 'channel/') || str_contains($url, '@fixture')) {
                return new HttpResponse(200, inlineBody: '<meta property="og:title" content="Channel"><script>"channelId":"UCfixture123"</script>');
            }

            return new HttpResponse(200, inlineBody: '{"items":[],"nextPageToken":null}');
        }
    }

    final class YtStage implements StagingArea
    {
        public int $sizeBytes = 10;
        /** @var list<string> */ public array $paths = [];
        public function write(string $relativePath, string $content, ?string $mediaType = null): StagedArtifact
        {
            return new StagedArtifact($relativePath, $mediaType ?? 'application/octet-stream', strlen($content));
        }
        public function stage(string $relativePath, ?string $mediaType = null): StagedArtifact
        {
            $this->paths[] = $relativePath;

            return new StagedArtifact($relativePath, $mediaType ?? 'application/octet-stream', $this->sizeBytes);
        }
    }

    final class YtHelper implements HelperRunner
    {
        /** @var list<string> */ public array $args = [];
        public int $completeExitCode = 0;
        public int $invocations = 0;
        public string $completeStderr = '';
        public bool $captionMissing = false;
        public string $progressLine = "download:progress=35.0%;total=NA;estimate=98765\n";
        public string $captionPath = 'youtube-vid1.en.vtt';
        /** @var list<list<string>> */
        public array $metadataRequests = [];
        /** @var array<string, string> */
        public array $captionPaths = ['en' => 'youtube-vid1.en.vtt'];
        public function run(string $name, array $arguments = [], ?callable $onOutput = null): HelperResult
        {
            $this->invocations++;
            ytAssert($name === 'yt-dlp', 'wrong helper');
            $this->args = $arguments;

            if (in_array('--dump-json', $arguments, true)) {
                $this->metadataRequests[] = $arguments;

                return new HelperResult(0, json_encode(['id' => 'backfill1', 'title' => 'Backfill item', 'upload_date' => '20260101', 'duration' => 321, 'filesize_approx' => 1234], JSON_THROW_ON_ERROR));
            }

            if ($onOutput !== null && in_array('--progress-template', $arguments, true)) {
                $onOutput('err', $this->progressLine);
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

            return new HelperResult($this->completeExitCode, "/staging/youtube-vid1.mp4\n/staging/youtube-vid1.info.json\n/staging/youtube-vid1.jpg\n", $this->completeStderr);
        }
    }

    final class YtProgress implements ProgressReporter
    {
        /** @var list<float|null> */ public array $fractions = [];
        /** @var list<array{string, ?float, ?int, bool}> */ public array $updates = [];
        /** @var list<string> */ public array $discovered = [];
        public function report(string $stage, ?float $fraction = null, ?int $sizeBytes = null, bool $sizeEstimated = false): void
        {
            $this->fractions[] = $fraction;
            $this->updates[] = [$stage, $fraction, $sizeBytes, $sizeEstimated];
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
    $dataPath = sys_get_temp_dir() . '/stashd-youtube-estimator-' . bin2hex(random_bytes(8));
    mkdir($dataPath, 0700, true);
    $plugin = new YouTubeInput(new PluginContext(http: $http, progress: $progress, staging: $stage, helpers: $helper, pluginDataPath: $dataPath));
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
    ytAssert($items[0]->sizeBytes === null && ! $items[0]->sizeEstimated, 'lightweight RSS discovery invented a size estimate');
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
    $helper->metadataRequests = [];
    $helper->invocations = 0;
    $apiItems = $plugin->discover('playlist:PL123', \Stashd\PluginSdk\DiscoveryIntent::Complete, [new InputOption('__stashd_complete_credential_available', OptionValue::boolean(true))]);
    ytAssert(count($apiItems) === 1 && $apiItems[0]->id === 'vid2', 'API discovery did not filter short videos before metadata enrichment');
    ytAssert($apiItems[0]->sizeBytes !== null && $apiItems[0]->sizeEstimated, 'API evidence did not produce a bootstrap size estimate');
    ytAssert($helper->invocations === 0 && $helper->metadataRequests === [], 'API discovery invoked yt-dlp to estimate size');
    $bucketDataPath = sys_get_temp_dir() . '/stashd-youtube-buckets-' . bin2hex(random_bytes(8));
    mkdir($bucketDataPath, 0700, true);
    $bucketStage = new YtStage();
    $bucketPlugin = new YouTubeInput(new PluginContext(http: new YtHttp(), progress: new YtProgress(), staging: $bucketStage, helpers: new YtHelper(), pluginDataPath: $bucketDataPath));
    $bucketItems = $bucketPlugin->discover('playlist:PLBuckets', \Stashd\PluginSdk\DiscoveryIntent::Complete, [new InputOption('__stashd_complete_credential_available', OptionValue::boolean(true)), new InputOption('include_shorts', OptionValue::boolean(true))]);
    ytAssert(array_map(static fn(\Stashd\PluginSdk\DiscoveredItem $item): ?int => $item->sizeBytes, $bucketItems) === [15837500, 27150000, 49775000, 79187500, 113125000], 'enhanced thumbnail candidates did not select the expected bootstrap buckets');
    $bucketPlugin->acquire($bucketItems[0], new AcquisitionOptions(MediaKind::Video));
    $bucketPlugin->acquire($bucketItems[0], new AcquisitionOptions(MediaKind::Video));
    $bucketStage->sizeBytes = 1_000_000_000;
    $bucketPlugin->acquire($bucketItems[0], new AcquisitionOptions(MediaKind::Video));
    $bucketLearned = new YouTubeInput(new PluginContext(http: new YtHttp(), progress: new YtProgress(), staging: new YtStage(), helpers: new YtHelper(), pluginDataPath: $bucketDataPath));
    $learnedBuckets = $bucketLearned->discover('playlist:PLBuckets', \Stashd\PluginSdk\DiscoveryIntent::Complete, [new InputOption('__stashd_complete_credential_available', OptionValue::boolean(true)), new InputOption('include_shorts', OptionValue::boolean(true))]);
    $learnedBucketSizes = array_map(static fn(\Stashd\PluginSdk\DiscoveredItem $item): ?int => $item->sizeBytes, $learnedBuckets);
    ytAssert($learnedBucketSizes === [10, 27150000, 49775000, 79187500, 113125000], 'learned median or resolution-bucket isolation changed: ' . json_encode($learnedBucketSizes));
    $isolationDataPath = sys_get_temp_dir() . '/stashd-youtube-isolation-' . bin2hex(random_bytes(8));
    mkdir($isolationDataPath, 0700, true);
    $isolationHelper = new YtHelper();
    $isolationPlugin = new YouTubeInput(new PluginContext(http: new YtHttp(), progress: new YtProgress(), staging: new YtStage(), helpers: $isolationHelper, pluginDataPath: $isolationDataPath));
    $isolationItems = $isolationPlugin->discover('playlist:PLBuckets', \Stashd\PluginSdk\DiscoveryIntent::Complete, [new InputOption('__stashd_complete_credential_available', OptionValue::boolean(true)), new InputOption('include_shorts', OptionValue::boolean(true))]);
    $isolationBaseline = $isolationItems[0]->sizeBytes;
    $pdo = new PDO('sqlite:' . $isolationDataPath . '/estimator.sqlite');
    $pdo->prepare('INSERT INTO observations VALUES (?, ?, ?, ?, ?)')->execute(['video-best-v0', 'sd', 181, 1_000_000_000, time()]);
    $isolationHelper->completeExitCode = 1;

    try {
        $isolationPlugin->acquire($isolationItems[0], new AcquisitionOptions(MediaKind::Video));
    } catch (RuntimeException) {
    }
    $isolationHelper->completeExitCode = 0;
    $isolationPlugin->acquire($isolationItems[0], new AcquisitionOptions(MediaKind::Audio));
    $isolationPlugin->acquire($isolationItems[0], new AcquisitionOptions(MediaKind::Video, [new InputOption('include_captions', OptionValue::boolean(true))], [ArtifactRole::Captions]));
    $isolationPlugin->acquire($isolationItems[0], new AcquisitionOptions(MediaKind::Video, [], [ArtifactRole::Artwork]));
    $isolationPlugin->acquire($isolationItems[0], new AcquisitionOptions(MediaKind::Video, [], [ArtifactRole::Metadata]));
    $isolationFresh = new YouTubeInput(new PluginContext(http: new YtHttp(), progress: new YtProgress(), staging: new YtStage(), helpers: new YtHelper(), pluginDataPath: $isolationDataPath));
    $isolationEstimate = $isolationFresh->discover('playlist:PLBuckets', \Stashd\PluginSdk\DiscoveryIntent::Complete, [new InputOption('__stashd_complete_credential_available', OptionValue::boolean(true)), new InputOption('include_shorts', OptionValue::boolean(true))])[0]->sizeBytes;
    ytAssert($isolationBaseline === 15837500 && $isolationEstimate === $isolationBaseline, 'failed, supplementary, audio, or incompatible-profile observations contaminated video estimates');
    $helper->metadataRequests = [];
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
    $plugin->acquire($apiItems[0], new AcquisitionOptions(MediaKind::Video));
    $freshPlugin = new YouTubeInput(new PluginContext(http: $http, progress: new YtProgress(), staging: new YtStage(), helpers: new YtHelper(), pluginDataPath: $dataPath));
    $learned = $freshPlugin->discover('playlist:PL123', \Stashd\PluginSdk\DiscoveryIntent::Complete, [new InputOption('__stashd_complete_credential_available', OptionValue::boolean(true))]);
    ytAssert($learned[0]->sizeBytes === 10 && $learned[0]->sizeEstimated, 'a fresh plugin instance did not estimate from the persisted primary acquisition');
    $acquired = $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video));
    ytAssert(count($acquired->artifacts) === 3, 'helper artifacts were not classified');
    ytAssert(in_array(['Downloading', 0.35, 98765, true], $progress->updates, true), 'yt-dlp approximate total was not reported as estimated');
    ytAssert($acquired->artifacts[0]->role === 'primary' && in_array('--format', $helper->args, true), 'video acquisition strategy failed');
    $ffmpegLocation = array_search('--ffmpeg-location', $helper->args, true);
    ytAssert($ffmpegLocation !== false && ($helper->args[$ffmpegLocation + 1] ?? null) === '/plugin/stashd-plugin/helpers', 'bundled ffmpeg path was not configured');
    ytAssert(in_array('--write-subs', $helper->args, true) && ! in_array('--write-auto-subs', $helper->args, true), 'creator captions were not enabled by default');
    ytAssert(in_array(0.35, $progress->fractions, true), 'yt-dlp progress was not translated');
    $helper->progressLine = "download:progress=35.0%;total=12345;estimate=NA\n";
    $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video));
    ytAssert(in_array(['Downloading', 0.35, 12345, false], $progress->updates, true), 'yt-dlp exact total was incorrectly marked estimated');
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
    unset($pdo);

    foreach ([$dataPath, $bucketDataPath, $isolationDataPath] as $path) {
        foreach (['/estimator.sqlite', '/estimator.sqlite-wal', '/estimator.sqlite-shm'] as $suffix) {
            $file = $path . $suffix;

            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }
    expect(true)->toBeTrue();
});
