<?php

namespace TypechoPlugin\PandaBangumi;

use Utils\Helper;

final class PluginConfig
{
    private const DEFAULT_API_BASE = 'https://api.bgm.tv';

    /** @var callable */
    private $optionsProvider;

    public function __construct(?callable $optionsProvider = null)
    {
        $this->optionsProvider = $optionsProvider ?? static function (): object {
            return Helper::options()->plugin('PandaBangumi');
        };
    }

    private function options(): object
    {
        try {
            $options = ($this->optionsProvider)();
            return is_object($options) ? $options : (object)array();
        } catch (\Throwable $e) {
            return (object)array();
        }
    }

    public function apiBase(): string
    {
        $options = $this->options();
        $apiBase = isset($options->ApiBase) ? trim((string)$options->ApiBase) : '';
        if ($apiBase === '') {
            return self::DEFAULT_API_BASE;
        }

        $parts = parse_url($apiBase);
        if (
            !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('/[\x00-\x1F\x7F]/', $apiBase)
        ) {
            return self::DEFAULT_API_BASE;
        }

        $origin = 'https://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
        return $origin . rtrim((string)($parts['path'] ?? ''), '/');
    }

    public function buildApiUrl(string $path): string
    {
        return $this->apiBase() . '/' . ltrim($path, '/');
    }

    public function userAgent(): string
    {
        $version = defined('PandaBangumi_Plugin_VERSION')
            ? (string)constant('PandaBangumi_Plugin_VERSION')
            : 'dev';

        return 'mikusa/PandaBangumi/' . $version
            . ' (https://github.com/mikusaa/Typecho-Plugin-PandaBangumi)';
    }

    public function int(string $name, int $default, int $min, int $max): int
    {
        $options = $this->options();
        $value = isset($options->{$name}) ? (int)$options->{$name} : $default;
        return max($min, min($value, $max));
    }

    public static function normalizeRefreshInterval(mixed $value): int
    {
        return max(300, is_scalar($value) ? (int)$value : 0);
    }

    public function imageMode(): string
    {
        $options = $this->options();
        $mode = strtolower(trim((string)($options->ImageMode ?? '')));
        return in_array($mode, ['direct', 'cache'], true) ? $mode : 'direct';
    }

    public function cacheImages(): bool
    {
        return $this->imageMode() === 'cache';
    }
}
