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

/**
 * What a report is grouped by: one row per student, per class, per subject,
 * per year group, per recording teacher, and so on.
 *
 * The rewards report and the room-exits report ask the same questions of
 * two different tables, so the grouping is defined once here and both
 * gateways build their query from it. Both use the same table aliases -
 * `r` for the table being reported on, then `student`, `enrolment`,
 * `formGroup`, `yearGroup`, `courseClass`, `course`, `teacher` and `space`
 * for the core tables joined alongside it.
 *
 * Every grouping lists its own GROUP BY columns in full rather than relying
 * on MySQL to work out which columns are functionally dependent on the key:
 * this database runs with ONLY_FULL_GROUP_BY, and dependency through a
 * join is not something to leave to the optimiser.
 */
class ReportGrouping
{
    public const DEFAULT_GROUPING = 'student';

    /**
     * The grouping keys and their labels, for a select element.
     *
     * @return array
     */
    public static function options(): array
    {
        return [
            'student'   => __('Student'),
            'class'     => __('Class'),
            'subject'   => __('Subject'),
            'yearGroup' => __('Year Group'),
            'formGroup' => __('Form Group'),
            'teacher'   => __('Recorded By'),
            'space'     => __('Room'),
            'date'      => __('Date'),
        ];
    }

    /**
     * One grouping's SQL.
     *
     * @param string $key        A key from options(). Anything unknown
     *                           falls back to the default rather than
     *                           erroring - the value arrives from a query
     *                           string.
     * @param string $dateColumn The reported table's own date expression,
     *                           since a reward carries a date column and a
     *                           room exit only carries a timestamp.
     *
     * @return array Keys: label, cols, groupBy, sortBy, and person - true
     *                when the group is a person, so the page knows to draw
     *                a photo and format the name properly rather than
     *                printing a plain label.
     */
    public static function definition($key, $dateColumn = 'r.date'): array
    {
        $definitions = [
            'student' => [
                'cols' => [
                    'r.gibbonPersonID AS groupID',
                    'student.surname',
                    'student.preferredName',
                    'student.image_240',
                    'MAX(formGroup.name) AS formGroupName',
                    'MAX(yearGroup.name) AS yearGroupName',
                ],
                'groupBy' => [
                    'r.gibbonPersonID',
                    'student.surname',
                    'student.preferredName',
                    'student.image_240',
                ],
                'sortBy' => ['student.surname', 'student.preferredName'],
                'person' => true,
                'role'   => 'Student',
            ],
            'class' => [
                'cols' => [
                    'r.gibbonCourseClassID AS groupID',
                    "CONCAT(COALESCE(course.nameShort, ''), '.',
                        COALESCE(courseClass.nameShort, '')) AS groupName",
                ],
                'groupBy' => [
                    'r.gibbonCourseClassID',
                    'course.nameShort',
                    'courseClass.nameShort',
                ],
                'sortBy' => ['course.nameShort', 'courseClass.nameShort'],
            ],
            'subject' => [
                'cols' => [
                    'course.gibbonCourseID AS groupID',
                    'course.name AS groupName',
                ],
                'groupBy' => ['course.gibbonCourseID', 'course.name'],
                'sortBy'  => ['course.name'],
            ],
            'yearGroup' => [
                'cols' => [
                    'yearGroup.gibbonYearGroupID AS groupID',
                    'yearGroup.name AS groupName',
                ],
                // sequenceNumber is grouped as well as sorted on: year
                // groups read in school order, not alphabetically, and
                // ONLY_FULL_GROUP_BY applies to ORDER BY too.
                'groupBy' => [
                    'yearGroup.gibbonYearGroupID',
                    'yearGroup.name',
                    'yearGroup.sequenceNumber',
                ],
                'sortBy'  => ['yearGroup.sequenceNumber'],
            ],
            'formGroup' => [
                'cols' => [
                    'formGroup.gibbonFormGroupID AS groupID',
                    'formGroup.name AS groupName',
                ],
                'groupBy' => ['formGroup.gibbonFormGroupID', 'formGroup.name'],
                'sortBy'  => ['formGroup.name'],
            ],
            'teacher' => [
                'cols' => [
                    'r.gibbonPersonIDCreator AS groupID',
                    'teacher.surname',
                    'teacher.preferredName',
                    'teacher.image_240',
                ],
                'groupBy' => [
                    'r.gibbonPersonIDCreator',
                    'teacher.surname',
                    'teacher.preferredName',
                    'teacher.image_240',
                ],
                'sortBy' => ['teacher.surname', 'teacher.preferredName'],
                'person' => true,
                'role'   => 'Staff',
            ],
            'space' => [
                'cols' => [
                    'r.gibbonSpaceID AS groupID',
                    'space.name AS groupName',
                ],
                'groupBy' => ['r.gibbonSpaceID', 'space.name'],
                'sortBy'  => ['space.name'],
            ],
            'date' => [
                'cols' => [
                    $dateColumn.' AS groupID',
                    $dateColumn.' AS groupName',
                ],
                'groupBy' => [$dateColumn],
                'sortBy'  => [$dateColumn],
            ],
        ];

        $definition = $definitions[$key] ?? $definitions[self::DEFAULT_GROUPING];
        $definition['person'] = $definition['person'] ?? false;
        $definition['role'] = $definition['role'] ?? 'Student';
        $definition['key'] = self::resolve($key);
        $definition['label'] = self::options()[$key]
            ?? self::options()[self::DEFAULT_GROUPING];

        return $definition;
    }

    /**
     * @param string $key A grouping key from a query string.
     *
     * @return string The key itself when it is one this class knows about,
     *                the default otherwise.
     */
    public static function resolve($key): string
    {
        return isset(self::options()[$key]) ? $key : self::DEFAULT_GROUPING;
    }
}
