<?php
/**
 * Client updater API
 *
 * @package   astarOT
 * @author    britto dev <contato@lucasgiovanni.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Api;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ClientUpdater extends Api
{
    private const DEFAULT_CHANNEL = 'stable';

    private const BINARY_CANDIDATES = [
        'WIN32-WGL' => ['OTClient.exe', 'otclient_x64.exe', 'otclient.exe'],
        'WIN32-EGL' => ['OTClient.exe', 'otclient_x64.exe', 'otclient.exe'],
        'WIN32-WGL-GCC' => ['OTClient.exe', 'otclient_x64.exe', 'otclient.exe'],
        'WIN32-EGL-GCC' => ['OTClient.exe', 'otclient_x64.exe', 'otclient.exe'],
        'X11-GLX' => ['otclient_linux', 'otclient'],
        'X11-EGL' => ['otclient_linux', 'otclient'],
        'ANDROID-EGL' => [],
        'ANDROID64-EGL' => [],
    ];

    public static function getManifest($request): array
    {
        $postVars = $request->getPostVars();
        $args = is_array($postVars['args'] ?? null) ? $postVars['args'] : [];
        $platform = is_string($postVars['platform'] ?? null) ? $postVars['platform'] : '';
        $channel = self::sanitizeChannel($args['channel'] ?? self::DEFAULT_CHANNEL);
        $manifest = self::loadManifest($channel);

        $response = [
            'url' => is_string($manifest['url'] ?? null) ? $manifest['url'] : self::buildFilesUrl($channel),
            'files' => is_array($manifest['files'] ?? null) ? $manifest['files'] : [],
            'keepFiles' => !empty($manifest['keepFiles']),
        ];

        $binary = self::resolveBinary($manifest, $platform);
        if ($binary !== null) {
            $response['binary'] = $binary;
        }

        return $response;
    }

    private static function loadManifest(string $channel): array
    {
        $channelRoot = self::getChannelRoot($channel);
        $manifestPath = $channelRoot . '/manifest.json';
        $filesDir = $channelRoot . '/files';

        if (is_file($manifestPath)) {
            $contents = file_get_contents($manifestPath);
            if (is_string($contents) && $contents !== '') {
                $manifest = json_decode($contents, true);
                if (is_array($manifest)) {
                    return self::normalizeManifest($channel, $manifest);
                }
            }
        }

        if (is_dir($filesDir)) {
            return self::buildManifestFromFiles($channel, $filesDir);
        }

        return [
            'channel' => $channel,
            'url' => self::buildFilesUrl($channel),
            'files' => [],
            'binaries' => [],
            'keepFiles' => false,
        ];
    }

    private static function normalizeManifest(string $channel, array $manifest): array
    {
        $manifest['channel'] = $channel;
        $manifest['url'] = is_string($manifest['url'] ?? null) && $manifest['url'] !== ''
            ? rtrim($manifest['url'], '/')
            : self::buildFilesUrl($channel);
        $manifest['files'] = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $manifest['binaries'] = is_array($manifest['binaries'] ?? null) ? $manifest['binaries'] : [];
        $manifest['keepFiles'] = !empty($manifest['keepFiles']);

        return $manifest;
    }

    private static function buildManifestFromFiles(string $channel, string $filesDir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($filesDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $pathName = $file->getPathname();
            $relativePath = substr($pathName, strlen($filesDir));
            if (!is_string($relativePath) || $relativePath === '') {
                continue;
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            if (preg_match('/(^|\/)\./', $relativePath) === 1) {
                continue;
            }

            $checksum = self::checksum($pathName);
            if ($checksum === '') {
                continue;
            }

            $files[$relativePath] = $checksum;
        }

        ksort($files);

        return [
            'channel' => $channel,
            'url' => self::buildFilesUrl($channel),
            'files' => $files,
            'binaries' => self::buildBinariesFromFiles($files),
            'keepFiles' => false,
        ];
    }

    private static function buildBinariesFromFiles(array $files): array
    {
        $binaries = [];

        foreach (self::BINARY_CANDIDATES as $platform => $candidates) {
            foreach ($candidates as $candidate) {
                $file = '/' . ltrim($candidate, '/');
                if (!isset($files[$file])) {
                    continue;
                }

                $binaries[$platform] = [
                    'file' => $file,
                    'checksum' => $files[$file],
                ];
                break;
            }
        }

        return $binaries;
    }

    private static function resolveBinary(array $manifest, string $platform): ?array
    {
        if ($platform === '') {
            return null;
        }

        $binary = $manifest['binaries'][$platform] ?? null;
        if (!is_array($binary)) {
            return null;
        }

        $file = is_string($binary['file'] ?? null) ? $binary['file'] : '';
        $checksum = is_string($binary['checksum'] ?? null) ? $binary['checksum'] : '';
        if ($file === '' || $checksum === '') {
            return null;
        }

        return [
            'file' => $file,
            'checksum' => $checksum,
        ];
    }

    private static function sanitizeChannel($channel): string
    {
        $channel = is_string($channel) ? trim($channel) : '';
        if ($channel === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $channel) !== 1) {
            return self::DEFAULT_CHANNEL;
        }

        return $channel;
    }

    private static function buildFilesUrl(string $channel): string
    {
        return URL . '/resources/client-updater/' . rawurlencode($channel) . '/files';
    }

    private static function getChannelRoot(string $channel): string
    {
        return dirname(__DIR__, 3) . '/resources/client-updater/' . $channel;
    }

    private static function checksum(string $path): string
    {
        $checksum = hash_file('crc32b', $path);
        if (!is_string($checksum) || $checksum === '') {
            return '';
        }

        $checksum = ltrim(strtolower($checksum), '0');
        return $checksum === '' ? '0' : $checksum;
    }
}
