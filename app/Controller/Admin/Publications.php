<?php
/**
 * Publications Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Admin;

use App\Model\Entity\News as EntityNews;
use App\Model\Entity\Player as EntityPlayer;
use App\Utils\View;
use App\Model\Functions\News;
use App\Session\Admin\Login as SessionAdminLogin;

class Publications extends Base
{
    private static function getPlayersByLoggedAccount(): array
    {
        $idLogged = SessionAdminLogin::idLogged();
        $selectPlayers = EntityPlayer::getPlayer([ 'account_id' => $idLogged]);
        $players = [];

        while ($player = $selectPlayers->fetchObject()) {
            $players[] = [
                'id' => $player->id,
                'name' => $player->name,
            ];
        }

        return $players;
    }

    public static function insertNews($request)
    {
        $postVars = $request->getPostVars();

        if (empty($postVars['news_title'])){
            $status = Alert::getError('Adicione um titulo.');
            return self::viewPublishNews($request, $status);
        }
        if (empty($postVars['news_category'])){
            $status = Alert::getError('Selecione uma categoria.');
            return self::viewPublishNews($request, $status);
        }
        if (empty($postVars['news_body'])){
            $status = Alert::getError('Escreva o texto da publicação.');
            return self::viewPublishNews($request, $status);
        }
        if (empty($postVars['news_player'])){
            $status = Alert::getError('Selecione um player.');
            return self::viewPublishNews($request, $status);
        }

        $filter_title = filter_var($postVars['news_title'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_category = filter_var($postVars['news_category'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_player = filter_var($postVars['news_player'], FILTER_SANITIZE_NUMBER_INT);

        EntityNews::insertNews([
            'title' => $filter_title,
            'body' => $postVars['news_body'],
            'category' => $filter_category,
            'type' => 1,
            'player_id' => $filter_player,
        ]);
        $status = Alert::getSuccess('Publicado com sucesso.');
        return self::viewPublishNews($request, $status);
    }

    public static function viewPublishNews($request, $status = null)
    {
        $content = View::render('admin/modules/publications/news', [
            'status' => $status,
            'players' => self::getPlayersByLoggedAccount(),
        ]);
        return parent::getPanel('Publications', $content, 'publications');
    }

    public static function insertNewsticker($request)
    {
        $postVars = $request->getPostVars();

        if (empty($postVars['newsticker_title'])){
            $status = Alert::getError('Adicione um titulo.');
            return self::viewPublishNewsticker($request, $status);
        }
        if (empty($postVars['newsticker_category'])){
            $status = Alert::getError('Selecione uma categoria.');
            return self::viewPublishNewsticker($request, $status);
        }
        if (empty($postVars['newsticker_body'])){
            $status = Alert::getError('Escreva o texto da publicação.');
            return self::viewPublishNewsticker($request, $status);
        }
        if (empty($postVars['newsticker_author'])){
            $status = Alert::getError('Selecione um player.');
            return self::viewPublishNewsticker($request, $status);
        }

        $filter_title = filter_var($postVars['newsticker_title'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_category = filter_var($postVars['newsticker_category'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_player = filter_var($postVars['newsticker_author'], FILTER_SANITIZE_NUMBER_INT);

        EntityNews::insertNews([
            'title' => $filter_title,
            'body' => $postVars['newsticker_body'],
            'category' => $filter_category,
            'type' => 2,
            'player_id' => $filter_player,
        ]);
        $status = Alert::getSuccess('Publicado com sucesso.');
        return self::viewPublishNewsticker($request, $status);
    }

    public static function viewPublishNewsticker($request, $status = null)
    {
        $content = View::render('admin/modules/publications/newsticker', [
            'status' => $status,
            'players' => self::getPlayersByLoggedAccount(),
        ]);
        return parent::getPanel('Publications', $content, 'publications');
    }

    public static function insertFeaturedArticle($request)
    {
        $postVars = $request->getPostVars();

        if (empty($postVars['featuredarticle_title'])){
            $status = Alert::getError('Adicione um titulo.');
            return self::viewPublishFeaturedArticle($request, $status);
        }
        if (empty($postVars['featuredarticle_category'])){
            $status = Alert::getError('Selecione uma categoria.');
            return self::viewPublishFeaturedArticle($request, $status);
        }
        if (empty($postVars['featuredarticle_body'])){
            $status = Alert::getError('Escreva o texto da publicação.');
            return self::viewPublishFeaturedArticle($request, $status);
        }
        if (empty($postVars['featuredarticle_author'])){
            $status = Alert::getError('Selecione um player.');
            return self::viewPublishFeaturedArticle($request, $status);
        }

        $filter_title = filter_var($postVars['featuredarticle_title'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_category = filter_var($postVars['featuredarticle_category'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_player = filter_var($postVars['featuredarticle_author'], FILTER_SANITIZE_NUMBER_INT);

        EntityNews::insertNews([
            'title' => $filter_title,
            'body' => $postVars['featuredarticle_body'],
            'category' => $filter_category,
            'type' => 3,
            'player_id' => $filter_player,
        ]);
        $status = Alert::getSuccess('Publicado com sucesso.');
        return self::viewPublishFeaturedArticle($request, $status);
    }

    public static function viewPublishFeaturedArticle($request, $status = null)
    {
        $content = View::render('admin/modules/publications/featuredarticle', [
            'status' => $status,
            'players' => self::getPlayersByLoggedAccount(),
        ]);
        return parent::getPanel('Publications', $content, 'publications');
    }
    
    public static function viewPublications($request)
    {
        $content = View::render('admin/modules/publications/index', [
            'news' => News::getNews(),
            'newstickers' => News::getNewsTicker(),
            'featuredarticles' => News::getFeaturedArticle()
        ]);
        return parent::getPanel('Publications', $content, 'publications');
    }

}
