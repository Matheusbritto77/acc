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
use App\Session\Admin\Login as SessionPlayerLogin;
use App\Model\Entity\ServerConfig as EntityServerConfig;
use App\Model\Functions\Server as FunctionsServer;
use App\Model\Functions\ThemeBox as FunctionsThemeBox;

class Base{
    private static function getCanonicalUrl(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? rtrim(URL, '/') . '/' : rtrim(URL, '/') . $path;
    }

    private static function getCurrentLang(): string
    {
        return strtolower((string) ($_COOKIE['lang'] ?? 'pt'));
    }

    private static function getSeoDescription(string $currentPage, string $title): string
    {
        $siteName = SITE_NAME;
        $map = [
            'latestnews' => sprintf('%s latest news, announcements and updates for the community.', $siteName),
            'newsarchive' => sprintf('Browse the %s news archive, articles and announcements.', $siteName),
            'eventschedule' => sprintf('Check the event calendar and scheduled events on %s.', $siteName),
            'eventcalendar' => sprintf('Check the event calendar and scheduled events on %s.', $siteName),
            'creatures' => sprintf('Browse the creature library for %s.', $siteName),
            'boostablebosses' => sprintf('See the daily boosted bosses and hunting targets on %s.', $siteName),
            'achievements' => sprintf('Explore the achievements available on %s.', $siteName),
            'experiencetable' => sprintf('Check the experience table for %s up to level 3500.', $siteName),
            'characters' => sprintf('Search and view characters on %s.', $siteName),
            'worlds' => sprintf('Review the game worlds and server status on %s.', $siteName),
            'highscores' => sprintf('View the highscores and leaderboards on %s.', $siteName),
            'lastdeaths' => sprintf('Browse the latest deaths on %s.', $siteName),
            'houses' => sprintf('Search houses and auctions on %s.', $siteName),
            'guilds' => sprintf('Discover guilds, members and guild features on %s.', $siteName),
            'polls' => sprintf('Vote in active community polls on %s.', $siteName),
            'downloads' => sprintf('Download the official client and game files for %s.', $siteName),
            'rules' => sprintf('Read the rules and community guidelines for %s.', $siteName),
            'team' => sprintf('Meet the staff and support team behind %s.', $siteName),
            'premiumfeatures' => sprintf('Review the premium features and benefits available in %s.', $siteName),
        ];

        return $map[$currentPage] ?? sprintf('%s - %s', $title, $siteName);
    }

    private static function isNoIndexPage(string $currentPage): bool
    {
        return in_array($currentPage, ['account', 'accountmanagement', 'createaccount', 'lostaccount', 'payment'], true);
    }

    private static function getRobotsDirective(string $currentPage): string
    {
        if (self::isNoIndexPage($currentPage)) {
            return 'noindex,nofollow,noarchive';
        }

        return 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
    }

    private static function getOgType(string $currentPage): string
    {
        return $currentPage === 'newsarchive' ? 'article' : 'website';
    }

    private static function getLocale(): string
    {
        return match (self::getCurrentLang()) {
            'en' => 'en_US',
            'es' => 'es_ES',
            default => 'pt_BR',
        };
    }

    private static function getHtmlLang(): string
    {
        return match (self::getCurrentLang()) {
            'en' => 'en',
            'es' => 'es',
            default => 'pt-BR',
        };
    }

    private static function getStructuredData(string $title, string $description, string $canonicalUrl): string
    {
        $data = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => SITE_NAME,
                'url' => rtrim(URL, '/') . '/',
                'description' => $description,
                'inLanguage' => self::getLocale(),
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => SITE_NAME,
                    'url' => rtrim(URL, '/') . '/',
                    'logo' => [
                        '@type' => 'ImageObject',
                        'url' => URL . '/resources/images/global/general/favicon.ico',
                    ],
                ],
                'image' => URL . '/resources/images/global/header/background-artwork-astarot-v2.jpg',
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $title,
                'url' => $canonicalUrl,
                'description' => $description,
                'isPartOf' => [
                    '@type' => 'WebSite',
                    'name' => SITE_NAME,
                    'url' => rtrim(URL, '/') . '/',
                ],
            ],
        ];

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function getPagination($request, $obPagination)
    {
        $pages = $obPagination->getPages();
        /*if(count($pages) <= 1) return '';*/

        $links = '';
        $url = $request->getRouter()->getCurrentUrl();
        $queryParams = $request->getQueryParams();

        foreach($pages as $page){
            $queryParams['page'] = $page['page'];
            $link = $url.'?'.http_build_query($queryParams);
            $links .= View::render('pagination/link', [
                'page' => $page['page'],
                'link' => $link,
                'active' => $page['current'] ? 'CurrentPageLink' : '',
            ]);
        }
        return View::render('pagination/box', [
            'links' => $links,
            'total' => count($pages)
        ]);
    }

    public static function getLogged()
    {
        if(SessionPlayerLogin::isLogged() == true){
            $code = 'true';
        }else{
            $code = 'false';
        }
        return $code;
    }

    public static function getMenu($currentPage)
    {
        $menu = [
            'latestnews' => [
                'name' => 'Last News',
                'tag' => 'latestnews',
                'link' => 'latestnews',
                'color' => 'd7d7d7',
                'category' => 'news',
            ],
            'newsarchive' => [
                'name' => 'News Archive',
                'tag' => 'newsarchive',
                'link' => 'newsarchive',
                'color' => 'd7d7d7',
                'category' => 'news',
            ],
            'eventschedule' => [
                'name' => 'Event Schedule',
                'tag' => 'eventschedule',
                'link' => 'eventcalendar',
                'color' => 'd7d7d7',
                'category' => 'news',
            ],
            'creatures' => [
                'name' => 'Creatures',
                'tag' => 'creatures',
                'link' => 'library/creatures',
                'color' => 'd7d7d7',
                'category' => 'library',
            ],
            'boostablebosses' => [
                'name' => 'Boostable Bosses',
                'tag' => 'boostablebosses',
                'link' => 'library/boostablebosses',
                'color' => 'd7d7d7',
                'category' => 'library',
            ],
            'achievements' => [
                'name' => 'Achievements',
                'tag' => 'achievements',
                'link' => 'library/achievements',
                'color' => 'd7d7d7',
                'category' => 'library',
            ],
            'experiencetable' => [
                'name' => 'Experience Table',
                'tag' => 'experiencetable',
                'link' => 'library/experiencetable',
                'color' => 'd7d7d7',
                'category' => 'library',
            ],
            'characters' => [
                'name' => 'Characters',
                'tag' => 'characters',
                'link' => 'community/characters',
                'color' => 'd7d7d7',
                'category' => 'community',
            ],
            'worlds' => [
                'name' => 'Worlds',
                'tag' => 'worlds',
                'link' => 'community/worlds',
                'color' => 'd7d7d7',
                'category' => 'community',
            ],
            'highscores' => [
                'name' => 'Highscores',
                'tag' => 'highscores',
                'link' => 'community/highscores',
                'color' => 'd7d7d7',
                'category' => 'community',
            ],
            'lastdeaths' => [
                'name' => 'Last Deaths',
                'tag' => 'lastdeaths',
                'link' => 'community/lastdeaths',
                'color' => 'd7d7d7',
                'category' => 'community',
            ],
            'houses' => [
                'name' => 'Houses',
                'tag' => 'houses',
                'link' => 'community/houses',
                'color' => 'd7d7d7',
                'category' => 'community',
            ],
            'guilds' => [
                'name' => 'Guilds',
                'tag' => 'guilds',
                'link' => 'community/guilds',
                'color' => 'd7d7d7',
                'category' => 'community',
            ],
            'polls' => [
                'name' => 'Polls',
                'tag' => 'polls',
                'link' => 'community/polls',
                'color' => 'd7d7d7',
                'category' => 'community',
            ],
            'accountmanagement' => [
                'name' => 'Account Management',
                'tag' => 'account',
                'link' => 'account',
                'color' => 'd7d7d7',
                'category' => 'account',
            ],
            'createaccount' => [
                'name' => 'Create Account',
                'tag' => 'createaccount',
                'link' => 'createaccount',
                'color' => 'd7d7d7',
                'category' => 'account',
            ],
            'downloads' => [
                'name' => 'Download Client',
                'tag' => 'downloads',
                'link' => 'downloads',
                'color' => 'd7d7d7',
                'category' => 'account',
            ],
            'lostaccount' => [
                'name' => 'Lost Account',
                'tag' => 'lostaccount',
                'link' => 'account/lostaccount',
                'color' => 'd7d7d7',
                'category' => 'account',
            ],
            'Active Wars' => [
                'name' => 'Active Wars',
                'tag' => 'activewars',
                'link' => 'guildwars/active',
                'color' => 'd7d7d7',
                'category' => 'wars',
            ],
            'Pending Wars' => [
                'name' => 'Pending Wars',
                'tag' => 'pendingwars',
                'link' => 'guildwars/pending',
                'color' => 'd7d7d7',
                'category' => 'wars',
            ],
            'Surrender Wars' => [
                'name' => 'Surrender Wars',
                'tag' => 'surrenderwars',
                'link' => 'guildwars/surrender',
                'color' => 'd7d7d7',
                'category' => 'wars',
            ],
            'Ended Wars' => [
                'name' => 'Ended Wars',
                'tag' => 'endedwars',
                'link' => 'guildwars/ended',
                'color' => 'd7d7d7',
                'category' => 'wars',
            ],
            'rules' => [
                'name' => 'Rules',
                'tag' => 'rules',
                'link' => 'support/rules',
                'color' => 'd7d7d7',
                'category' => 'support',
            ],
            'team' => [
                'name' => 'Team',
                'tag' => 'team',
                'link' => 'support/team',
                'color' => 'd7d7d7',
                'category' => 'support',
            ],
            'shop' => [
                'name' => 'Donate',
                'tag' => 'donate',
                'link' => 'payment',
                'color' => 'd7d7d7',
                'category' => 'shop',
            ],
            'premiumfeatures' => [
                'name' => 'Premium Features',
                'tag' => 'premiumfeatures',
                'link' => 'premiumfeatures',
                'color' => 'd7d7d7',
                'category' => 'shop',
            ],
        ];
        foreach($menu as $key => $value){
            if($key == $currentPage){
                $current = 1;
            }else{
                $current = 0;
            }
            $format[] = [
                'name' => $value['name'],
                'tag' => $value['tag'],
                'link' => $value['link'],
                'color' => $value['color'],
                'category' => $value['category'],
                'current' => $current,
            ];
        }
        return $format;
    }

    public static function getBase($title, $content, $currentPage = 'latestnews')
    {
        $websiteInfo = EntityServerConfig::getInfoWebsite()->fetchObject();
        $canonicalUrl = self::getCanonicalUrl();
        $seoDescription = self::getSeoDescription($currentPage, $title);
        $seoRobots = self::getRobotsDirective($currentPage);
        $seoJsonLd = self::getStructuredData($title, $seoDescription, $canonicalUrl);

        return View::render('pages/base', [
            'title' => $title . ' - ' . $websiteInfo->title . '',
            'content' => $content,
            'menu' => self::getMenu($currentPage),
            'activemenu' => $currentPage,
            'seo_description' => $seoDescription,
            'seo_canonical' => $canonicalUrl,
            'seo_robots' => $seoRobots,
            'seo_type' => self::getOgType($currentPage),
            'seo_lang' => self::getHtmlLang(),
            'seo_locale' => self::getLocale(),
            'seo_json_ld' => $seoJsonLd,
            'loginStatus' => self::getLogged(),
            'discord' => $websiteInfo->discord,
            'boostedcreature' => FunctionsServer::getBoostedCreature(),
            'boostedboss' => FunctionsServer::getBoostedBoss(),
            'playersonline' => FunctionsServer::getCountPlayersOnline(),
            'server_status' => FunctionsServer::getServerStatus(),
            'active_donates' => $websiteInfo->donates,
            'highscores' => FunctionsThemeBox::getHighscoresTop5(),
            'current_poll' => FunctionsThemeBox::getCurrentPoll(),
            'countdown' => FunctionsThemeBox::getCurrentCountdown()
        ]);
    }
}
