<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Utils;

use App\Security\Csrf;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use App\Utils\ViewFunctions;
use App\Utils\ViewFilters;

class View{

    private static $vars = [];

    private static function resolveViewDirectories($view = null)
    {
        $directories = [
            __DIR__.'/../../resources/view/',
            __DIR__.'/../../resources/view/base/',
            __DIR__.'/../../resources/view/pagination/',
            __DIR__.'/../../resources/view/admin/',
            __DIR__.'/../../resources/view/admin/alert/',
            __DIR__.'/../../resources/view/themeboxes/',
        ];

        if (is_string($view) && trim($view, '/') !== '') {
            $directories[] = __DIR__.'/../../resources/view/' . trim($view, '/') . '/';
        }

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

    public static function getContentView($view = null)
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

    private static function shouldInjectCsrfFields(string $view): bool
    {
        $normalizedView = trim($view, '/');

        return strpos($normalizedView, 'admin/') === 0
            || strpos($normalizedView, 'pages/account/') === 0
            || strpos($normalizedView, 'pages/community/charbazaar') === 0;
    }

    private static function injectCsrfFields($view, $html)
    {
        if (!self::shouldInjectCsrfFields((string) $view)) {
            return $html;
        }

        return preg_replace_callback('/<form\b([^>]*)>/i', function ($matches) {
            $attributes = $matches[1] ?? '';

            if (!preg_match('/\bmethod\s*=\s*([\"\']?)(post|put|delete)\1/i', $attributes)) {
                return $matches[0];
            }

            return $matches[0] . Csrf::getField();
        }, $html);
    }

    public static function render($view, $vars = [])
    {
        $vars = array_merge(self::$vars, $vars);

        $normalizedView = trim($view, '/');
        $contentView = self::getContentView(dirname($normalizedView));
        $loader = $contentView->getLoader();
        $template = $normalizedView.'.html.twig';

        try {
            if ($loader instanceof FilesystemLoader && !$loader->exists($template)) {
                $template = basename($template);
            }
        } catch (LoaderError) {
            $template = basename($template);
        }

        $html = $contentView->render($template, $vars);
        $html = self::injectCsrfFields($normalizedView, $html);
        return \App\Utils\Translator::translateHtml($html);
    }

}
