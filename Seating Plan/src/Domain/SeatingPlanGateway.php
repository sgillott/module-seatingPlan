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
use Gibbon\Module\SeatingPlan\ClassListNormalizer;
use Gibbon\Module\SeatingPlan\FurnitureCatalogue;

/**
 * Seating plans and the seats on them.
 *
 * A plan is keyed to one room layout plus the set of classes that meet there,
 * not to a date: one arrangement serves every period where the same group
 * sits in the same room. A seat stores only a person and a grid position - no
 * name, no photo - both are pulled live from gibbonPerson when the room is
 * drawn, so nothing about a student is duplicated into this module's tables.
 */
class SeatingPlanGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'seatingPlanPlan';
    private static $primaryKey = 'seatingPlanPlanID';

    /**
     * The plan for a room layout and class set, if one has been saved yet.
     *
     * @param string $seatingPlanRoomLayoutID The furniture layout.
     * @param string $classList               Sorted, comma-joined class IDs.
     *
     * @return array Empty when nobody has seated anyone here yet.
     */
    public function getPlanByLayoutAndClasses(
        $seatingPlanRoomLayoutID,
        $classList
    ): array {
        $data = [
            'seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID,
            'classList'               => $classList,
        ];
        $sql = "SELECT * FROM seatingPlanPlan
            WHERE seatingPlanRoomLayoutID=:seatingPlanRoomLayoutID
                AND classList=:classList
            ORDER BY isDefault DESC, seatingPlanPlanID
            LIMIT 1";

        return $this->db()->selectOne($sql, $data) ?: [];
    }

    /**
     * How much seating hangs off a layout, so the owner can be told what
     * replacing its furniture would disturb before they agree to it.
     *
     * @param string $seatingPlanRoomLayoutID The layout.
     *
     * @return array ['plans' => int, 'seats' => int]
     */
    public function countSeatedPlansByLayout($seatingPlanRoomLayoutID): array
    {
        $data = ['seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID];
        $sql = "SELECT
                COUNT(DISTINCT seatingPlanPlan.seatingPlanPlanID) AS plans,
                COUNT(seatingPlanSeat.seatingPlanSeatID) AS seats
            FROM seatingPlanPlan
            JOIN seatingPlanSeat
                ON (seatingPlanSeat.seatingPlanPlanID
                    =seatingPlanPlan.seatingPlanPlanID)
            WHERE seatingPlanPlan.seatingPlanRoomLayoutID
                =:seatingPlanRoomLayoutID";

        $row = $this->db()->selectOne($sql, $data) ?: [];

        return [
            'plans' => (int) ($row['plans'] ?? 0),
            'seats' => (int) ($row['seats'] ?? 0),
        ];
    }

    /**
     * Matches seated students to the chairs of a room that has just
     * changed shape underneath them.
     *
     * Nearest-free-chair, taken greedily: every possible pairing is scored
     * by how far the student would move, and the closest pairing is settled
     * first. Not an optimal assignment - that would be the Hungarian
     * algorithm - but this one has the property that actually matters here:
     * a student sitting on a chair that did not move scores zero, is
     * matched before anything else, and therefore never shuffles for no
     * reason. With at most sixty students and four hundred pieces the cost
     * is irrelevant.
     *
     * A student with no chair left is unseated rather than parked on the
     * floor: an unseated student shows in the room's own "still need a
     * seat" count, whereas one left at stale coordinates looks seated and
     * is not.
     *
     * A pure function of its two arguments, so it can be exercised against
     * constructed cases without a database.
     *
     * @param array $seats  Rows with gibbonPersonID, posX, posY.
     * @param array $chairs Rows with posX, posY.
     *
     * @return array ['seats' => the surviving seats with their new
     *                positions, 'moved' => how many changed position,
     *                'unseated' => gibbonPersonID values with nowhere left].
     */
    /**
     * Gets the one plan for a layout/class set, creating it atomically when
     * the first save for that set arrives.
     *
     * @return array The existing or newly-created plan row.
     */
    public function getOrCreateForLayoutAndClasses(
        $seatingPlanRoomLayoutID,
        $gibbonSpaceID,
        $classList,
        $gibbonPersonIDOwner
    ): array {
        $classList = ClassListNormalizer::normalize($classList);
        $data = [
            'seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID,
            'classList'               => $classList,
        ];
        $existing = $this->getPlanByLayoutAndClasses(
            $seatingPlanRoomLayoutID,
            $classList
        );

        if (!empty($existing)) {
            return $existing;
        }

        $transactionStarted = false;
        try {
            $this->db()->beginTransaction();
            $transactionStarted = true;
            $existing = $this->db()->selectOne(
                'SELECT * FROM seatingPlanPlan
                 WHERE seatingPlanRoomLayoutID=:seatingPlanRoomLayoutID
                     AND classList=:classList
                 ORDER BY isDefault DESC, seatingPlanPlanID
                 LIMIT 1 FOR UPDATE',
                $data
            ) ?: [];

            if (empty($existing)) {
                $planID = $this->insert([
                    'seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID,
                    'gibbonSpaceID'           => $gibbonSpaceID,
                    'classList'               => $classList,
                    'gibbonPersonIDOwner'     => $gibbonPersonIDOwner,
                    'name'                    => __('Seating Plan'),
                    'isDefault'               => 'Y',
                    'timestampModified'       => date('Y-m-d H:i:s'),
                ]);
                $existing = $this->db()->selectOne(
                    'SELECT * FROM seatingPlanPlan
                     WHERE seatingPlanPlanID=:seatingPlanPlanID',
                    ['seatingPlanPlanID' => $planID]
                ) ?: [];
            }

            $this->db()->commit();
            return $existing;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $this->db()->rollBack();
            }

            $existing = $this->getPlanByLayoutAndClasses(
                $seatingPlanRoomLayoutID,
                $classList
            );
            if (!empty($existing)) {
                return $existing;
            }

            throw $e;
        }
    }

    public static function matchSeatsToChairs(array $seats, array $chairs): array
    {
        $pairs = [];

        foreach ($seats as $s => $seat) {
            foreach ($chairs as $c => $chair) {
                $dx = (int) $seat['posX'] - (int) $chair['posX'];
                $dy = (int) $seat['posY'] - (int) $chair['posY'];
                // Squared distance: the ordering is all that matters, and
                // this keeps it in integers.
                $pairs[] = [$dx * $dx + $dy * $dy, $s, $c];
            }
        }

        // Ties are broken by seat then chair order so the result is stable
        // rather than dependent on the sort implementation.
        usort(
            $pairs,
            function ($a, $b) {
                return $a[0] <=> $b[0] ?: ($a[1] <=> $b[1] ?: $a[2] <=> $b[2]);
            }
        );

        $takenSeat = [];
        $takenChair = [];
        $placed = [];
        $moved = 0;

        foreach ($pairs as $pair) {
            list($distance, $s, $c) = $pair;

            if (isset($takenSeat[$s]) || isset($takenChair[$c])) {
                continue;
            }

            $takenSeat[$s] = true;
            $takenChair[$c] = true;

            if ($distance > 0) {
                ++$moved;
            }

            $placed[$s] = [
                'gibbonPersonID' => $seats[$s]['gibbonPersonID'],
                'posX'           => (int) $chairs[$c]['posX'],
                'posY'           => (int) $chairs[$c]['posY'],
            ];
        }

        $unseated = [];

        foreach ($seats as $s => $seat) {
            if (!isset($takenSeat[$s])) {
                $unseated[] = $seat['gibbonPersonID'];
            }
        }

        // Keyed by the original seat index up to here, purely so the two
        // loops above can find each other; the caller wants a plain list.
        ksort($placed);

        return [
            'seats'    => array_values($placed),
            'moved'    => $moved,
            'unseated' => $unseated,
        ];
    }

    /**
     * Re-seats every plan on a layout whose furniture has just been
     * replaced, and reports what happened so the teacher can be told.
     *
     * **The caller must already be inside a transaction.** This does not
     * open one of its own on purpose: Connection counts transactions but
     * only ever reaches one, so an inner commit() here would commit the
     * caller's transaction early and the furniture replacement this
     * follows would be left half-applied if anything below then failed.
     *
     * @param string $seatingPlanRoomLayoutID The layout that changed.
     * @param array  $chairs                  The new chairs, with posX/posY.
     *
     * @return array ['moved' => int, 'unseated' => int, 'plans' => int]
     */
    public function resnapSeatsToChairs($seatingPlanRoomLayoutID, array $chairs): array
    {
        $data = ['seatingPlanRoomLayoutID' => $seatingPlanRoomLayoutID];
        $sql = "SELECT seatingPlanPlanID FROM seatingPlanPlan
            WHERE seatingPlanRoomLayoutID=:seatingPlanRoomLayoutID";

        $plans = $this->db()->select($sql, $data)->fetchAll(\PDO::FETCH_COLUMN);
        $totals = ['moved' => 0, 'unseated' => 0, 'plans' => count($plans)];

        // Nothing to snap to. In a room with no chairs the students stand
        // where they were put, so their positions are not stale - unseating
        // everyone here would throw away a plan that is still perfectly
        // good.
        if (empty($chairs)) {
            return $totals;
        }

        foreach ($plans as $seatingPlanPlanID) {
            $seats = $this->selectSeatsByPlan($seatingPlanPlanID);

            if (empty($seats)) {
                continue;
            }

            $result = self::matchSeatsToChairs($seats, $chairs);

            $this->db()->statement(
                'DELETE FROM seatingPlanSeat
                 WHERE seatingPlanPlanID=:seatingPlanPlanID',
                ['seatingPlanPlanID' => $seatingPlanPlanID]
            );

            foreach ($result['seats'] as $seat) {
                $this->db()->insert(
                    'INSERT INTO seatingPlanSeat
                        (seatingPlanPlanID, gibbonPersonID, posX, posY)
                     VALUES
                        (:seatingPlanPlanID, :gibbonPersonID, :posX, :posY)',
                    [
                        'seatingPlanPlanID' => $seatingPlanPlanID,
                        'gibbonPersonID'    => $seat['gibbonPersonID'],
                        'posX'              => $seat['posX'],
                        'posY'              => $seat['posY'],
                    ]
                );
            }

            $totals['moved'] += $result['moved'];
            $totals['unseated'] += count($result['unseated']);
        }

        return $totals;
    }

    /**
     * Every seat on a plan.
     *
     * @param string $seatingPlanPlanID The plan.
     *
     * @return array
     */
    public function selectSeatsByPlan($seatingPlanPlanID): array
    {
        $data = ['seatingPlanPlanID' => $seatingPlanPlanID];
        $sql = "SELECT gibbonPersonID, posX, posY
            FROM seatingPlanSeat
            WHERE seatingPlanPlanID=:seatingPlanPlanID";

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Checks what the client submitted before anything is written.
     *
     * A room with chairs in it seats students in chairs: only a student on
     * the room's own roster, exactly on a chair, is written, and open floor
     * is the gesture for taking someone out of a seat. A room with no chairs
     * drawn - a room nobody has furnished yet, which is every room until
     * somebody does - has no such anchor, so any position inside the room
     * counts and the floor is the plan. Two students may then share a spot,
     * which on open floor is merely untidy, whereas two students in one
     * chair is a contradiction.
     *
     * Pure, and separate from the write below, so a save can be found bad
     * before it has created anything - a layout made for a save that then
     * failed would be left behind empty.
     *
     * @param array $seats          Raw seats from the client.
     * @param array $validPersonIDs Everyone currently on the room's roster.
     * @param array $chairPositions Every chair's "x,y" position in the room,
     *                              empty when none are drawn.
     * @param int   $gridCols       Room width in cells.
     * @param int   $gridRows       Room depth in cells.
     *
     * @return array Clean seat rows, ready to write.
     *
     * @throws \InvalidArgumentException When a seat fails validation.
     */
    public static function validateSeats(
        array $seats,
        array $validPersonIDs,
        array $chairPositions,
        int $gridCols,
        int $gridRows
    ): array {
        $clean = [];
        $seenPerson = [];
        $seenChair = [];
        $freePlacement = empty($chairPositions);
        // A student tile is one whole cell, so the last position it can start
        // at and still be inside the room is one cell short of the far wall.
        $limitX = ($gridCols - 1) * FurnitureCatalogue::SUBDIVISIONS;
        $limitY = ($gridRows - 1) * FurnitureCatalogue::SUBDIVISIONS;
        // gibbonPersonID reaches here as a zerofilled string from a gateway
        // select, or as a plain integer from a test or another caller. Both
        // name the same person, so compare numerically rather than trusting
        // the formatting to match.
        $validPersonIDs = array_map('intval', $validPersonIDs);

        foreach ($seats as $index => $seat) {
            $gibbonPersonID = (string) ($seat['gibbonPersonID'] ?? '');
            $posX = (int) ($seat['posX'] ?? -1);
            $posY = (int) ($seat['posY'] ?? -1);
            $chairKey = $posX.','.$posY;

            if (!in_array((int) $gibbonPersonID, $validPersonIDs, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Seat %d is not on this room\'s roster.',
                    $index + 1
                ));
            }

            if ($freePlacement) {
                if ($posX < 0 || $posY < 0 || $posX > $limitX || $posY > $limitY) {
                    throw new \InvalidArgumentException(sprintf(
                        'Seat %d is outside this room.',
                        $index + 1
                    ));
                }
            } elseif (!in_array($chairKey, $chairPositions, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Seat %d is not an actual chair in this room.',
                    $index + 1
                ));
            }

            if (isset($seenPerson[$gibbonPersonID])) {
                throw new \InvalidArgumentException(
                    'The same student was submitted twice.'
                );
            }

            if (!$freePlacement && isset($seenChair[$chairKey])) {
                throw new \InvalidArgumentException(
                    'Two students were put in the same chair.'
                );
            }

            $seenPerson[$gibbonPersonID] = true;
            $seenChair[$chairKey] = true;

            $clean[] = [
                'gibbonPersonID' => $gibbonPersonID,
                'posX'           => $posX,
                'posY'           => $posY,
            ];
        }

        return $clean;
    }

    /**
     * Replaces every seat on a plan in one transaction.
     *
     * @param string $seatingPlanPlanID The plan.
     * @param array  $clean             Seats from validateSeats(), which is
     *                                  where everything about them is
     *                                  checked - this only writes.
     *
     * @return int The number of seats written.
     */
    public function replaceSeatsForPlan($seatingPlanPlanID, array $clean): int
    {
        $this->db()->beginTransaction();

        try {
            $this->db()->statement(
                "DELETE FROM seatingPlanSeat
                    WHERE seatingPlanPlanID=:seatingPlanPlanID",
                ['seatingPlanPlanID' => $seatingPlanPlanID]
            );

            // $this->insert() always targets this gateway's own table,
            // seatingPlanPlan; a seat is a row in seatingPlanSeat, so it is
            // written with its own statement rather than that helper.
            foreach ($clean as $seat) {
                $this->db()->insert(
                    "INSERT INTO seatingPlanSeat
                        (seatingPlanPlanID, gibbonPersonID, posX, posY)
                        VALUES
                        (:seatingPlanPlanID, :gibbonPersonID, :posX, :posY)",
                    [
                        'seatingPlanPlanID' => $seatingPlanPlanID,
                        'gibbonPersonID'    => $seat['gibbonPersonID'],
                        'posX'              => $seat['posX'],
                        'posY'              => $seat['posY'],
                    ]
                );
            }

            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }

        return count($clean);
    }
}
