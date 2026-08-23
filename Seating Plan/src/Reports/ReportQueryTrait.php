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

namespace Gibbon\Module\SeatingPlan\Reports;

use Aura\SqlQuery\Common\SelectInterface;

/**
 * The joins and filters both report queries share.
 *
 * A reward tally row and a room-exit row carry the same context columns -
 * student, class, room, period, recording teacher - so a report over either
 * hangs the same core tables off it and offers the same filters. Only the
 * table being reported on and its date expression differ, and both are
 * passed in.
 */
trait ReportQueryTrait
{
    /**
     * Hangs the core tables a report needs off the fact table, all as left
     * joins: a row whose class is 0 (a student in more than one of a room's
     * co-taught classes, so not attributable to either) must still appear
     * in a student or year-group report, and would vanish from an inner
     * join to gibbonCourseClass.
     *
     * @param SelectInterface $query The query, already FROM the fact table
     *                               aliased as `r`.
     *
     * @return SelectInterface
     */
    protected function joinReportTables(SelectInterface $query): SelectInterface
    {
        return $query
            ->innerJoin(
                'gibbonPerson AS student',
                'student.gibbonPersonID=r.gibbonPersonID'
            )
            ->leftJoin(
                'gibbonStudentEnrolment AS enrolment',
                'enrolment.gibbonPersonID=r.gibbonPersonID
                    AND enrolment.gibbonSchoolYearID=r.gibbonSchoolYearID'
            )
            ->leftJoin(
                'gibbonFormGroup AS formGroup',
                'formGroup.gibbonFormGroupID=enrolment.gibbonFormGroupID'
            )
            ->leftJoin(
                'gibbonYearGroup AS yearGroup',
                'yearGroup.gibbonYearGroupID=enrolment.gibbonYearGroupID'
            )
            ->leftJoin(
                'gibbonCourseClass AS courseClass',
                'courseClass.gibbonCourseClassID=r.gibbonCourseClassID'
            )
            ->leftJoin(
                'gibbonCourse AS course',
                'course.gibbonCourseID=courseClass.gibbonCourseID'
            )
            ->leftJoin(
                'gibbonPerson AS teacher',
                'teacher.gibbonPersonID=r.gibbonPersonIDCreator'
            )
            ->leftJoin(
                'gibbonSpace AS space',
                'space.gibbonSpaceID=r.gibbonSpaceID'
            );
    }

    /**
     * Applies the report's own filters. Every one is optional; an empty
     * value simply does not narrow the result.
     *
     * @param SelectInterface $query      The query.
     * @param array           $filters    Any of dateStart, dateEnd,
     *                                    gibbonPersonID, gibbonYearGroupID,
     *                                    gibbonFormGroupID,
     *                                    gibbonCourseClassID,
     *                                    gibbonCourseID,
     *                                    gibbonPersonIDCreator,
     *                                    gibbonSpaceID.
     * @param string          $dateColumn The fact table's date expression.
     *
     * @return SelectInterface
     */
    protected function filterReport(
        SelectInterface $query,
        array $filters,
        $dateColumn
    ): SelectInterface {
        if (!empty($filters['dateStart'])) {
            $query->where($dateColumn.' >= :dateStart')
                ->bindValue('dateStart', $filters['dateStart']);
        }

        if (!empty($filters['dateEnd'])) {
            $query->where($dateColumn.' <= :dateEnd')
                ->bindValue('dateEnd', $filters['dateEnd']);
        }

        $columns = [
            'gibbonPersonID'        => 'r.gibbonPersonID',
            'gibbonPersonIDCreator' => 'r.gibbonPersonIDCreator',
            'gibbonCourseClassID'   => 'r.gibbonCourseClassID',
            'gibbonSpaceID'         => 'r.gibbonSpaceID',
            'gibbonCourseID'        => 'course.gibbonCourseID',
            'gibbonYearGroupID'     => 'enrolment.gibbonYearGroupID',
            'gibbonFormGroupID'     => 'enrolment.gibbonFormGroupID',
        ];

        foreach ($columns as $name => $column) {
            if (!empty($filters[$name])) {
                $query->where($column.' = :'.$name)
                    ->bindValue($name, $filters[$name]);
            }
        }

        return $query;
    }

    /**
     * Narrows a report to what a viewer holding only Reports_my may see:
     * anything recorded in a class they teach, plus anything they recorded
     * themselves.
     *
     * The second half matters because a point given to a student who is in
     * more than one of a room's co-taught classes is filed against class 0,
     * and would otherwise be invisible to the very teacher who gave it.
     *
     * @param SelectInterface $query          The query.
     * @param string          $gibbonPersonID The viewer.
     *
     * @return SelectInterface
     */
    protected function scopeReportToOwnClasses(
        SelectInterface $query,
        $gibbonPersonID
    ): SelectInterface {
        return $query
            ->where(
                '(r.gibbonPersonIDCreator = :viewer
                    OR EXISTS (
                        SELECT 1 FROM gibbonCourseClassPerson AS mine
                        WHERE mine.gibbonCourseClassID=r.gibbonCourseClassID
                            AND mine.gibbonPersonID=:viewer
                            AND mine.role=\'Teacher\'
                    ))'
            )
            ->bindValue('viewer', $gibbonPersonID);
    }
}
