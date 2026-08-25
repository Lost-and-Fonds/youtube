<?php

declare(strict_types=1);

use Stashd\PluginSdk\AcquisitionOptions;
use Stashd\PluginSdk\HelperResult;
use Stashd\PluginSdk\HelperRunner;
use Stashd\PluginSdk\HttpClient;
use Stashd\PluginSdk\HttpResponse;
use Stashd\PluginSdk\InputOption;
use Stashd\PluginSdk\MediaKind;
use Stashd\PluginSdk\OptionValue;
use Stashd\PluginSdk\PluginContext;
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
        public function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $credential = null): HttpResponse
        {
            if (str_contains($url, 'feeds/videos.xml')) {
                return new HttpResponse(200, inlineBody: '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015"><entry><title>One</title><published>2026-01-01T00:00:00Z</published><yt:videoId>vid1</yt:videoId></entry><entry><title>Two</title><published>2026-01-02T00:00:00Z</published><yt:videoId>vid2</yt:videoId></entry></feed>');
            }

            if (str_contains($url, 'oembed')) {
                return new HttpResponse(200, inlineBody: '{"title":"Video","thumbnail_url":"https://i.ytimg.com/x.jpg"}');
            }

            if (str_contains($url, 'playlist')) {
                return new HttpResponse(200, inlineBody: '<meta property="og:title" content="Playlist">');
            }

            if (str_contains($url, 'channel/') || str_contains($url, '@fixture')) {
                return new HttpResponse(200, inlineBody: '<meta property="og:title" content="Channel"><script>"channelId":"UCfixture123"</script>');
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
        public function run(string $name, array $arguments = []): HelperResult
        {
            ytAssert($name === 'yt-dlp', 'wrong helper');
            $this->args = $arguments;

            return new HelperResult(0, "/staging/youtube-vid1.mp4\n/staging/youtube-vid1.info.json\n/staging/youtube-vid1.jpg\n");
        }
    }

    $http = new YtHttp();
    $stage = new YtStage();
    $helper = new YtHelper();
    $plugin = new YouTubeInput(new PluginContext(http: $http, staging: $stage, helpers: $helper));
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://youtu.be/abc123')]))->id === 'video:abc123', 'short URL identity failed');
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://www.youtube.com/playlist?list=PL123')]))->id === 'playlist:PL123', 'playlist identity failed');
    $channel = $plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://www.youtube.com/@fixture')]));
    ytAssert($channel->id === 'UCfixture123' && $channel->title === 'Channel', 'handle resolution failed');
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://www.youtube.com/playlist?list=PL123')]))->title === 'Playlist', 'playlist title resolution failed');
    ytAssert($plugin->resolve(new SourceDescriptor(['url' => OptionValue::text('https://youtu.be/abc123')]))->title === 'Video', 'video title resolution failed');
    $items = $plugin->discover('UCfixture123', \Stashd\PluginSdk\DiscoveryIntent::Refresh);
    ytAssert(count($items) === 2 && $items[0]->id === 'vid1', 'Atom discovery failed');
    $acquired = $plugin->acquire($items[0], new AcquisitionOptions(MediaKind::Video));
    ytAssert(count($acquired->artifacts) === 3, 'helper artifacts were not classified');
    ytAssert($acquired->artifacts[0]->role === 'primary' && in_array('--format', $helper->args, true), 'video acquisition strategy failed');
    expect(true)->toBeTrue();
});
