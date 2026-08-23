<?php
declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;
return (new Config())->setRules(['@PER-CS3x0' => true, 'declare_strict_types' => true])->setFinder(Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']));
