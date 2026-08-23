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
 * The furniture types a room layout may contain.
 *
 * Sizes here are whole grid cells, one cell being the size of a chair. Stored
 * positions and sizes are in tenths of a cell, so furniture can be nudged into
 * place rather than jumping a whole chair width at a time.
 */
class FurnitureCatalogue
{
    /**
     * How many steps a cell is divided into on each axis.
     */
    public const SUBDIVISIONS = 10;

    /**
     * Every placeable type, keyed by the value stored in the database.
     *
     * @var array
     */
    private static $types = [
        'chair' => [
            'label'     => 'Chair',
            'wide'      => 1,
            'high'      => 1,
            'resizable' => false,
            'seat'      => true,
            'surface'   => false,
            'facesToward' => 'surface',
            'layer'     => 'seat',
        ],
        'deskSingle' => [
            'label'     => 'Single Desk',
            'wide'      => 1,
            'high'      => 1,
            'resizable' => false,
            'seat'      => false,
            'surface'   => true,
        ],
        'deskDouble' => [
            'label'     => 'Double Desk',
            'wide'      => 2,
            'high'      => 1,
            'resizable' => false,
            'seat'      => false,
            'surface'   => true,
        ],
        'deskTeacher' => [
            'label'     => 'Teacher Desk',
            'wide'      => 3,
            'high'      => 1,
            'resizable' => false,
            'seat'      => false,
            'surface'   => true,
        ],
        'slab' => [
            'label'     => 'Benching',
            'wide'      => 2,
            'high'      => 2,
            'resizable' => true,
            'seat'      => false,
            'surface'   => true,
        ],
        'computer' => [
            'label'     => 'Computer',
            'wide'      => 1,
            'high'      => 1,
            'resizable' => false,
            'seat'      => false,
            'surface'   => true,
            'facesToward' => 'seat',
            'facesFromWalls' => true,
            'layer'     => 'device',
        ],
        'board' => [
            'label'     => 'Board',
            'wide'      => 6,
            'high'      => 0.5,
            'resizable' => true,
            'planLabel' => 'Board',
            'seat'      => false,
            'surface'   => false,
        ],
        'screen' => [
            'label'     => 'Display Screen',
            'wide'      => 3,
            'high'      => 1,
            'resizable' => false,
            'planLabel' => 'Screen',
            'seat'      => false,
            'surface'   => false,
        ],
        'door' => [
            'label'     => 'Door',
            'wide'      => 1.5,
            'high'      => 1.5,
            'resizable' => false,
            'planLabel' => 'Door',
            'mirrorable' => true,
            'seat'      => false,
            'surface'   => false,
        ],
        'wall' => [
            'label'     => 'Wall',
            'wide'      => 4,
            'high'      => 0.1,
            'resizable' => true,
            'seat'      => false,
            'surface'   => false,
            'wall'      => true,
        ],
        'cupboard' => [
            'label'     => 'Cupboard',
            'wide'      => 2,
            'high'      => 1,
            'resizable' => true,
            'seat'      => false,
            'surface'   => false,
        ],
    ];

    /**
     * All types, in the order they appear in the designer palette.
     *
     * @return array
     */
    public static function all(): array
    {
        return self::$types;
    }

    /**
     * Whether a stored type string is one we recognise.
     *
     * @param string $type The type key.
     *
     * @return bool
     */
    public static function has(string $type): bool
    {
        return isset(self::$types[$type]);
    }

    /**
     * A single type definition, or an empty array when unknown.
     *
     * @param string $type The type key.
     *
     * @return array
     */
    public static function get(string $type): array
    {
        return self::$types[$type] ?? [];
    }

    /**
     * Whether a type may be resized by the user.
     *
     * @param string $type The type key.
     *
     * @return bool
     */
    public static function isResizable(string $type): bool
    {
        return !empty(self::$types[$type]['resizable']);
    }

    /**
     * Whether a type is a seat a student can be placed on.
     *
     * @param string $type The type key.
     *
     * @return bool
     */
    public static function isSeat(string $type): bool
    {
        return !empty(self::$types[$type]['seat']);
    }

    /**
     * Whether a type can be mirrored, giving it a left and right hand.
     *
     * @param string $type The type key.
     *
     * @return bool
     */
    public static function isMirrorable(string $type): bool
    {
        return !empty(self::$types[$type]['mirrorable']);
    }

    /**
     * The catalogue as a JSON string, for the designer script.
     *
     * @return string
     */
    public static function toJson(): string
    {
        $palette = [];

        foreach (self::$types as $type => $spec) {
            $palette[$type] = [
                'label'     => __($spec['label']),
                // Sizes reach the browser already in tenths, so the designer
                // never has to convert between two units.
                'wide'      => (int) round($spec['wide'] * self::SUBDIVISIONS),
                'high'      => (int) round($spec['high'] * self::SUBDIVISIONS),
                'resizable' => $spec['resizable'],
                'seat'      => $spec['seat'],
                // A work surface is something a chair should be turned to face.
                'surface'   => !empty($spec['surface']),
                // Text drawn on the piece itself, for things that are otherwise
                // just a line on the floor.
                'planLabel' => !empty($spec['planLabel'])
                    ? __($spec['planLabel']) : '',
                // Only a door has a handedness worth flipping.
                'mirrorable' => !empty($spec['mirrorable']),
                // What this piece turns to face, if anything, and whether it
                // should turn its back on a wall when nothing else is near.
                'facesToward' => $spec['facesToward'] ?? '',
                'facesFromWalls' => !empty($spec['facesFromWalls']),
                'wall' => !empty($spec['wall']),
                // Which stacking layer the piece sits in.
                'layer' => $spec['layer'] ?? 'base',
            ];
        }

        return json_encode($palette);
    }
}
