<?php
declare(strict_types=1);

namespace App\Controller\Pages;

use App\Model\Entity\News as EntityNews;

class Seo
{
    private static function addUrl(array &$items, string $loc, ?string $lastmod = null): void
    {
        $items[] = [
            'loc' => rtrim(URL, '/') . '/' . ltrim($loc, '/'),
            'lastmod' => $lastmod,
        ];
    }

    private static function buildUrlset(array $items): string
    {
        usort($items, static function (array $left, array $right): int {
            return strcmp($left['loc'], $right['loc']);
        });

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($items as $item) {
            $writer->startElement('url');
            $writer->writeElement('loc', $item['loc']);

            if (!empty($item['lastmod'])) {
                $writer->writeElement('lastmod', $item['lastmod']);
            }

            $writer->endElement();
        }

        $writer->endElement();

        return $writer->outputMemory();
    }

    private static function buildSitemapIndex(array $items): string
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($items as $item) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', $item['loc']);

            if (!empty($item['lastmod'])) {
                $writer->writeElement('lastmod', $item['lastmod']);
            }

            $writer->endElement();
        }

        $writer->endElement();

        return $writer->outputMemory();
    }

    private static function latestNewsLastmod(): ?string
    {
        $newsItems = EntityNews::getNews(['hidden' => 0], 'date DESC', '1', 'id, date');
        $news = $newsItems->fetchObject();

        if ($news === false || empty($news->date)) {
            return null;
        }

        return date('c', strtotime((string) $news->date));
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

    public static function sitemapIndex(): string
    {
        $items = [];

        self::addUrl($items, '/sitemap-pages.xml');
        self::addUrl($items, '/sitemap-news.xml', self::latestNewsLastmod());

        return self::buildSitemapIndex($items);
    }

    public static function sitemapPages(): string
    {
        $items = [];

        self::addUrl($items, '/');
        self::addUrl($items, '/latestnews');
        self::addUrl($items, '/newsarchive');
        self::addUrl($items, '/eventcalendar');
        self::addUrl($items, '/downloads');
        self::addUrl($items, '/library/creatures');
        self::addUrl($items, '/library/boostablebosses');
        self::addUrl($items, '/library/achievements');
        self::addUrl($items, '/library/experiencetable');
        self::addUrl($items, '/community/characters');
        self::addUrl($items, '/community/worlds');
        self::addUrl($items, '/community/highscores');
        self::addUrl($items, '/community/lastdeaths');
        self::addUrl($items, '/community/houses');
        self::addUrl($items, '/charactertrade');
        self::addUrl($items, '/community/guilds');
        self::addUrl($items, '/community/polls');
        self::addUrl($items, '/guildwars/active');
        self::addUrl($items, '/guildwars/pending');
        self::addUrl($items, '/guildwars/surrender');
        self::addUrl($items, '/guildwars/ended');
        self::addUrl($items, '/support/rules');
        self::addUrl($items, '/support/team');
        self::addUrl($items, '/premiumfeatures');

        return self::buildUrlset($items);
    }

    public static function sitemapNews(): string
    {
        $items = [];

        $newsItems = EntityNews::getNews(['hidden' => 0], 'date DESC', '300', 'id, date');
        while ($news = $newsItems->fetchObject()) {
            $lastmod = null;
            if (!empty($news->date)) {
                $lastmod = date('c', strtotime((string) $news->date));
            }

            self::addUrl($items, '/newsarchive/' . $news->id . '/view', $lastmod);
        }

        return self::buildUrlset($items);
    }
}
