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

class ExperienceTable extends Base{
    private const MAX_LEVEL = 3500;
    private const COLUMN_COUNT = 4;

    public static function viewExperienceTable()
    {
        $content = View::render('pages/library/experiencetable', [
            'experienceColumns' => self::getExperienceColumns(),
        ]);
        return parent::getBase('Experience Table', $content, 'experiencetable');
    }

    private static function getExperienceColumns(): array
    {
        $columns = [];
        $levelsPerColumn = (int) ceil(self::MAX_LEVEL / self::COLUMN_COUNT);

        for ($column = 0; $column < self::COLUMN_COUNT; $column++) {
            $startLevel = ($column * $levelsPerColumn) + 1;
            $endLevel = min($startLevel + $levelsPerColumn - 1, self::MAX_LEVEL);
            $levels = [];

            for ($level = $startLevel; $level <= $endLevel; $level++) {
                $levels[] = [
                    'level' => number_format($level),
                    'experience' => number_format(self::getExperienceForLevel($level)),
                ];
            }

            $columns[] = $levels;
        }

        return $columns;
    }

    private static function getExperienceForLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        $currentLevel = $level - 1;
        $levelSquared = $currentLevel * $currentLevel;
        $levelCubed = $levelSquared * $currentLevel;
        $totalExperience = intdiv(
            (50 * $levelCubed) - (150 * $levelSquared) + (400 * $currentLevel),
            3
        );

        return $totalExperience;
    }
}
