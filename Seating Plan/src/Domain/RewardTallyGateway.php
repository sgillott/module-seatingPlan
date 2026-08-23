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

use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;
use Gibbon\Module\SeatingPlan\Reports\ReportGrouping;
use Gibbon\Module\SeatingPlan\Reports\ReportQueryTrait;

/**
 * This module's own ledger of the reward and sanction points given in a
 * room: one row per (student, lesson, recording teacher), with a running
 * count of each type.
 *
 * Every point lands here, whether or not the school has asked for a
 * gibbonBehaviour record as well (see BehaviourPolicy) - the two record
 * different things. This table answers "what happened in this lesson", which
 * gibbonBehaviour structurally cannot: it carries no room, period or class
 * column at all, which is why the module used to scope its own counting by a
 * date plus a fixed comment marker. That compromise is gone; this is the
 * count the corner badges show, the count the threshold is measured against,
 * and the only thing the Reports pages read.
 *
 * A lesson is identified by (date, gibbonTTColumnRowID). Note that is the
 * period's *column row*, not a gibbonTTDayRowClassID - every co-taught class
 * sharing a room at one time has its own gibbonTTDayRowClassID but they all
 * share one gibbonTTColumnRowID, so only the column row identifies the lesson
 * the whole room is in.
 */
class RewardTallyGateway extends QueryableGateway
{
    use TableAware;
    use ReportQueryTrait;

    private static $tableName = 'seatingPlanReward';
    private static $primaryKey = 'seatingPlanRewardID';

    /**
     * The reported table's own date expression, for the shared report
     * filters and the "group by date" option.
     */
    private const DATE_COLUMN = 'r.date';

    /**
     * Adds one point of one type to a student's tally for one lesson,
     * creating the row on the first point and incrementing it after that.
     *
     * A raw upsert rather than TableAware::insertAndUpdate(), which is built
     * for auto-increment keys and strips the primary key before inserting -
     * useless against the natural (student, lesson, teacher) key here. The
     * same reason BadgeGateway writes house colours and badge slots by hand.
     *
     * @param array  $lesson Keys: gibbonPersonID, gibbonCourseClassID (0 when
     *                       the student's class in this room is ambiguous),
     *                       gibbonSpaceID, gibbonTTColumnRowID,
     *                       gibbonSchoolYearID, date, gibbonPersonIDCreator.
     * @param string $type   'Positive' or 'Negative'.
     *
     * @return bool False when the type is not one of the two known values.
     */
    public function addPoint(array $lesson, $type): bool
    {
        // The column being incremented cannot be a bound parameter, so it is
        // resolved from this fixed pair and never from the caller's string.
        $columns = ['Positive' => 'positive', 'Negative' => 'negative'];

        if (!isset($columns[$type])) {
            return false;
        }

        $column = $columns[$type];

        $data = [
            'gibbonPersonID'        => $lesson['gibbonPersonID'],
            'gibbonCourseClassID'   => $lesson['gibbonCourseClassID'],
            'gibbonSpaceID'         => $lesson['gibbonSpaceID'],
            'gibbonTTColumnRowID'   => $lesson['gibbonTTColumnRowID'],
            'gibbonSchoolYearID'    => $lesson['gibbonSchoolYearID'],
            'date'                  => $lesson['date'],
            'gibbonPersonIDCreator' => $lesson['gibbonPersonIDCreator'],
        ];
        $sql = "INSERT INTO seatingPlanReward (
                gibbonPersonID, gibbonCourseClassID, gibbonSpaceID,
                gibbonTTColumnRowID, gibbonSchoolYearID, date,
                gibbonPersonIDCreator, {$column}, timestampModified)
            VALUES (
                :gibbonPersonID, :gibbonCourseClassID, :gibbonSpaceID,
                :gibbonTTColumnRowID, :gibbonSchoolYearID, :date,
                :gibbonPersonIDCreator, 1, NOW())
            ON DUPLICATE KEY UPDATE
                {$column}={$column}+1,
                timestampModified=NOW()";

        $this->db()->statement($sql, $data);

        return true;
    }

    /**
     * One tally row with enough context to say, on a confirmation page,
     * whose record this is and which lesson it belongs to.
     *
     * @param string $seatingPlanRewardID The row.
     *
     * @return array Empty when it does not exist.
     */
    public function getRecordForAdmin($seatingPlanRewardID): array
    {
        $data = ['seatingPlanRewardID' => $seatingPlanRewardID];
        $sql = "SELECT
                r.*,
                student.title AS studentTitle,
                student.preferredName AS studentPreferredName,
                student.surname AS studentSurname,
                CONCAT(COALESCE(course.nameShort, ''), '.',
                    COALESCE(courseClass.nameShort, '')) AS className,
                space.name AS spaceName,
                period.name AS periodName,
                teacher.title AS teacherTitle,
                teacher.preferredName AS teacherPreferredName,
                teacher.surname AS teacherSurname
            FROM seatingPlanReward AS r
            JOIN gibbonPerson AS student
                ON (student.gibbonPersonID=r.gibbonPersonID)
            LEFT JOIN gibbonCourseClass AS courseClass
                ON (courseClass.gibbonCourseClassID=r.gibbonCourseClassID)
            LEFT JOIN gibbonCourse AS course
                ON (course.gibbonCourseID=courseClass.gibbonCourseID)
            LEFT JOIN gibbonSpace AS space
                ON (space.gibbonSpaceID=r.gibbonSpaceID)
            LEFT JOIN gibbonTTColumnRow AS period
                ON (period.gibbonTTColumnRowID=r.gibbonTTColumnRowID)
            LEFT JOIN gibbonPerson AS teacher
                ON (teacher.gibbonPersonID=r.gibbonPersonIDCreator)
            WHERE r.seatingPlanRewardID=:seatingPlanRewardID";

        return $this->db()->selectOne($sql, $data) ?: [];
    }

    /**
     * Takes one point of one type back off a student's lesson total.
     *
     * This works on the **lesson**, not on the caller's own row. The ledger
     * is keyed by recording teacher, but the count on the tile is the
     * lesson total across all of them, so a teacher right-clicking a count
     * of two has to be able to move it - otherwise, in a co-taught room,
     * the click would visibly do nothing. Their own row is spent first, and
     * only then a colleague's.
     *
     * Never creates a row, and never goes below zero.
     *
     * @param array  $lesson Keys: gibbonPersonID, gibbonTTColumnRowID,
     *                       date, gibbonPersonIDCreator.
     * @param string $type   'Positive' or 'Negative'.
     *
     * @return bool False when there was nothing left to take back.
     */
    public function removePoint(array $lesson, $type): bool
    {
        $columns = ['Positive' => 'positive', 'Negative' => 'negative'];

        if (!isset($columns[$type])) {
            return false;
        }

        $column = $columns[$type];

        $data = [
            'gibbonPersonID'        => $lesson['gibbonPersonID'],
            'date'                  => $lesson['date'],
            'gibbonTTColumnRowID'   => $lesson['gibbonTTColumnRowID'],
            'gibbonPersonIDCreator' => $lesson['gibbonPersonIDCreator'],
        ];
        // Own row first, then anybody else's, and only rows with something
        // left on them.
        $sql = "SELECT seatingPlanRewardID
            FROM seatingPlanReward
            WHERE gibbonPersonID=:gibbonPersonID
                AND date=:date
                AND gibbonTTColumnRowID=:gibbonTTColumnRowID
                AND {$column} > 0
            ORDER BY gibbonPersonIDCreator=:gibbonPersonIDCreator DESC,
                seatingPlanRewardID
            LIMIT 1";

        $rewardID = $this->db()->selectOne($sql, $data);

        if (empty($rewardID)) {
            return false;
        }

        // The count comes down; the matching *Logged counter deliberately
        // does not. A Behaviour record already written stays written, and
        // leaving the counter alone is what stops the same record being
        // written a second time if the count climbs back past the
        // threshold.
        $this->db()->statement(
            "UPDATE seatingPlanReward
                SET {$column}={$column}-1, timestampModified=NOW()
                WHERE seatingPlanRewardID=:seatingPlanRewardID
                    AND {$column} > 0",
            ['seatingPlanRewardID' => $rewardID]
        );

        return true;
    }

    /**
     * How many Behaviour records this module has already written for one
     * student's lesson total of one type, summed across recording teachers
     * exactly as the count itself is.
     *
     * @param string $gibbonPersonID      The student.
     * @param string $date                Y-m-d.
     * @param string $gibbonTTColumnRowID The period.
     * @param string $type                'Positive' or 'Negative'.
     *
     * @return int
     */
    public function countLogged(
        $gibbonPersonID,
        $date,
        $gibbonTTColumnRowID,
        $type
    ): int {
        $columns = ['Positive' => 'positiveLogged', 'Negative' => 'negativeLogged'];

        if (!isset($columns[$type])) {
            return 0;
        }

        $column = $columns[$type];

        $data = [
            'gibbonPersonID'      => $gibbonPersonID,
            'date'                => $date,
            'gibbonTTColumnRowID' => $gibbonTTColumnRowID,
        ];
        $sql = "SELECT COALESCE(SUM({$column}), 0)
            FROM seatingPlanReward
            WHERE gibbonPersonID=:gibbonPersonID
                AND date=:date
                AND gibbonTTColumnRowID=:gibbonTTColumnRowID";

        return (int) $this->db()->selectOne($sql, $data);
    }

    /**
     * Records that a Behaviour record has been written, against the row the
     * point was just added to.
     *
     * @param array  $lesson Keys: gibbonPersonID, gibbonTTColumnRowID,
     *                       date, gibbonPersonIDCreator.
     * @param string $type   'Positive' or 'Negative'.
     * @param int    $extra  How many were written.
     *
     * @return void
     */
    public function addLogged(array $lesson, $type, int $extra): void
    {
        $columns = ['Positive' => 'positiveLogged', 'Negative' => 'negativeLogged'];

        if (!isset($columns[$type]) || $extra < 1) {
            return;
        }

        $column = $columns[$type];

        $data = [
            'gibbonPersonID'        => $lesson['gibbonPersonID'],
            'date'                  => $lesson['date'],
            'gibbonTTColumnRowID'   => $lesson['gibbonTTColumnRowID'],
            'gibbonPersonIDCreator' => $lesson['gibbonPersonIDCreator'],
            'extra'                 => $extra,
        ];
        $this->db()->statement(
            "UPDATE seatingPlanReward
                SET {$column}={$column}+:extra
                WHERE gibbonPersonID=:gibbonPersonID
                    AND date=:date
                    AND gibbonTTColumnRowID=:gibbonTTColumnRowID
                    AND gibbonPersonIDCreator=:gibbonPersonIDCreator",
            $data
        );
    }

    /**
     * How many points of each type a student has been given in one lesson,
     * summed across every teacher who recorded any - a co-taught room has one
     * row per teacher, but the lesson total is what the threshold is measured
     * against and what the corner badge shows.
     *
     * @param string $gibbonPersonID      The student.
     * @param string $date                Y-m-d.
     * @param string $gibbonTTColumnRowID The period.
     *
     * @return array ['Positive' => int, 'Negative' => int]
     */
    public function countLesson($gibbonPersonID, $date, $gibbonTTColumnRowID): array
    {
        $data = [
            'gibbonPersonID'      => $gibbonPersonID,
            'date'                => $date,
            'gibbonTTColumnRowID' => $gibbonTTColumnRowID,
        ];
        $sql = "SELECT
                COALESCE(SUM(positive), 0) AS positive,
                COALESCE(SUM(negative), 0) AS negative
            FROM seatingPlanReward
            WHERE gibbonPersonID=:gibbonPersonID
                AND date=:date
                AND gibbonTTColumnRowID=:gibbonTTColumnRowID";

        $row = $this->db()->selectOne($sql, $data) ?: [];

        return [
            'Positive' => (int) ($row['positive'] ?? 0),
            'Negative' => (int) ($row['negative'] ?? 0),
        ];
    }

    /**
     * The same counts for a whole room at once, so the corner badges are
     * right the moment the page loads without one query per student.
     *
     * @param array  $gibbonPersonIDs     The room's roster. These must be the
     *                                    IDs as the database returned them:
     *                                    FIND_IN_SET is a string-list match,
     *                                    so a plain PHP int ("601") never
     *                                    matches a zerofilled column's own
     *                                    string form ("00000601").
     * @param string $date                Y-m-d.
     * @param string $gibbonTTColumnRowID The period.
     *
     * @return array Keyed by gibbonPersonID, each ['Positive' => int,
     *                'Negative' => int]. A student with no points at all is
     *                simply absent from the result.
     */
    public function countLessonByRoster(
        array $gibbonPersonIDs,
        $date,
        $gibbonTTColumnRowID
    ): array {
        if (empty($gibbonPersonIDs)) {
            return [];
        }

        $data = [
            'gibbonPersonIDList'  => implode(',', $gibbonPersonIDs),
            'date'                => $date,
            'gibbonTTColumnRowID' => $gibbonTTColumnRowID,
        ];
        $sql = "SELECT
                gibbonPersonID,
                SUM(positive) AS positive,
                SUM(negative) AS negative
            FROM seatingPlanReward
            WHERE FIND_IN_SET(gibbonPersonID, :gibbonPersonIDList)
                AND date=:date
                AND gibbonTTColumnRowID=:gibbonTTColumnRowID
            GROUP BY gibbonPersonID";

        $counts = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $counts[$row['gibbonPersonID']] = [
                'Positive' => (int) $row['positive'],
                'Negative' => (int) $row['negative'],
            ];
        }

        return $counts;
    }

    /* ------------------------------------------------------- reporting */

    /**
     * Rewards and sanctions, totalled over whatever the report is grouped
     * by - one row per student, per class, per subject, per year group, per
     * recording teacher, and so on.
     *
     * @param QueryCriteria $criteria    Paging and sorting.
     * @param string        $groupBy     A ReportGrouping key.
     * @param array         $filters     See ReportQueryTrait::filterReport.
     * @param string|null   $ownClassesOf When set, narrows the report to
     *                                    what this viewer may see under
     *                                    Reports_my.
     *
     * @return \Gibbon\Domain\DataSet
     */
    public function queryTally(
        QueryCriteria $criteria,
        $groupBy,
        array $filters = [],
        $ownClassesOf = null
    ) {
        $grouping = ReportGrouping::definition($groupBy, self::DATE_COLUMN);

        $query = $this
            ->newQuery()
            ->from('seatingPlanReward AS r')
            ->cols(array_merge($grouping['cols'], [
                'SUM(r.positive) AS positive',
                'SUM(r.negative) AS negative',
                'COUNT(*) AS lessons',
                'COUNT(DISTINCT r.gibbonPersonID) AS students',
                'MIN('.self::DATE_COLUMN.') AS dateFirst',
                'MAX('.self::DATE_COLUMN.') AS dateLast',
            ]))
            ->groupBy($grouping['groupBy']);

        $this->joinReportTables($query);
        $this->filterReport($query, $filters, self::DATE_COLUMN);

        if (!empty($ownClassesOf)) {
            $this->scopeReportToOwnClasses($query, $ownClassesOf);
        }

        foreach ($grouping['sortBy'] as $column) {
            $query->orderBy([$column]);
        }

        return $this->runQuery($query, $criteria);
    }

    /**
     * The headline totals across everything the filters select, not just
     * the page being shown.
     *
     * Its own query rather than a sum of the paginated rows: a total that
     * changed when the reader turned the page would simply be wrong.
     *
     * @param array       $filters      See ReportQueryTrait::filterReport.
     * @param string|null $ownClassesOf When set, narrows the totals the
     *                                  same way the report itself is
     *                                  narrowed under Reports_my.
     *
     * @return array ['positive' => int, 'negative' => int,
     *                'students' => int, 'lessons' => int]
     */
    public function summariseTally(array $filters = [], $ownClassesOf = null): array
    {
        $query = $this
            ->newQuery()
            ->from('seatingPlanReward AS r')
            ->cols([
                'COALESCE(SUM(r.positive), 0) AS positive',
                'COALESCE(SUM(r.negative), 0) AS negative',
                'COUNT(DISTINCT r.gibbonPersonID) AS students',
                'COUNT(*) AS lessons',
            ]);

        $this->joinReportTables($query);
        $this->filterReport($query, $filters, self::DATE_COLUMN);

        if (!empty($ownClassesOf)) {
            $this->scopeReportToOwnClasses($query, $ownClassesOf);
        }

        $row = $this->runSelect($query)->fetch() ?: [];

        return [
            'positive' => (int) ($row['positive'] ?? 0),
            'negative' => (int) ($row['negative'] ?? 0),
            'students' => (int) ($row['students'] ?? 0),
            'lessons'  => (int) ($row['lessons'] ?? 0),
        ];
    }

    /**
     * One student's own reward and sanction history, lesson by lesson, for
     * the drill-down page. Not grouped - each row is one lesson with one
     * recording teacher.
     *
     * @param QueryCriteria $criteria       Paging and sorting.
     * @param string        $gibbonPersonID The student.
     * @param array         $filters        See ReportQueryTrait.
     * @param string|null   $ownClassesOf   When set, narrows to what this
     *                                      viewer may see under Reports_my.
     *
     * @return \Gibbon\Domain\DataSet
     */
    public function queryStudentHistory(
        QueryCriteria $criteria,
        $gibbonPersonID,
        array $filters = [],
        $ownClassesOf = null
    ) {
        $query = $this
            ->newQuery()
            ->from('seatingPlanReward AS r')
            ->cols([
                'r.seatingPlanRewardID',
                'r.date',
                'r.positive',
                'r.negative',
                "CONCAT(COALESCE(course.nameShort, ''), '.',
                    COALESCE(courseClass.nameShort, '')) AS className",
                'course.name AS courseName',
                'space.name AS spaceName',
                'period.name AS periodName',
                'teacher.title AS teacherTitle',
                'teacher.surname AS teacherSurname',
                'teacher.preferredName AS teacherPreferredName',
            ])
            ->leftJoin(
                'gibbonTTColumnRow AS period',
                'period.gibbonTTColumnRowID=r.gibbonTTColumnRowID'
            )
            ->where('r.gibbonPersonID = :gibbonPersonID')
            ->bindValue('gibbonPersonID', $gibbonPersonID);

        $this->joinReportTables($query);
        $this->filterReport($query, $filters, self::DATE_COLUMN);

        if (!empty($ownClassesOf)) {
            $this->scopeReportToOwnClasses($query, $ownClassesOf);
        }

        return $this->runQuery($query, $criteria);
    }
}
