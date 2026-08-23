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
 * One slot of the timetable: a room, at a time, on a date.
 *
 * Gibbon has no single table for this. A room comes from gibbonTTDayRowClass,
 * unless a one-off change moved the lesson, in which case gibbonTTSpaceChange
 * wins for that date. Both queries here account for that, so a lesson moved to
 * another room for one day is found in the room it actually happens in.
 */
class TimetableSlotGateway extends Gateway
{
    /**
     * The room and timing of one timetabled class on one date.
     *
     * @param string $gibbonTTDayRowClassID The timetabled class.
     * @param string $date                  Y-m-d.
     *
     * @return array Empty when the class does not run on that date.
     */
    public function getSlot($gibbonTTDayRowClassID, $date): array
    {
        $data = [
            'gibbonTTDayRowClassID' => $gibbonTTDayRowClassID,
            'date'                  => $date,
        ];
        $sql = "SELECT
                slot.gibbonTTDayRowClassID,
                slot.gibbonTTDayID,
                slot.gibbonTTColumnRowID,
                slot.gibbonCourseClassID,
                COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID) AS gibbonSpaceID,
                gibbonSpace.name AS spaceName,
                gibbonSpace.capacity,
                gibbonTTColumnRow.name AS periodName,
                gibbonTTColumnRow.timeStart,
                gibbonTTColumnRow.timeEnd,
                gibbonTTDayDate.date,
                gibbonCourse.gibbonSchoolYearID,
                moved.gibbonTTSpaceChangeID AS spaceChanged
            FROM gibbonTTDayRowClass AS slot
            JOIN gibbonTTColumnRow
                ON (gibbonTTColumnRow.gibbonTTColumnRowID=slot.gibbonTTColumnRowID)
            JOIN gibbonTTDayDate
                ON (gibbonTTDayDate.gibbonTTDayID=slot.gibbonTTDayID
                    AND gibbonTTDayDate.date=:date)
            JOIN gibbonCourseClass
                ON (gibbonCourseClass.gibbonCourseClassID=slot.gibbonCourseClassID)
            JOIN gibbonCourse
                ON (gibbonCourse.gibbonCourseID=gibbonCourseClass.gibbonCourseID)
            LEFT JOIN gibbonTTSpaceChange AS moved
                ON (moved.gibbonTTDayRowClassID=slot.gibbonTTDayRowClassID
                    AND moved.date=gibbonTTDayDate.date)
            LEFT JOIN gibbonSpace
                ON (gibbonSpace.gibbonSpaceID
                    =COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID))
            WHERE slot.gibbonTTDayRowClassID=:gibbonTTDayRowClassID";

        return $this->db()->selectOne($sql, $data) ?: [];
    }

    /**
     * Every period one class runs on one day, in time order.
     *
     * Used to find a multi-period lesson's full run of periods: the caller
     * filters this to the room it cares about, then walks outward from the
     * period it started at while each neighbour's start time meets the
     * previous one's end time. There is no explicit period-sequence column
     * on gibbonTTColumnRow, only timeStart/timeEnd, so time-chaining is the
     * only ordering available and the correct one to use.
     *
     * @param string $gibbonCourseClassID The class.
     * @param string $gibbonTTDayID       The timetable day.
     * @param string $date                Y-m-d, for resolving a same-day
     *                                     room change.
     *
     * @return array
     */
    public function selectClassSlotsOnDay(
        $gibbonCourseClassID,
        $gibbonTTDayID,
        $date
    ): array {
        $data = [
            'gibbonCourseClassID' => $gibbonCourseClassID,
            'gibbonTTDayID'       => $gibbonTTDayID,
            'date'                => $date,
        ];
        $sql = "SELECT
                slot.gibbonTTDayRowClassID,
                slot.gibbonTTColumnRowID,
                COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID) AS gibbonSpaceID,
                gibbonTTColumnRow.timeStart,
                gibbonTTColumnRow.timeEnd
            FROM gibbonTTDayRowClass AS slot
            JOIN gibbonTTColumnRow
                ON (gibbonTTColumnRow.gibbonTTColumnRowID=slot.gibbonTTColumnRowID)
            LEFT JOIN gibbonTTSpaceChange AS moved
                ON (moved.gibbonTTDayRowClassID=slot.gibbonTTDayRowClassID
                    AND moved.date=:date)
            WHERE slot.gibbonCourseClassID=:gibbonCourseClassID
                AND slot.gibbonTTDayID=:gibbonTTDayID
            ORDER BY gibbonTTColumnRow.timeStart";

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * The contiguous run of periods, in one room, that includes a given
     * period - the "every period this student's own class occupies,
     * back-to-back, in this room, today" a register save writes attendance
     * for.
     *
     * A pure function over already-fetched rows, not a query, so it can be
     * tested directly against constructed cases without a database: a
     * single-period class, a double-period contiguous class, and a
     * same-room-same-day-but-not-contiguous class all need their own
     * assertion.
     *
     * @param array  $daySlots            Rows from selectClassSlotsOnDay(),
     *                                    in their returned (timeStart) order.
     * @param string $gibbonSpaceID       The room being viewed.
     * @param string $gibbonTTColumnRowID The period being viewed.
     *
     * @return array gibbonTTDayRowClassID values, in time order. Empty if
     *               the class does not actually meet in that room at that
     *               period.
     */
    public static function findContiguousRun(
        array $daySlots,
        $gibbonSpaceID,
        $gibbonTTColumnRowID
    ): array {
        $roomSlots = array_values(array_filter(
            $daySlots,
            function ($row) use ($gibbonSpaceID) {
                return $row['gibbonSpaceID'] == $gibbonSpaceID;
            }
        ));

        $startIndex = null;

        foreach ($roomSlots as $i => $row) {
            if ($row['gibbonTTColumnRowID'] == $gibbonTTColumnRowID) {
                $startIndex = $i;
                break;
            }
        }

        if ($startIndex === null) {
            return [];
        }

        $periods = [$roomSlots[$startIndex]['gibbonTTDayRowClassID']];

        for ($i = $startIndex - 1; $i >= 0; $i--) {
            if ($roomSlots[$i]['timeEnd'] !== $roomSlots[$i + 1]['timeStart']) {
                break;
            }
            array_unshift($periods, $roomSlots[$i]['gibbonTTDayRowClassID']);
        }

        for ($i = $startIndex + 1; $i < count($roomSlots); $i++) {
            if ($roomSlots[$i]['timeStart'] !== $roomSlots[$i - 1]['timeEnd']) {
                break;
            }
            $periods[] = $roomSlots[$i]['gibbonTTDayRowClassID'];
        }

        return $periods;
    }

    /**
     * Every class sharing one room at one time on one date.
     *
     * Co-teaching is normal rather than exceptional here: in the live data,
     * eighteen per cent of room-and-period slots hold more than one class.
     *
     * @param string $gibbonSpaceID       The room.
     * @param string $gibbonTTDayID       The timetable day.
     * @param string $gibbonTTColumnRowID The period.
     * @param string $date                Y-m-d.
     *
     * @return array
     */
    public function selectClassesInSlot(
        $gibbonSpaceID,
        $gibbonTTDayID,
        $gibbonTTColumnRowID,
        $date
    ): array {
        $data = [
            'gibbonSpaceID'       => $gibbonSpaceID,
            'gibbonTTDayID'       => $gibbonTTDayID,
            'gibbonTTColumnRowID' => $gibbonTTColumnRowID,
            'date'                => $date,
        ];
        $sql = "SELECT
                slot.gibbonTTDayRowClassID,
                slot.gibbonCourseClassID,
                gibbonCourse.nameShort AS courseNameShort,
                gibbonCourseClass.nameShort AS classNameShort,
                gibbonCourse.name AS courseName,
                gibbonCourseClass.attendance
            FROM gibbonTTDayRowClass AS slot
            JOIN gibbonTTDayDate
                ON (gibbonTTDayDate.gibbonTTDayID=slot.gibbonTTDayID
                    AND gibbonTTDayDate.date=:date)
            JOIN gibbonCourseClass
                ON (gibbonCourseClass.gibbonCourseClassID=slot.gibbonCourseClassID)
            JOIN gibbonCourse
                ON (gibbonCourse.gibbonCourseID=gibbonCourseClass.gibbonCourseID)
            LEFT JOIN gibbonTTSpaceChange AS moved
                ON (moved.gibbonTTDayRowClassID=slot.gibbonTTDayRowClassID
                    AND moved.date=gibbonTTDayDate.date)
            WHERE slot.gibbonTTDayID=:gibbonTTDayID
                AND slot.gibbonTTColumnRowID=:gibbonTTColumnRowID
                AND COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID)=:gibbonSpaceID
            GROUP BY slot.gibbonTTDayRowClassID
            ORDER BY gibbonCourse.nameShort, gibbonCourseClass.nameShort";

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * For a day's worth of timetabled classes: how many classes share each
     * room at that time, whether the viewer has a layout for the room, and
     * whether anyone has already been seated there.
     *
     * One query for the whole day rather than one per lesson.
     *
     * @param array  $gibbonTTDayRowClassIDs The day's classes.
     * @param string $date                   Y-m-d.
     * @param string $gibbonPersonID         The viewer.
     *
     * @return array Keyed by gibbonTTDayRowClassID.
     */
    public function selectSlotSummaries(
        array $gibbonTTDayRowClassIDs,
        $date,
        $gibbonPersonID
    ): array {
        if (empty($gibbonTTDayRowClassIDs)) {
            return [];
        }

        $data = [
            'idList'         => implode(',', $gibbonTTDayRowClassIDs),
            'date'           => $date,
            'gibbonPersonID' => $gibbonPersonID,
        ];
        $sql = "SELECT
                slot.gibbonTTDayRowClassID,
                COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID) AS gibbonSpaceID,
                (SELECT COUNT(DISTINCT peer.gibbonCourseClassID)
                    FROM gibbonTTDayRowClass AS peer
                    LEFT JOIN gibbonTTSpaceChange AS peerMoved
                        ON (peerMoved.gibbonTTDayRowClassID=peer.gibbonTTDayRowClassID
                            AND peerMoved.date=:date)
                    WHERE peer.gibbonTTDayID=slot.gibbonTTDayID
                        AND peer.gibbonTTColumnRowID=slot.gibbonTTColumnRowID
                        AND COALESCE(peerMoved.gibbonSpaceID, peer.gibbonSpaceID)
                            =COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID)
                ) AS classCount,
                (SELECT COUNT(*)
                    FROM seatingPlanRoomLayout AS layout
                    WHERE layout.gibbonSpaceID
                            =COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID)
                        AND (layout.gibbonPersonIDOwner=:gibbonPersonID
                            OR layout.shared='Y')
                ) AS layoutCount,
                (SELECT COUNT(*)
                    FROM seatingPlanSeat AS seat
                    JOIN seatingPlanPlan AS plan
                        ON (plan.seatingPlanPlanID=seat.seatingPlanPlanID)
                    JOIN seatingPlanRoomLayout AS planLayout
                        ON (planLayout.seatingPlanRoomLayoutID
                            =plan.seatingPlanRoomLayoutID)
                    WHERE planLayout.gibbonSpaceID
                            =COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID)
                        AND (planLayout.gibbonPersonIDOwner=:gibbonPersonID
                            OR planLayout.shared='Y')
                        AND FIND_IN_SET(
                            CAST(slot.gibbonCourseClassID AS UNSIGNED),
                            plan.classList)
                ) AS seatedCount
            FROM gibbonTTDayRowClass AS slot
            LEFT JOIN gibbonTTSpaceChange AS moved
                ON (moved.gibbonTTDayRowClassID=slot.gibbonTTDayRowClassID
                    AND moved.date=:date)
            WHERE FIND_IN_SET(slot.gibbonTTDayRowClassID, :idList)";

        $summaries = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $summary) {
            $summaries[$summary['gibbonTTDayRowClassID']] = $summary;
        }

        return $summaries;
    }

    /**
     * The timetabled classes a person is covering on one date.
     *
     * A substitute is not enrolled in the class, so their lessons never appear
     * in the person's own timetable. They are found here instead.
     *
     * @param string $gibbonPersonID     The covering teacher.
     * @param string $date               Y-m-d.
     * @param string $gibbonSchoolYearID The school year.
     *
     * @return array
     */
    public function selectCoveredSlots($gibbonPersonID, $date, $gibbonSchoolYearID): array
    {
        $data = [
            'gibbonPersonID'     => $gibbonPersonID,
            'date'               => $date,
            'gibbonSchoolYearID' => $gibbonSchoolYearID,
        ];
        $sql = "SELECT
                slot.gibbonTTDayRowClassID,
                slot.gibbonTTDayID,
                slot.gibbonTTColumnRowID,
                slot.gibbonCourseClassID,
                gibbonTTColumnRow.name AS period,
                gibbonTTColumnRow.timeStart,
                gibbonTTColumnRow.timeEnd,
                gibbonCourse.nameShort AS courseNameShort,
                gibbonCourseClass.nameShort AS classNameShort,
                gibbonSpace.name AS roomName,
                CONCAT(absent.preferredName, ' ', absent.surname) AS coveringFor
            FROM gibbonStaffCoverage AS cover
            JOIN gibbonStaffCoverageDate AS coverDate
                ON (coverDate.gibbonStaffCoverageID=cover.gibbonStaffCoverageID
                    AND coverDate.foreignTable='gibbonTTDayRowClass'
                    AND coverDate.date=:date)
            JOIN gibbonTTDayRowClass AS slot
                ON (slot.gibbonTTDayRowClassID=coverDate.foreignTableID)
            JOIN gibbonTTColumnRow
                ON (gibbonTTColumnRow.gibbonTTColumnRowID=slot.gibbonTTColumnRowID)
            JOIN gibbonCourseClass
                ON (gibbonCourseClass.gibbonCourseClassID=slot.gibbonCourseClassID)
            JOIN gibbonCourse
                ON (gibbonCourse.gibbonCourseID=gibbonCourseClass.gibbonCourseID)
            LEFT JOIN gibbonTTSpaceChange AS moved
                ON (moved.gibbonTTDayRowClassID=slot.gibbonTTDayRowClassID
                    AND moved.date=coverDate.date)
            LEFT JOIN gibbonSpace
                ON (gibbonSpace.gibbonSpaceID
                    =COALESCE(moved.gibbonSpaceID, slot.gibbonSpaceID))
            LEFT JOIN gibbonPerson AS absent
                ON (absent.gibbonPersonID=cover.gibbonPersonID)
            WHERE cover.gibbonPersonIDCoverage=:gibbonPersonID
                AND cover.gibbonSchoolYearID=:gibbonSchoolYearID
                AND cover.status='Accepted'
            ORDER BY gibbonTTColumnRow.timeStart";

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * The day to show by default: today, unless there is nothing timetabled
     * for this person today, in which case the most recent day that did have
     * something, so the page never opens onto an empty table for no reason.
     *
     * Only used when the viewer has not chosen a date themselves - picking an
     * empty day on purpose still shows that day, empty.
     *
     * @param string $gibbonPersonID The teacher.
     * @param string $date           Y-m-d, normally today.
     *
     * @return string Y-m-d. The given date, unchanged, if nothing earlier
     *                is found either.
     */
    public function selectDefaultDate($gibbonPersonID, $date): string
    {
        $data = ['gibbonPersonID' => $gibbonPersonID, 'date' => $date];
        $sql = "SELECT MAX(d) FROM (
                SELECT gibbonTTDayDate.date AS d
                FROM gibbonCourseClassPerson
                JOIN gibbonTTDayRowClass
                    ON (gibbonTTDayRowClass.gibbonCourseClassID
                        =gibbonCourseClassPerson.gibbonCourseClassID)
                JOIN gibbonTTDayDate
                    ON (gibbonTTDayDate.gibbonTTDayID=gibbonTTDayRowClass.gibbonTTDayID)
                LEFT JOIN gibbonTTDayRowClassException
                    ON (gibbonTTDayRowClassException.gibbonTTDayRowClassID
                            =gibbonTTDayRowClass.gibbonTTDayRowClassID
                        AND gibbonTTDayRowClassException.gibbonPersonID
                            =gibbonCourseClassPerson.gibbonPersonID)
                WHERE gibbonCourseClassPerson.gibbonPersonID=:gibbonPersonID
                    AND NOT gibbonCourseClassPerson.role LIKE '% - Left'
                    AND gibbonTTDayDate.date<=:date
                    AND gibbonTTDayRowClassException.gibbonTTDayRowClassExceptionID
                        IS NULL
                UNION ALL
                SELECT coverDate.date AS d
                FROM gibbonStaffCoverage AS cover
                JOIN gibbonStaffCoverageDate AS coverDate
                    ON (coverDate.gibbonStaffCoverageID=cover.gibbonStaffCoverageID
                        AND coverDate.foreignTable='gibbonTTDayRowClass')
                WHERE cover.gibbonPersonIDCoverage=:gibbonPersonID
                    AND cover.status='Accepted'
                    AND coverDate.date<=:date
            ) AS lessonDates";

        $found = $this->db()->selectOne($sql, $data);

        return $found ?: $date;
    }

    /**
     * Whether a person teaches, assists or covers any of the given classes.
     *
     * @param array  $courseClassIDs The classes in the room.
     * @param string $gibbonPersonID The person.
     *
     * @return bool
     */
    public function isStaffOfAnyClass(array $courseClassIDs, $gibbonPersonID): bool
    {
        if (empty($courseClassIDs)) {
            return false;
        }

        // A bound IN list rather than FIND_IN_SET: gibbonCourseClassID is a
        // zerofilled column, so a string-list match only works when the
        // caller's IDs carry the same padding, and a canonical class list
        // deliberately does not. Comparing as numbers sidesteps that
        // entirely, and uses the index while it is at it.
        $data = ['gibbonPersonID' => $gibbonPersonID];
        $placeholders = [];

        foreach (array_values($courseClassIDs) as $i => $courseClassID) {
            $placeholders[] = ':classID'.$i;
            $data['classID'.$i] = (int) $courseClassID;
        }

        $sql = "SELECT COUNT(*)
            FROM gibbonCourseClassPerson
            WHERE gibbonCourseClassID IN (".implode(',', $placeholders).")
                AND gibbonPersonID=:gibbonPersonID
                AND role IN ('Teacher', 'Assistant', 'Technician')";

        return (int) $this->db()->selectOne($sql, $data) > 0;
    }
}
