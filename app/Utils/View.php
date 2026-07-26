<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Utils;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use App\Utils\ViewFunctions;
use App\Utils\ViewFilters;

class View{

    private static $vars = [];

    private static function resolveViewDirectories($view)
    {
        $directories = [
            __DIR__.'/../../resources/view/' . trim($view, '/') . '/',
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

    public static function getContentView($view)
    {
        $loader = new FilesystemLoader(self::resolveViewDirectories($view));
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
        
        $array = explode('/', $view);
        $view_file = end($array);
        $remove_file = array_pop($array);
        $view_path = implode('/', $array);

        $contentView = self::getContentView($view_path);
        $html = $contentView->render($view_file.'.html.twig', $vars);
        return \App\Utils\Translator::translateHtml($html);
    }

}
