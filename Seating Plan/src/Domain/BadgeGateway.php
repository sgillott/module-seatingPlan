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

use Gibbon\Domain\Gateway;

/**
 * The two pieces of badge data core has no ready gateway for: a house's
 * badge colour (core's own gibbonHouse has no colour column, only a logo)
 * and a student's CAT4 "Mean SAS" score (core's external assessment tables
 * are generic, keyed by field name rather than a fixed column).
 */
class BadgeGateway extends Gateway
{
    /**
     * Every active house, with its badge colour if one has been set.
     *
     * @return array
     */
    public function selectHouses(): array
    {
        $sql = "SELECT gibbonHouse.gibbonHouseID, gibbonHouse.name,
                seatingPlanHouseColour.colour
            FROM gibbonHouse
            LEFT JOIN seatingPlanHouseColour
                ON (seatingPlanHouseColour.gibbonHouseID
                    =gibbonHouse.gibbonHouseID)
            ORDER BY gibbonHouse.name";

        return $this->db()->select($sql)->fetchAll();
    }

    /**
     * Every house's badge colour, keyed by gibbonHouseID. Houses with no
     * colour set yet are simply absent from the result - a badge for such a
     * house renders with no colour of its own, rather than a guessed one.
     *
     * @return array
     */
    public function selectHouseColours(): array
    {
        $sql = "SELECT gibbonHouseID, colour FROM seatingPlanHouseColour";
        $colours = [];

        foreach ($this->db()->select($sql)->fetchAll() as $row) {
            $colours[$row['gibbonHouseID']] = $row['colour'];
        }

        return $colours;
    }

    /**
     * Sets one house's badge colour, overwriting any previous value.
     *
     * seatingPlanHouseColour's primary key is gibbonHouseID itself, a
     * natural key rather than an auto-increment one - TableAware's own
     * insertAndUpdate() strips the primary key column out before inserting
     * (built for auto-increment keys, where the database assigns it), which
     * would silently drop gibbonHouseID here. A raw upsert is used instead,
     * the same way SeatingPlanGateway writes seatingPlanSeat rows.
     *
     * @param string $gibbonHouseID The house.
     * @param string $colour        A CSS colour, e.g. '#2f7d4e'.
     *
     * @return void
     */
    public function saveHouseColour($gibbonHouseID, $colour): void
    {
        $sql = "INSERT INTO seatingPlanHouseColour (gibbonHouseID, colour)
            VALUES (:gibbonHouseID, :colour)
            ON DUPLICATE KEY UPDATE colour=VALUES(colour)";

        $this->db()->insert(
            $sql,
            ['gibbonHouseID' => $gibbonHouseID, 'colour' => $colour]
        );
    }

    /**
     * A teacher's own four corner badge slots - one setting per teacher,
     * applied to every room they open, not saved per plan. Set it once and
     * it follows them everywhere, the same way house colours are one
     * setting for the whole school rather than one per plan.
     *
     * @param string $gibbonPersonID The teacher.
     *
     * @return array Always exactly 4 entries, a source key or '' for an
     *                empty slot. No row yet, or malformed JSON, both
     *                degrade to four empty slots rather than an error.
     */
    public function selectBadgeSlots($gibbonPersonID): array
    {
        $raw = $this->db()->selectOne(
            "SELECT badges FROM seatingPlanBadgeConfig WHERE gibbonPersonID=:gibbonPersonID",
            ['gibbonPersonID' => $gibbonPersonID]
        );

        $decoded = !empty($raw) ? json_decode($raw, true) : null;

        $slots = is_array($decoded) && is_array($decoded['badges'] ?? null)
            ? array_values($decoded['badges'])
            : [];

        $slots = array_pad(array_slice($slots, 0, 4), 4, '');

        return array_map(
            function ($slot) {
                return is_string($slot) ? $slot : '';
            },
            $slots
        );
    }

    /**
     * Saves a teacher's own four badge slots, overwriting any previous set.
     *
     * @param string $gibbonPersonID The teacher.
     * @param array  $slots          Exactly 4 source keys, '' for empty.
     *
     * @return void
     */
    public function saveBadgeSlots($gibbonPersonID, array $slots): void
    {
        $slots = array_pad(array_slice(array_values($slots), 0, 4), 4, '');

        $sql = "INSERT INTO seatingPlanBadgeConfig (gibbonPersonID, badges)
            VALUES (:gibbonPersonID, :badges)
            ON DUPLICATE KEY UPDATE badges=VALUES(badges)";

        $this->db()->insert(
            $sql,
            [
                'gibbonPersonID' => $gibbonPersonID,
                'badges'         => json_encode(['badges' => $slots]),
            ]
        );
    }

    /**
     * The most recent CAT4 "Mean SAS" score for each of a set of students.
     *
     * CAT results live in core's generic external-assessment tables, one row
     * per field per sitting (gibbonExternalAssessmentStudentEntry), rather
     * than a fixed column - looked up by the field's own name, not a
     * hardcoded ID, since a field's ID is assigned per install and is not
     * guaranteed to match this development database's. A student with more
     * than one sitting on record gets their most recent one.
     *
     * @param array $gibbonPersonIDs The students to look up.
     *
     * @return array Keyed by gibbonPersonID, each a
     *                ['value' => string, 'lowestAcceptable' => ?string]
     *                pair - the raw scale value (e.g. '112') and the CAT
     *                scale's own lowestAcceptable, kept for completeness
     *                even though this badge is shown neutral, not
     *                threshold-coloured.
     */
    public function selectCATScores(array $gibbonPersonIDs): array
    {
        if (empty($gibbonPersonIDs)) {
            return [];
        }

        $data = ['gibbonPersonIDList' => implode(',', $gibbonPersonIDs)];
        $sql = "SELECT
                student.gibbonPersonID,
                scaleGrade.value,
                scale.lowestAcceptable,
                student.date
            FROM gibbonExternalAssessmentStudent AS student
            JOIN gibbonExternalAssessmentStudentEntry AS entry
                ON (entry.gibbonExternalAssessmentStudentID
                    =student.gibbonExternalAssessmentStudentID)
            JOIN gibbonExternalAssessmentField AS field
                ON (field.gibbonExternalAssessmentFieldID
                    =entry.gibbonExternalAssessmentFieldID)
            JOIN gibbonScaleGrade AS scaleGrade
                ON (scaleGrade.gibbonScaleGradeID=entry.gibbonScaleGradeID)
            JOIN gibbonScale AS scale
                ON (scale.gibbonScaleID=field.gibbonScaleID)
            WHERE FIND_IN_SET(student.gibbonPersonID, :gibbonPersonIDList)
                AND field.name='Mean SAS'
                AND scale.name='Cognitive Abilities Test'
            ORDER BY student.gibbonPersonID, student.date DESC";

        $scores = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            // ORDER BY ... date DESC means the first row seen for a student
            // is their most recent sitting; later duplicates are ignored.
            if (isset($scores[$row['gibbonPersonID']])) {
                continue;
            }

            $scores[$row['gibbonPersonID']] = [
                'value'            => $row['value'],
                'lowestAcceptable' => $row['lowestAcceptable'],
            ];
        }

        return $scores;
    }
}
