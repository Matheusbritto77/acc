<?php
/**
 * Team Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Pages\Support;

use \App\Utils\View;
use App\Controller\Pages\Base;
use App\Model\Entity\Player as EntityPlayer;
use App\Model\Functions\Player as FunctionsPlayer;

class Team extends Base{

    private const OWNER_NAME = 'Sethdev';
    private const OWNER_ROLE = 'Server Owner';

    public static function getTeam()
    {
        $select_players = EntityPlayer::getPlayer(['group_id >=' => 2]);
        while ($player = $select_players->fetchObject()) {
            $displayName = $player->name;
            $displayGroup = FunctionsPlayer::convertGroup($player->group_id);

            if (strcasecmp($player->name, self::OWNER_NAME) === 0) {
                $displayName = self::OWNER_NAME;
                $displayGroup = self::OWNER_ROLE;
            }

            $arrayTeam[] = [
                'name' => $displayName,
                'group' => $displayGroup,
            ];
        }
        return $arrayTeam ?? [];
    }
    public static function viewTeam($request)
    {
        $content = View::render('pages/support/team', [
            'players' => self::getTeam()
        ]);
        return parent::getBase('Team', $content, $currentPage = 'team');
    }

}
