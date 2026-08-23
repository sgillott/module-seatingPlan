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

use Gibbon\Module\SeatingPlan\Domain\FurnitureGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;

/**
 * Moving room layouts in and out of the module as a JSON file.
 *
 * A layout is a drawing of furniture and nothing else, which makes it the
 * one thing in this module that is genuinely portable: it can be sent to a
 * colleague, kept as a backup before a room is rearranged, or carried to
 * another Gibbon install entirely.
 *
 * **The file never contains a person.** No owner, no students, no seating
 * plan, no attendance, no rewards - only the room's dimensions, its
 * furniture, and the names of the layout and the room it was drawn for.
 * There is therefore nothing in an exported file that could identify
 * anybody, which is what makes it safe to email around.
 *
 * **Importing never overwrites.** It always creates a new layout owned by
 * whoever imported it, exactly as duplicating a colleague's layout already
 * does. A file cannot be used to reach into a layout somebody else owns,
 * and an import can never destroy work that is already there.
 */
class LayoutTransfer
{
    /**
     * Identifies our own files, so a JSON file from somewhere else is
     * rejected with a useful message rather than a type error.
     */
    public const FORMAT = 'gibbon.seatingPlan.roomLayout';

    /**
     * Raised when the shape of the file changes incompatibly. A file from
     * a newer version than this code understands is refused rather than
     * guessed at.
     */
    public const FORMAT_VERSION = 1;

    /**
     * @var RoomLayoutGateway
     */
    private $layoutGateway;

    /**
     * @var FurnitureGateway
     */
    private $furnitureGateway;

    /**
     * @param RoomLayoutGateway $layoutGateway    Room layouts.
     * @param FurnitureGateway  $furnitureGateway Their furniture.
     */
    public function __construct(
        RoomLayoutGateway $layoutGateway,
        FurnitureGateway $furnitureGateway
    ) {
        $this->layoutGateway = $layoutGateway;
        $this->furnitureGateway = $furnitureGateway;
    }

    /**
     * Builds the export payload for a set of layouts.
     *
     * Only layouts the person may already see are included - their own, and
     * anything a colleague has shared. Exporting is reading, so it follows
     * the same rule as the Room Layouts list itself; a layout the person
     * cannot see is silently left out rather than reported, so the file
     * cannot be used to probe for what exists.
     *
     * @param array  $layoutIDs      The layouts to export.
     * @param string $gibbonPersonID The person exporting.
     * @param string $moduleVersion  Recorded for support purposes only.
     *
     * @return array Ready for json_encode.
     */
    public function export(
        array $layoutIDs,
        $gibbonPersonID,
        $moduleVersion = ''
    ): array {
        $layouts = [];

        foreach ($layoutIDs as $layoutID) {
            $layout = $this->layoutGateway->getLayoutByID($layoutID);

            if (empty($layout)) {
                continue;
            }

            if (
                $layout['gibbonPersonIDOwner'] != $gibbonPersonID
                && $layout['shared'] != 'Y'
            ) {
                continue;
            }

            $layouts[] = [
                'name'      => (string) $layout['name'],
                // The room this was drawn for, by name. Informational only:
                // space IDs mean nothing outside the install they came
                // from, so an import always asks which room to put it in.
                'roomName'  => (string) ($layout['spaceName'] ?? ''),
                'gridCols'  => (int) $layout['gridCols'],
                'gridRows'  => (int) $layout['gridRows'],
                'furniture' => array_map(
                    function ($item) {
                        return [
                            'type'     => (string) $item['type'],
                            'posX'     => (int) $item['posX'],
                            'posY'     => (int) $item['posY'],
                            'rotation' => (int) $item['rotation'],
                            'flipped'  => $item['flipped'] === 'Y',
                            'sizeX'    => (int) $item['sizeX'],
                            'sizeY'    => (int) $item['sizeY'],
                        ];
                    },
                    $this->furnitureGateway->selectFurnitureByLayout($layoutID)
                ),
            ];
        }

        return [
            'format'        => self::FORMAT,
            'formatVersion' => self::FORMAT_VERSION,
            'moduleVersion' => (string) $moduleVersion,
            'exportedAt'    => date('c'),
            'layouts'       => $layouts,
        ];
    }

    /**
     * Reads an uploaded file into a list of importable layouts.
     *
     * Every failure is a clear message rather than a warning and a half
     * result: a teacher who picks the wrong file should be told so.
     *
     * @param string $raw The file's contents.
     *
     * @return array One entry per layout, each with name, roomName,
     *                gridCols, gridRows and furniture.
     *
     * @throws \InvalidArgumentException When the file is not usable.
     */
    public function parse($raw): array
    {
        $payload = json_decode((string) $raw, true);

        if (!is_array($payload)) {
            throw new \InvalidArgumentException(
                __('That file is not readable as JSON.')
            );
        }

        if (($payload['format'] ?? '') !== self::FORMAT) {
            throw new \InvalidArgumentException(
                __('That file is not a Seating Plan room layout export.')
            );
        }

        if ((int) ($payload['formatVersion'] ?? 0) > self::FORMAT_VERSION) {
            throw new \InvalidArgumentException(
                __('That file was made by a newer version of this module.')
            );
        }

        $layouts = $payload['layouts'] ?? null;

        if (!is_array($layouts) || empty($layouts)) {
            throw new \InvalidArgumentException(
                __('That file contains no room layouts.')
            );
        }

        return array_map([$this, 'readLayout'], array_values($layouts));
    }

    /**
     * One layout entry from a file, with its numbers clamped into the
     * range the designer itself allows.
     *
     * @param mixed $layout The raw entry.
     *
     * @return array
     *
     * @throws \InvalidArgumentException When the entry is not a layout.
     */
    private function readLayout($layout): array
    {
        if (!is_array($layout)) {
            throw new \InvalidArgumentException(
                __('That file contains no room layouts.')
            );
        }

        $furniture = $layout['furniture'] ?? [];

        if (!is_array($furniture)) {
            throw new \InvalidArgumentException(
                __('One of the layouts in that file has no furniture list.')
            );
        }

        if (count($furniture) > 400) {
            throw new \InvalidArgumentException(
                __('That is more furniture than a room can hold.')
            );
        }

        return [
            'name'      => mb_substr(trim((string) ($layout['name'] ?? '')), 0, 40),
            'roomName'  => (string) ($layout['roomName'] ?? ''),
            'gridCols'  => $this->clampGrid($layout['gridCols'] ?? 20),
            'gridRows'  => $this->clampGrid($layout['gridRows'] ?? 14),
            'furniture' => array_values($furniture),
        ];
    }

    /**
     * @param mixed $value A grid dimension from a file.
     *
     * @return int Within the 10-40 range the designer enforces.
     */
    private function clampGrid($value): int
    {
        return max(10, min(40, (int) $value));
    }

    /**
     * Creates one new layout from a parsed entry.
     *
     * The furniture is checked against the catalogue and the room bounds
     * *before* the layout row is created, so a file the module cannot place
     * leaves nothing behind at all.
     *
     * @param array  $layout         An entry from parse().
     * @param string $gibbonSpaceID  The room to put it in.
     * @param string $gibbonPersonID The importer, who owns the result.
     * @param string $name           Overrides the file's own name if given.
     * @param string $shared         'Y' or 'N'.
     *
     * @return string The new seatingPlanRoomLayoutID.
     *
     * @throws \InvalidArgumentException When the furniture will not fit.
     */
    public function import(
        array $layout,
        $gibbonSpaceID,
        $gibbonPersonID,
        $name = '',
        $shared = 'Y'
    ) {
        $items = $this->furnitureGateway->validateItems(
            $layout['furniture'],
            $layout['gridCols'],
            $layout['gridRows']
        );

        $name = mb_substr(trim((string) $name), 0, 40);

        if ($name === '') {
            $name = $layout['name'] !== '' ? $layout['name'] : __('Imported layout');
        }

        $layoutID = $this->layoutGateway->insert([
            'gibbonSpaceID'       => $gibbonSpaceID,
            'name'                => $name,
            'gridCols'            => $layout['gridCols'],
            'gridRows'            => $layout['gridRows'],
            'gibbonPersonIDOwner' => $gibbonPersonID,
            'shared'              => $shared === 'N' ? 'N' : 'Y',
            'timestampModified'   => date('Y-m-d H:i:s'),
        ]);

        $this->furnitureGateway->replaceFurnitureForLayout(
            $layoutID,
            $items,
            $layout['gridCols'],
            $layout['gridRows']
        );

        return $layoutID;
    }
}
