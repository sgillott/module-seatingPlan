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
 * The students to seat: everyone enrolled across every class sharing a room.
 *
 * Core's CourseClassPersonGateway::selectStudentsByClass() takes one class at
 * a time and does not return gender or house, so a room with several co-taught
 * classes needs a query of its own. It follows the same shape core uses to
 * exclude a student who is absent that day: a LEFT JOIN to
 * gibbonTTDayRowClassException, generalised to one (class, timetabled slot)
 * pair per class in the room, since each co-taught class has its own
 * gibbonTTDayRowClassID on any given date.
 */
class StudentRosterGateway extends Gateway
{
    /**
     * Every student across a set of classes, minus anyone excused that day.
     *
     * @param array  $courseClassIDs The classes sharing the room.
     * @param array  $exceptionPairs One ['gibbonCourseClassID',
     *                               'gibbonTTDayRowClassID'] pair per class,
     *                               for the date being viewed. Pass an empty
     *                               array to skip exception filtering
     *                               entirely, e.g. when validating a save
     *                               against "anyone currently enrolled"
     *                               rather than one specific day.
     * @param string $date              Y-m-d. Defaults to today.
     * @param string $gibbonSchoolYearID Scopes the form group / year group
     *                                   enrolment joined in for badges. A
     *                                   student with no enrolment row for
     *                                   this year (or when this is left
     *                                   empty) simply gets no form/year
     *                                   group value, rather than an error.
     *
     * @return array Each row also carries `courseClassIDs`, a comma-joined
     *                list of every class in $courseClassIDs the student
     *                actually belongs to - almost always exactly one, but
     *                never guessed at when it is not. A caller that needs a
     *                single class for that student (register mode, writing
     *                attendance) must explode this and treat more than one
     *                entry as ambiguous, not silently pick one.
     */
    public function selectRoster(
        array $courseClassIDs,
        array $exceptionPairs = [],
        $date = null,
        $gibbonSchoolYearID = null
    ): array {
        if (empty($courseClassIDs)) {
            return [];
        }

        $data = [
            // Unpadded, to match the CAST on the column below. Callers pass
            // these either straight from the database (zerofilled) or from
            // a canonical class list (not), and the query must not care.
            'classList'         => implode(',', array_map('intval', $courseClassIDs)),
            'today'             => $date ?: date('Y-m-d'),
            'gibbonSchoolYearID' => $gibbonSchoolYearID ?: '',
        ];

        $sql = "SELECT
                gibbonCourseClassPerson.gibbonPersonID,
                gibbonPerson.surname,
                gibbonPerson.preferredName,
                gibbonPerson.image_240,
                gibbonPerson.gender,
                gibbonPerson.gibbonHouseID,
                gibbonHouse.name AS houseName,
                gibbonPerson.privacy,
                gibbonFormGroup.nameShort AS formGroup,
                gibbonYearGroup.nameShort AS yearGroup,
                GROUP_CONCAT(DISTINCT gibbonCourseClassPerson.gibbonCourseClassID
                    SEPARATOR ',') AS courseClassIDs
            FROM gibbonCourseClassPerson
            JOIN gibbonPerson
                ON (gibbonPerson.gibbonPersonID
                    =gibbonCourseClassPerson.gibbonPersonID)
            LEFT JOIN gibbonHouse
                ON (gibbonHouse.gibbonHouseID=gibbonPerson.gibbonHouseID)
            LEFT JOIN gibbonStudentEnrolment
                ON (gibbonStudentEnrolment.gibbonPersonID
                        =gibbonPerson.gibbonPersonID
                    AND gibbonStudentEnrolment.gibbonSchoolYearID
                        =:gibbonSchoolYearID)
            LEFT JOIN gibbonFormGroup
                ON (gibbonFormGroup.gibbonFormGroupID
                    =gibbonStudentEnrolment.gibbonFormGroupID)
            LEFT JOIN gibbonYearGroup
                ON (gibbonYearGroup.gibbonYearGroupID
                    =gibbonStudentEnrolment.gibbonYearGroupID)";

        if (!empty($exceptionPairs)) {
            $pairSelects = [];

            foreach (array_values($exceptionPairs) as $i => $pair) {
                $data["pairClass{$i}"] = $pair['gibbonCourseClassID'];
                $data["pairSlot{$i}"] = $pair['gibbonTTDayRowClassID'];
                $pairSelects[] = "SELECT :pairClass{$i} AS gibbonCourseClassID,
                    :pairSlot{$i} AS gibbonTTDayRowClassID";
            }

            $sql .= " LEFT JOIN (".implode(' UNION ALL ', $pairSelects)."
                    ) AS slotForClass
                ON (slotForClass.gibbonCourseClassID
                    =gibbonCourseClassPerson.gibbonCourseClassID)
                LEFT JOIN gibbonTTDayRowClassException
                    ON (gibbonTTDayRowClassException.gibbonTTDayRowClassID
                            =slotForClass.gibbonTTDayRowClassID
                        AND gibbonTTDayRowClassException.gibbonPersonID
                            =gibbonCourseClassPerson.gibbonPersonID)";
        }

        // CAST, because gibbonCourseClassID is a zerofilled column that
        // stringifies as "00000897" while a canonical class list holds
        // "897" - FIND_IN_SET compares strings, so without this the two
        // never match. See ClassListNormalizer.
        $sql .= " WHERE FIND_IN_SET(
                    CAST(gibbonCourseClassPerson.gibbonCourseClassID AS UNSIGNED),
                    :classList)
                AND gibbonPerson.status='Full'
                AND gibbonCourseClassPerson.role='Student'
                AND (gibbonPerson.dateStart IS NULL
                    OR gibbonPerson.dateStart<=:today)
                AND (gibbonPerson.dateEnd IS NULL
                    OR gibbonPerson.dateEnd>=:today)
            GROUP BY gibbonCourseClassPerson.gibbonPersonID";

        if (!empty($exceptionPairs)) {
            $sql .= " HAVING COUNT(gibbonTTDayRowClassExceptionID) = 0";
        }

        $sql .= " ORDER BY gibbonPerson.surname, gibbonPerson.preferredName";

        return $this->db()->select($sql, $data)->fetchAll();
    }
}
