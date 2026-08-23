<?php
/**
 * Gibbon, Flexible & Open School System
 * Copyright (C) 2010, Ross Parker
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\SeatingPlan;

/**
 * Compares two furniture layouts so a teacher can see what taking somebody
 * else's version would actually change.
 *
 * A piece is "the same" when everything about it matches - type, position,
 * rotation, mirroring and size. Anything else is a piece that appears or
 * disappears; a desk that moved reads as one going and another arriving,
 * which is both true and the easiest thing to see on a picture.
 *
 * Duplicates are counted rather than deduplicated: four identical chairs in
 * one layout and three in the other means one chair is lost, not none.
 */
class LayoutComparison
{
    /**
     * Marks up both sides for previewing.
     *
     * @param array $mine   The viewer's current furniture rows.
     * @param array $theirs The furniture they are being offered.
     *
     * @return array ['mine' => rows each with a 'state' of 'same' or
     *                'removed', 'theirs' => rows each with 'same' or
     *                'added', 'added' => int, 'removed' => int]
     */
    public static function compare(array $mine, array $theirs): array
    {
        $mineCounts = self::countByShape($mine);
        $theirCounts = self::countByShape($theirs);

        // Walk each side against a copy of the other side's tally,
        // spending a match as it is used, so repeated identical pieces
        // pair up one for one.
        $remaining = $theirCounts;
        $minePreview = self::mark($mine, $remaining, 'removed');

        $remaining = $mineCounts;
        $theirsPreview = self::mark($theirs, $remaining, 'added');

        return [
            'mine'    => $minePreview,
            'theirs'  => $theirsPreview,
            'removed' => self::countState($minePreview, 'removed'),
            'added'   => self::countState($theirsPreview, 'added'),
        ];
    }

    /**
     * @param array $rows Furniture rows.
     *
     * @return array Shape key => how many of that exact piece there are.
     */
    private static function countByShape(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $key = self::shapeKey($row);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Tags each row 'same' when the other side still has one to spare, or
     * $state when it does not.
     *
     * @param array  $rows      Furniture rows.
     * @param array  $remaining The other side's tally, spent as it matches.
     * @param string $state     What to call a row with no counterpart.
     *
     * @return array
     */
    private static function mark(array $rows, array &$remaining, string $state): array
    {
        $marked = [];

        foreach ($rows as $row) {
            $key = self::shapeKey($row);

            if (!empty($remaining[$key])) {
                --$remaining[$key];
                $row['state'] = 'same';
            } else {
                $row['state'] = $state;
            }

            $marked[] = $row;
        }

        return $marked;
    }

    /**
     * @param array  $rows  Marked rows.
     * @param string $state The state to count.
     *
     * @return int
     */
    private static function countState(array $rows, string $state): int
    {
        $total = 0;

        foreach ($rows as $row) {
            if (($row['state'] ?? '') === $state) {
                ++$total;
            }
        }

        return $total;
    }

    /**
     * Everything that makes one piece of furniture identical to another.
     *
     * @param array $row A furniture row.
     *
     * @return string
     */
    private static function shapeKey(array $row): string
    {
        return implode(
            '|',
            [
                (string) ($row['type'] ?? ''),
                (int) ($row['posX'] ?? 0),
                (int) ($row['posY'] ?? 0),
                (int) ($row['rotation'] ?? 0),
                ($row['flipped'] ?? 'N') === 'Y' ? 'Y' : 'N',
                (int) ($row['sizeX'] ?? 0),
                (int) ($row['sizeY'] ?? 0),
            ]
        );
    }
}
