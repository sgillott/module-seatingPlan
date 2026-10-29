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

use Gibbon\Contracts\Database\Connection;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;
use Gibbon\Module\SeatingPlan\Reports\ReportGrouping;
use Gibbon\Module\SeatingPlan\Reports\ReportQueryTrait;

/**
 * Room exits: a student marked out of the room, and back in again. Gibbon
 * core has no concept of this at all, unlike Rewards mode's own
 * gibbonBehaviour - this table and everything about it belongs entirely to
 * this module. No reason is captured, per the agreed design - just who
 * left, when, and when (if yet) they came back.
 */
class RoomExitGateway extends QueryableGateway
{
    use TableAware;
    use ReportQueryTrait;

    private static $tableName = 'seatingPlanRoomExit';
    private static $primaryKey = 'seatingPlanRoomExitID';

    /**
     * This table has no date column of its own - an exit is stamped with
     * the moment it started - so reports date it by that timestamp's day.
     */
    private const DATE_COLUMN = 'DATE(r.timeOut)';

    /**
     * @var SettingGateway
     */
    private $settingGateway;

    public function __construct(Connection $db, SettingGateway $settingGateway)
    {
        parent::__construct($db);
        $this->settingGateway = $settingGateway;
    }

    /**
     * When a student nobody marked back in is taken to have returned: after
     * the school's default time out (a module setting), but never later
     * than the end of the lesson. A setting of 0 means the end of the lesson.
     *
     * @param string $timeOut   Y-m-d H:i:s.
     * @param string $timeLimit Y-m-d H:i:s, when the lesson ends.
     *
     * @return string Y-m-d H:i:s.
     */
    public function assumedReturn($timeOut, $timeLimit): string
    {
        $minutes = $this->defaultMinutes();

        if ($minutes === 0) {
            return $timeLimit;
        }

        return min($timeLimit, date('Y-m-d H:i:s', strtotime($timeOut) + $minutes * 60));
    }

    public function defaultMinutes(): int
    {
        return max(0, (int) $this->settingGateway->getSettingByScope(
            'Seating Plan',
            'roomExitDefaultMinutes'
        ));
    }

    /**
     * Every currently-open exit (no time back in yet) for a room, keyed by
     * student - at most one open row per student can ever exist, enforced
     * by toggleForStudent() before opening a new one. Exits whose lesson
     * has ended are closed first, so a forgotten click never shows as a
     * student still out in a later lesson.
     *
     * @param string $gibbonSpaceID The room.
     *
     * @return array Keyed by gibbonPersonID, each
     *                ['seatingPlanRoomExitID', 'timeOut', 'timeLimit'].
     */
    public function selectOpenExitsByRoom($gibbonSpaceID): array
    {
        $this->closeEndedExits();

        $data = ['gibbonSpaceID' => $gibbonSpaceID];
        $sql = "SELECT seatingPlanRoomExitID, gibbonPersonID, timeOut, timeLimit
            FROM seatingPlanRoomExit
            WHERE gibbonSpaceID=:gibbonSpaceID
                AND timeIn IS NULL";

        $open = [];
        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $open[$row['gibbonPersonID']] = $row;
        }

        return $open;
    }

    /**
     * Closes every open exit whose lesson has ended, at the time
     * assumedReturn() gives. A student cannot be out of a lesson for longer
     * than the lesson lasts, so a teacher who forgets to mark them back in
     * loses nothing but the exact return time.
     *
     * @return void
     */
    public function closeEndedExits()
    {
        $minutes = $this->defaultMinutes();

        $this->db()->update(
            'UPDATE seatingPlanRoomExit
             SET timeIn='.($minutes > 0
                ? 'LEAST(timeLimit, timeOut + INTERVAL '.$minutes.' MINUTE)'
                : 'timeLimit').'
             WHERE timeIn IS NULL
                 AND timeLimit <= :now',
            ['now' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Records an exit after the lesson, already closed. Nothing is open, so
     * nothing can race it, unlike toggleForStudent().
     *
     * @param string $timeOut   Y-m-d H:i:s.
     * @param string $timeIn    Y-m-d H:i:s.
     * @param string $timeLimit Y-m-d H:i:s, when the lesson ended.
     *
     * @return int|false The new seatingPlanRoomExitID.
     */
    public function recordPastExit(
        $gibbonPersonID,
        $gibbonSpaceID,
        $gibbonPersonIDCreator,
        $gibbonCourseClassID,
        $gibbonTTColumnRowID,
        $gibbonSchoolYearID,
        $timeOut,
        $timeIn,
        $timeLimit
    ) {
        return $this->insert([
            'gibbonPersonID'        => $gibbonPersonID,
            'gibbonSpaceID'         => $gibbonSpaceID,
            'gibbonCourseClassID'   => $gibbonCourseClassID,
            'gibbonTTColumnRowID'   => $gibbonTTColumnRowID,
            'gibbonSchoolYearID'    => $gibbonSchoolYearID,
            'timeOut'               => $timeOut,
            'timeIn'                => $timeIn,
            'timeLimit'             => $timeLimit,
            'gibbonPersonIDCreator' => $gibbonPersonIDCreator,
        ]);
    }

    /**
     * Toggles one student's room-exit state while serializing requests for
     * the room. The room row is locked before the open-exit row is read so
     * two simultaneous first clicks cannot both insert an open exit.
     *
     * The lesson context is carried alongside the room so an exit can be
     * reported by class, subject and period - the same columns the reward
     * ledger keeps, with the same meaning for class 0: the student belongs
     * to more than one of the room's co-taught classes.
     *
     * @param string $timeLimit When the lesson ends (Y-m-d H:i:s). An exit
     *                          is never open past it, and none can start
     *                          after it.
     *
     * @return array{state:string,timeOut:string,timeLimit:string} state is
     *               'out', 'in', or 'ended' when nothing was written
     *               because the lesson is already over.
     */
    public function toggleForStudent(
        $gibbonPersonID,
        $gibbonSpaceID,
        $gibbonPersonIDCreator,
        $gibbonCourseClassID,
        $gibbonTTColumnRowID,
        $gibbonSchoolYearID,
        $timeLimit
    ): array {
        $transactionStarted = false;
        $now = date('Y-m-d H:i:s');

        try {
            $this->db()->beginTransaction();
            $transactionStarted = true;

            $this->db()->selectOne(
                'SELECT gibbonSpaceID FROM gibbonSpace
                 WHERE gibbonSpaceID=:gibbonSpaceID FOR UPDATE',
                ['gibbonSpaceID' => $gibbonSpaceID]
            );

            $open = $this->db()->selectOne(
                'SELECT seatingPlanRoomExitID, timeOut, timeLimit
                 FROM seatingPlanRoomExit
                 WHERE gibbonSpaceID=:gibbonSpaceID
                     AND gibbonPersonID=:gibbonPersonID
                     AND timeIn IS NULL
                 ORDER BY timeOut, seatingPlanRoomExitID
                 LIMIT 1 FOR UPDATE',
                [
                    'gibbonSpaceID' => $gibbonSpaceID,
                    'gibbonPersonID' => $gibbonPersonID,
                ]
            );

            if (!empty($open)) {
                $this->db()->update(
                    'UPDATE seatingPlanRoomExit
                     SET timeIn=:timeIn
                     WHERE seatingPlanRoomExitID=:seatingPlanRoomExitID
                         AND timeIn IS NULL',
                    [
                        'timeIn' => !empty($open['timeLimit']) && $open['timeLimit'] < $now
                            ? $this->assumedReturn($open['timeOut'], $open['timeLimit'])
                            : $now,
                        'seatingPlanRoomExitID' => $open['seatingPlanRoomExitID'],
                    ]
                );
                $this->db()->commit();

                return ['state' => 'in', 'timeOut' => '', 'timeLimit' => ''];
            }

            if ($timeLimit <= $now) {
                $this->db()->commit();

                return ['state' => 'ended', 'timeOut' => '', 'timeLimit' => ''];
            }

            $this->db()->insert(
                'INSERT INTO seatingPlanRoomExit
                    (gibbonPersonID, gibbonSpaceID, gibbonCourseClassID,
                     gibbonTTColumnRowID, gibbonSchoolYearID, timeOut,
                     timeLimit, gibbonPersonIDCreator)
                 VALUES
                    (:gibbonPersonID, :gibbonSpaceID, :gibbonCourseClassID,
                     :gibbonTTColumnRowID, :gibbonSchoolYearID, :timeOut,
                     :timeLimit, :gibbonPersonIDCreator)',
                [
                    'gibbonPersonID' => $gibbonPersonID,
                    'gibbonSpaceID' => $gibbonSpaceID,
                    'gibbonCourseClassID' => $gibbonCourseClassID,
                    'gibbonTTColumnRowID' => $gibbonTTColumnRowID,
                    'gibbonSchoolYearID' => $gibbonSchoolYearID,
                    'timeOut' => $now,
                    'timeLimit' => $timeLimit,
                    'gibbonPersonIDCreator' => $gibbonPersonIDCreator,
                ]
            );
            $this->db()->commit();

            return ['state' => 'out', 'timeOut' => $now, 'timeLimit' => $timeLimit];
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $this->db()->rollBack();
            }
            throw $e;
        }
    }

    /**
     * One exit with enough context to say, on a confirmation page, whose
     * record this is and which lesson it belongs to.
     *
     * @param string $seatingPlanRoomExitID The row.
     *
     * @return array Empty when it does not exist.
     */
    public function getRecordForAdmin($seatingPlanRoomExitID): array
    {
        $data = ['seatingPlanRoomExitID' => $seatingPlanRoomExitID];
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
            FROM seatingPlanRoomExit AS r
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
            WHERE r.seatingPlanRoomExitID=:seatingPlanRoomExitID";

        return $this->db()->selectOne($sql, $data) ?: [];
    }

    /* ------------------------------------------------------- reporting */

    /**
     * Room exits, totalled over whatever the report is grouped by. Both
     * how often and how long: a class with five short exits and a class
     * with one very long one are different problems.
     *
     * An exit still open has no duration yet, so it counts towards `exits`
     * and `open` but contributes nothing to `minutes` - deliberately not
     * measured against "now", which would make a stale row from a forgotten
     * click grow without limit and quietly dominate every total it is in.
     *
     * @param QueryCriteria $criteria     Paging and sorting.
     * @param string        $groupBy      A ReportGrouping key.
     * @param array         $filters      See ReportQueryTrait::filterReport.
     * @param array|null    $scope        From ReportAccess::scopeFor();
     *                                    null shows every record.
     *
     * @return \Gibbon\Domain\DataSet
     */
    public function queryExits(
        QueryCriteria $criteria,
        $groupBy,
        array $filters = [],
        ?array $scope = null
    ) {
        $grouping = ReportGrouping::definition($groupBy, self::DATE_COLUMN);

        $query = $this
            ->newQuery()
            ->from('seatingPlanRoomExit AS r')
            ->cols(array_merge($grouping['cols'], [
                'COUNT(*) AS exits',
                'COALESCE(SUM(TIMESTAMPDIFF(MINUTE, r.timeOut, r.timeIn)), 0)
                    AS minutes',
                'SUM(CASE WHEN r.timeIn IS NULL THEN 1 ELSE 0 END) AS stillOut',
                'COUNT(DISTINCT r.gibbonPersonID) AS students',
                'MIN('.self::DATE_COLUMN.') AS dateFirst',
                'MAX('.self::DATE_COLUMN.') AS dateLast',
            ]))
            ->groupBy($grouping['groupBy']);

        $this->joinReportTables($query);
        $this->filterReport($query, $filters, self::DATE_COLUMN);

        $this->scopeReport($query, $scope);

        foreach ($grouping['sortBy'] as $column) {
            $query->orderBy([$column]);
        }

        return $this->runQuery($query, $criteria);
    }

    /**
     * The headline totals across everything the filters select, not just
     * the page being shown - see RewardTallyGateway::summariseTally() for
     * why this is its own query.
     *
     * @param array       $filters      See ReportQueryTrait::filterReport.
     * @param array|null  $scope        From ReportAccess::scopeFor();
     *                                  null counts every record.
     *
     * @return array ['exits' => int, 'minutes' => int, 'stillOut' => int,
     *                'students' => int]
     */
    public function summariseExits(array $filters = [], ?array $scope = null): array
    {
        $query = $this
            ->newQuery()
            ->from('seatingPlanRoomExit AS r')
            ->cols([
                'COUNT(*) AS exits',
                'COALESCE(SUM(TIMESTAMPDIFF(MINUTE, r.timeOut, r.timeIn)), 0)
                    AS minutes',
                'SUM(CASE WHEN r.timeIn IS NULL THEN 1 ELSE 0 END) AS stillOut',
                'COUNT(DISTINCT r.gibbonPersonID) AS students',
            ]);

        $this->joinReportTables($query);
        $this->filterReport($query, $filters, self::DATE_COLUMN);

        $this->scopeReport($query, $scope);

        $row = $this->runSelect($query)->fetch() ?: [];

        return [
            'exits'    => (int) ($row['exits'] ?? 0),
            'minutes'  => (int) ($row['minutes'] ?? 0),
            'stillOut' => (int) ($row['stillOut'] ?? 0),
            'students' => (int) ($row['students'] ?? 0),
        ];
    }

    /**
     * One student's own room exits, for the drill-down page.
     *
     * @param QueryCriteria $criteria       Paging and sorting.
     * @param string        $gibbonPersonID The student.
     * @param array         $filters        See ReportQueryTrait.
     * @param array|null    $scope          From ReportAccess::scopeFor();
     *                                      null shows every record.
     *
     * @return \Gibbon\Domain\DataSet
     */
    public function queryStudentHistory(
        QueryCriteria $criteria,
        $gibbonPersonID,
        array $filters = [],
        ?array $scope = null
    ) {
        $query = $this
            ->newQuery()
            ->from('seatingPlanRoomExit AS r')
            ->cols([
                'r.seatingPlanRoomExitID',
                'r.timeOut',
                'r.timeIn',
                'TIMESTAMPDIFF(MINUTE, r.timeOut, r.timeIn) AS minutes',
                "CONCAT(COALESCE(course.nameShort, ''), '.',
                    COALESCE(courseClass.nameShort, '')) AS className",
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

        $this->scopeReport($query, $scope);

        return $this->runQuery($query, $criteria);
    }
}
