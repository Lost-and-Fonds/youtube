<?php

declare(strict_types=1);

require_once '/sdk/bootstrap.php';
require_once __DIR__ . '/../src/YouTubeInput.php';

use Stashd\PluginSdk\Runtime\InputPluginServer;
use YouTube\YouTubeInput;

(new InputPluginServer(static fn($context): YouTubeInput => new YouTubeInput($context)))->run();
