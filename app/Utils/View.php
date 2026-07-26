<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Utils;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use App\Utils\ViewFunctions;
use App\Utils\ViewFilters;

class View{

    private static $vars = [];

    private static function resolveViewDirectories()
    {
        $directories = [
            __DIR__.'/../../resources/view/',
            __DIR__.'/../../resources/view/base/',
            __DIR__.'/../../resources/view/pagination/',
            __DIR__.'/../../resources/view/admin/',
            __DIR__.'/../../resources/view/admin/alert/',
            __DIR__.'/../../resources/view/themeboxes/',
        ];

        $directories = array_map(function ($directory) {
            $resolved = realpath($directory);

            return $resolved !== false && is_dir($resolved) ? $resolved : null;
        }, $directories);

        return array_values(array_filter($directories));
    }

    public static function init($vars = [])
    {
        self::$vars = $vars;
    }

    public static function getContentView()
    {
        $loader = new FilesystemLoader(self::resolveViewDirectories());
        if($_ENV['DEV_MODE'] == true){
            $twig = new Environment($loader, [
                'debug' => true,
                'charset' => 'utf-8',
                'cache' => false,
                'autoescape' => 'html',
            ]);
        }else{
            $twig = new Environment($loader, [
                'debug' => false,
                'charset' => 'utf-8',
                'cache' => __DIR__.'/../../resources/view/cache',
            ]);
        }

        $ViewFunctions = new ViewFunctions;
        $twig->addFunction($ViewFunctions->addStyleCode());
        $twig->addFunction($ViewFunctions->addStyleCss());

        $ViewFilters = new ViewFilters;
        $twig->addFilter($ViewFilters->addFilters());

        return $twig;
    }

    public static function render($view, $vars = [])
    {
        $vars = array_merge(self::$vars, $vars);

        $contentView = self::getContentView();
        $html = $contentView->render(trim($view, '/').'.html.twig', $vars);
        return \App\Utils\Translator::translateHtml($html);
    }

}
