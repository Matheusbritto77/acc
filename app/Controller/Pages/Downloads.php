<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Pages;

use \App\Utils\View;
use App\Model\Entity\ServerConfig as EntityServerConfig;

class Downloads extends Base{
    private const DEFAULT_WINDOWS_DOWNLOAD = 'https://github.com/Matheusbritto77/client/releases/download/v4.2.2/OTClient-windows-x64.zip';
    private const DEFAULT_LINUX_DOWNLOAD = 'https://github.com/Matheusbritto77/client/releases/tag/v4.2.2';
    private const DEFAULT_MACOS_DOWNLOAD = 'https://github.com/Matheusbritto77/client/releases/download/v4.2.2/OTClient-macos-prod.dmg';

    public static function viewDownloads()
    {
        $dbServer = EntityServerConfig::getInfoWebsite()->fetchObject();
        $packages = self::getPublishedPackages();
        $content = View::render('pages/downloads', [
            'windows_download_link' => self::resolveWindowsDownloadLink($dbServer->downloads ?? '', $packages),
            'linux_download_link' => self::resolvePackageUrl($packages, 'linux', self::DEFAULT_LINUX_DOWNLOAD),
            'macos_download_link' => self::resolvePackageUrl($packages, 'macos', self::DEFAULT_MACOS_DOWNLOAD),
        ]);
        return parent::getBase('Downloads', $content, 'downloads');
    }

    private static function getPublishedPackages(): array
    {
        $manifestPath = dirname(__DIR__, 3) . '/resources/client-updater/stable/manifest.json';
        if (!is_file($manifestPath)) {
            return [];
        }

        $contents = file_get_contents($manifestPath);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $manifest = json_decode($contents, true);
        if (!is_array($manifest)) {
            return [];
        }

        return is_array($manifest['packages'] ?? null) ? $manifest['packages'] : [];
    }

    private static function resolveWindowsDownloadLink(string $dbLink, array $packages): string
    {
        $packageUrl = self::resolvePackageUrl($packages, 'windows');
        if ($packageUrl !== null) {
            return $packageUrl;
        }

        return $dbLink !== '' ? $dbLink : self::DEFAULT_WINDOWS_DOWNLOAD;
    }

    private static function resolvePackageUrl(array $packages, string $packageKey, ?string $fallback = null): ?string
    {
        $package = $packages[$packageKey] ?? null;
        if (is_array($package) && is_string($package['url'] ?? null) && $package['url'] !== '') {
            return $package['url'];
        }

        return $fallback;
    }

}
