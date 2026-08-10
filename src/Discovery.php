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
                if (\is_string($app) && $app !== '' && \class_exists($app)) {
                    $apps[] = $app;
                } elseif (\is_string($app) && $app !== '') {
                    throw new \UnexpectedValueException("Discovered Puff application [{$app}] does not exist.");
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
                if (\is_string($provider) && $provider !== '' && \class_exists($provider)) {
                    $providers[] = $provider;
                } elseif (\is_string($provider) && $provider !== '') {
                    throw new \UnexpectedValueException("Discovered Puff provider [{$provider}] does not exist.");
                }
            }
        }
        return self::$providers = \array_values(\array_unique($providers));
    }

    public static function reset(): void
    {
        self::$apps = null;
        self::$providers = null;
    }

    public static function version(string $package): ?string
    {
        foreach (self::packages() as $item) {
            if (($item['name'] ?? null) !== $package) {
                continue;
            }
            $version = $item['pretty_version'] ?? $item['version'] ?? null;
            return \is_string($version) ? $version : null;
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    private static function packages(): array
    {
        $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $composerDirectory = \dirname((string) $reflection->getFileName());
        $packages = self::read($composerDirectory . '/installed.json');
        $root = self::read(\dirname($composerDirectory, 2) . '/composer.json');
        if (isset($packages['packages']) && \is_array($packages['packages'])) {
            $packages = $packages['packages'];
        }
        if (isset($root['name'])) {
            $packages[] = $root;
        }
        return \array_values(\array_filter($packages, \is_array(...)));
    }

    /** @return array<string, mixed> */
    private static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }
        $decoded = \json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        return \is_array($decoded) ? $decoded : [];
    }
}
