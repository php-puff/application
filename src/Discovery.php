<?php
declare(strict_types=1);
/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/application
 * https://github.com/php-puff/application/issues
 * Copyright (c) Puff
 */

namespace Puff\Application;

final class Discovery
{    
    /** @var list<class-string>|null */
    private static ?array $apps = null;

    /** @var list<class-string>|null */
    private static ?array $providers = null;

    /** @return list<class-string> */
    public static function apps(): array
    {
        if (self::$apps !== null) {
            return self::$apps;
        }

        $apps = [];
        foreach (self::packages() as $package) {
            foreach ((array) ($package['extra']['puff']['apps'] ?? []) as $app) {
                if (\is_string($app) && $app !== '') {
                    $apps[] = $app;
                }
            }
        }
        return self::$apps = \array_values(\array_unique($apps));
    }

    /** @return list<class-string> */
    public static function providers(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $providers = [];
        foreach (self::packages() as $package) {
            foreach ((array) ($package['extra']['puff']['providers'] ?? []) as $provider) {
                if (\is_string($provider) && $provider !== '') {
                    $providers[] = $provider;
                }
            }
        }
        return self::$providers = \array_values(\array_unique($providers));
    }

    private static function packages(): array
    {
        $reflection = new \ReflectionClass(\Composer\InstalledVersions::class);
        $composerDirectory = \dirname((string) $reflection->getFileName());
        $packages = self::read($composerDirectory . '/installed.json');
        $root = self::read(\dirname($composerDirectory, 2) . '/composer.json');
        if (isset($packages['packages']) && \is_array($packages['packages'])) {
            $packages = $packages['packages'];
        }
        if (isset($root['name'])) {
            $packages[] = $root;
        }
        return $packages;
    }

    private static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
