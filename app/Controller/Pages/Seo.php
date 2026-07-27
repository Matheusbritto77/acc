<?php
declare(strict_types=1);

namespace App\Controller\Pages;

use App\Model\Entity\News as EntityNews;

class Seo
{
    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function addUrl(array &$items, string $loc, string $changefreq = 'weekly', string $priority = '0.7', ?string $lastmod = null): void
    {
        $items[] = [
            'loc' => rtrim(URL, '/') . '/' . ltrim($loc, '/'),
            'changefreq' => $changefreq,
            'priority' => $priority,
            'lastmod' => $lastmod ?? date('c'),
        ];
    }

    public static function robots(): string
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /admin/',
            'Disallow: /account',
            'Disallow: /account/',
            'Disallow: /payment',
            'Disallow: /payment/',
            'Disallow: /signature',
            'Disallow: /signature/',
            'Sitemap: ' . rtrim(URL, '/') . '/sitemap.xml',
        ];

        return implode("\n", $lines) . "\n";
    }

    public static function sitemap(): string
    {
        $items = [];

        self::addUrl($items, '/', 'daily', '1.0');
        self::addUrl($items, '/latestnews', 'hourly', '1.0');
        self::addUrl($items, '/newsarchive', 'daily', '0.9');
        self::addUrl($items, '/eventcalendar', 'daily', '0.8');
        self::addUrl($items, '/downloads', 'weekly', '0.9');
        self::addUrl($items, '/library/creatures', 'weekly', '0.6');
        self::addUrl($items, '/library/boostablebosses', 'daily', '0.6');
        self::addUrl($items, '/library/achievements', 'weekly', '0.6');
        self::addUrl($items, '/library/experiencetable', 'monthly', '0.6');
        self::addUrl($items, '/community/characters', 'daily', '0.8');
        self::addUrl($items, '/community/worlds', 'daily', '0.8');
        self::addUrl($items, '/community/highscores', 'hourly', '0.9');
        self::addUrl($items, '/community/lastdeaths', 'hourly', '0.8');
        self::addUrl($items, '/community/houses', 'daily', '0.8');
        self::addUrl($items, '/community/guilds', 'daily', '0.7');
        self::addUrl($items, '/community/polls', 'daily', '0.5');
        self::addUrl($items, '/guildwars/active', 'hourly', '0.6');
        self::addUrl($items, '/guildwars/pending', 'hourly', '0.5');
        self::addUrl($items, '/guildwars/surrender', 'hourly', '0.5');
        self::addUrl($items, '/guildwars/ended', 'hourly', '0.5');
        self::addUrl($items, '/support/rules', 'monthly', '0.5');
        self::addUrl($items, '/support/team', 'monthly', '0.5');
        self::addUrl($items, '/premiumfeatures', 'monthly', '0.5');

        $newsItems = EntityNews::getNews(['hidden' => 0], 'date DESC', '300', 'id, date');
        while ($news = $newsItems->fetchObject()) {
            $lastmod = null;
            if (!empty($news->date)) {
                $lastmod = date('c', strtotime((string) $news->date));
            }

            self::addUrl($items, '/newsarchive/' . $news->id . '/view', 'monthly', '0.7', $lastmod);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($items as $item) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . self::esc($item['loc']) . "</loc>\n";
            $xml .= '    <lastmod>' . self::esc($item['lastmod']) . "</lastmod>\n";
            $xml .= '    <changefreq>' . self::esc($item['changefreq']) . "</changefreq>\n";
            $xml .= '    <priority>' . self::esc($item['priority']) . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>\n";

        return $xml;
    }
}
