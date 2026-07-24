<?php
/**
 * Validator class
 *
 * @package   CanaryAAC
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 CanaryAAC
 */

namespace App\Controller\Api;

use App\DatabaseManager\Pagination;
use App\Model\Entity\Highscores as EntityHighscores;
use App\Model\Functions\Player;
use App\Http\Request;
use Exception;

class Highscores extends Api
{
    public static function getHighscoresCharacters($request, &$obPagination)
    {

        $queryParams = $request->getQueryParams();
        $professionMap = [
            'sorcerer' => 1,
            'druid' => 2,
            'paladin' => 3,
            'knight' => 4,
            'monk' => 9,
            'exalted_monk' => 10,
        ];

        $profession = null;
        $inputProfession = $queryParams['profession'] ?? null;
        if (is_numeric($inputProfession)) {
            $inputProfession = (int) $inputProfession;
        }
        if (isset($professionMap[$inputProfession])) {
            $profession = 'vocation = ' . $professionMap[$inputProfession];
        } elseif (in_array($inputProfession, $professionMap, true)) {
            $profession = 'vocation = ' . $inputProfession;
        }

        switch($queryParams['category']) {
            case 1:
            case 'achievements':
                $category = 'achievements DESC';
                break;
            case 2:
            case 'axe':
                $category = 'skill_axe DESC';
                break;
            case 3:
            case 'charm':
                $category = 'charm DESC';
                break;
            case 4:
            case 'club':
                $category = 'skill_club DESC';
                break;
            case 5:
            case 'distance':
                $category = 'skill_dist DESC';
                break;
            case 6:
            case 'experience':
                $category = 'level DESC';
                break;
            case 7:
            case 'fishing':
                $category = 'skill_fishing DESC';
                break;
            case 8:
            case 'fist':
                $category = 'skill_fist DESC';
                break;
            case 9:
            case 'taint':
                $category = 'taint DESC';
                break;
            case 10:
            case 'loyalty':
                $category = 'loyalty DESC';
                break;
            case 11:
            case 'magiclevel':
                $category = 'maglevel DESC';
                break;
            case 12:
            case 'shielding':
                $category = 'skill_shielding DESC';
                break;
            case 13:
            case 'sword':
                $category = 'skill_sword DESC';
                break;
            default:
                $category = 'level DESC';
                break;
        }

        $player = [];

        $totalAmount = EntityHighscores::getHighscoresEntity($profession, $category, null, ['COUNT(*) as qtd'])->fetchObject()->qtd;
        $currentPage = $queryParams['page'] ?? 1;
        $obPagination = new Pagination($totalAmount, $currentPage, 2);

        $results = EntityHighscores::getHighscoresEntity($profession, $category, $obPagination->getLimit());

        while($obRank = $results->fetchObject(EntityHighscores::class)) {
            $player[] = [
                'name' => $obRank->name,
                'vocation' => Player::convertVocation($obRank->vocation),
                'level' => (int)$obRank->level,
                'experience' => (int)$obRank->experience,
                'online' => Player::isOnline($obRank->id)
            ];
        }

        if($totalAmount == 0) {
            throw new Exception('Nenhuma house foi encontrada.', 404);
        }

        return $player;
    }

    /**
     * Método responsável por retornar os detalhes da API
     *
     * @param Request $request
     * @return array
     */
    public static function getHighscores($request)
    {
        return [
            'highscores' => self::getHighscoresCharacters($request, $obPagination),
            'pagination' => parent::getPagination($request, $obPagination)
        ];
    }

}
