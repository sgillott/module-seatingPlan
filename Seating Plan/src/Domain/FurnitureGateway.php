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

namespace Gibbon\Module\SeatingPlan\Domain;

use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;
use Gibbon\Module\SeatingPlan\FurnitureCatalogue;

/**
 * Furniture items belonging to a room layout.
 */
class FurnitureGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'seatingPlanFurniture';
    private static $primaryKey = 'seatingPlanFurnitureID';

    /**
     * Every item on a layout, ordered so rendering is deterministic.
     *
     * @param string $seatingPlanRoomLayoutID The layout.
     *
     * @return array
     */
    public function selectFurnitureByLayout($seatingPlanRoomLayoutID): array
    {
        $data = ['seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID];
        $sql = "SELECT type, posX, posY, rotation, flipped, sizeX, sizeY
            FROM seatingPlanFurniture
            WHERE seatingPlanRoomLayoutID=:seatingPlanRoomLayoutID
            ORDER BY posY, posX, seatingPlanFurnitureID";

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Replaces every item on a layout in one transaction.
     *
     * Items are validated against the catalogue and the room bounds before
     * anything is written. An invalid item aborts the whole save, so a layout
     * is never left half-written.
     *
     * @param string $seatingPlanRoomLayoutID The layout.
     * @param array  $items                   Raw items from the designer.
     * @param int    $cols                    Room width in cells.
     * @param int    $rows                    Room height in cells.
     *
     * @return int The number of items written.
     *
     * @throws \InvalidArgumentException When an item fails validation.
     */
    public function replaceFurnitureForLayout(
        $seatingPlanRoomLayoutID,
        array $items,
        int $cols,
        int $rows,
        bool $manageTransaction = true
    ): int {
        $clean = $this->validateItems($items, $cols, $rows);

        if (!$manageTransaction) {
            return $this->replaceFurnitureRows($seatingPlanRoomLayoutID, $clean);
        }

        $this->db()->beginTransaction();
        try {
            $count = $this->replaceFurnitureRows($seatingPlanRoomLayoutID, $clean);
            $this->db()->commit();
            return $count;
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }
    }

    /**
     * Writes validated furniture inside the caller's transaction.
     */
    private function replaceFurnitureRows($seatingPlanRoomLayoutID, array $clean): int
    {
        $this->db()->statement(
            'DELETE FROM seatingPlanFurniture
             WHERE seatingPlanRoomLayoutID=:seatingPlanRoomLayoutID',
            ['seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID]
        );

        foreach ($clean as $item) {
            $item['seatingPlanRoomLayoutID'] = $seatingPlanRoomLayoutID;
            $this->insert($item);
        }

        return count($clean);
    }

    /**
     * Checks a whole set of items and returns them as safe integers,
     * without writing anything.
     *
     * Separate from replaceFurnitureForLayout() so an import can find out
     * whether a file is placeable *before* creating the layout row it would
     * go into - otherwise a bad file leaves an empty layout behind.
     *
     * @param array $items Raw items.
     * @param int   $cols  Room width in cells.
     * @param int   $rows  Room height in cells.
     *
     * @return array
     *
     * @throws \InvalidArgumentException When an item is not placeable.
     */
    public function validateItems(array $items, int $cols, int $rows): array
    {
        $clean = [];

        foreach ($items as $index => $item) {
            $clean[] = $this->validateItem(
                is_array($item) ? $item : [],
                $index,
                $cols,
                $rows
            );
        }

        return $clean;
    }

    /**
     * Checks one incoming item and returns it as safe integers.
     *
     * @param array $item  The raw item.
     * @param int   $index Position in the payload, for the error message.
     * @param int   $cols  Room width in cells.
     * @param int   $rows  Room height in cells.
     *
     * @return array
     *
     * @throws \InvalidArgumentException When the item is not placeable.
     */
    private function validateItem(array $item, int $index, int $cols, int $rows): array
    {
        $type = (string) ($item['type'] ?? '');

        if (!FurnitureCatalogue::has($type)) {
            throw new \InvalidArgumentException(
                sprintf('Item %d has an unknown type.', $index + 1)
            );
        }

        $step = FurnitureCatalogue::SUBDIVISIONS;
        $spec = FurnitureCatalogue::get($type);

        // Everything below is in tenths of a cell. Catalogue sizes are in cells
        // and may be fractional, so a board can be half a cell deep; the scaling
        // and rounding happen once, here.
        $defaultX = (int) round($spec['wide'] * $step);
        $defaultY = (int) round($spec['high'] * $step);

        // One tenth of a cell is the floor, not half a cell: a wall is drawn
        // thinner than that by default and must be allowed to stay thin.
        $sizeX = FurnitureCatalogue::isResizable($type)
            ? max(1, (int) ($item['sizeX'] ?? $defaultX))
            : $defaultX;
        $sizeY = FurnitureCatalogue::isResizable($type)
            ? max(1, (int) ($item['sizeY'] ?? $defaultY))
            : $defaultY;

        $rotation = (int) ($item['rotation'] ?? 0);
        $rotation = ($rotation % 4 + 4) % 4;

        // A quarter or three-quarter turn swaps the footprint.
        $turned = $rotation === 1 || $rotation === 3;
        $spanX = $turned ? $sizeY : $sizeX;
        $spanY = $turned ? $sizeX : $sizeY;

        $posX = (int) ($item['posX'] ?? 0);
        $posY = (int) ($item['posY'] ?? 0);

        $limitX = $cols * $step;
        $limitY = $rows * $step;

        if ($posX < 0 || $posY < 0
            || $posX + $spanX > $limitX
            || $posY + $spanY > $limitY
        ) {
            // Name the piece: an index into a list the user cannot see tells
            // them nothing about what to move.
            throw new \InvalidArgumentException(
                __(
                    'A {piece} does not fit inside the room. Move it away from '
                    . 'the wall, or make the room bigger.',
                    ['piece' => mb_strtolower(__($spec['label']))]
                )
            );
        }

        // Mirroring changes which way a door swings, not the space it takes,
        // so it needs no bounds check of its own.
        $flipped = !empty($item['flipped']) && $item['flipped'] !== 'N'
            && FurnitureCatalogue::isMirrorable($type)
            ? 'Y' : 'N';

        // Store the unrotated size; rotation is applied when rendering.
        return [
            'type'     => $type,
            'posX'     => $posX,
            'posY'     => $posY,
            'rotation' => $rotation,
            'flipped'  => $flipped,
            'sizeX'    => $sizeX,
            'sizeY'    => $sizeY,
        ];
    }

    /**
     * Removes every item on a layout, used when a layout is deleted.
     *
     * @param string $seatingPlanRoomLayoutID The layout.
     *
     * @return bool
     */
    public function deleteFurnitureByLayout($seatingPlanRoomLayoutID): bool
    {
        $data = ['seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID];

        return $this->deleteWhere($data);
    }

    /**
     * Copies every item from one layout onto another.
     *
     * @param string $sourceID The layout to copy from.
     * @param string $targetID The layout to copy to.
     *
     * @return bool
     */
    public function copyFurniture($sourceID, $targetID): bool
    {
        $data = ['sourceID' => $sourceID, 'targetID' => $targetID];
        $sql = "INSERT INTO seatingPlanFurniture
                (seatingPlanRoomLayoutID, type, posX, posY, rotation, flipped,
                    sizeX, sizeY)
            SELECT :targetID, type, posX, posY, rotation, flipped, sizeX, sizeY
            FROM seatingPlanFurniture
            WHERE seatingPlanRoomLayoutID=:sourceID";

        return $this->db()->statement($sql, $data);
    }
}
