<?php
/**
 * Gibbon, Flexible & Open School System
 * @category Module
 * @package  Gibbon\Module\SeatingPlan
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */

namespace Gibbon\Module\SeatingPlan\Reports;

use Gibbon\Contracts\Database\Connection;

/** Authorizes access to the identity shown on a per-student report. */
class ReportAccess
{
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Which records the viewer's highest Reports action lets them see.
     * Reports_all sees everything (null). Reports_department adds the
     * records made in classes of departments the viewer coordinates.
     * Anything else sees only the viewer's own records.
     *
     * @return array|null ['gibbonPersonID' => string, 'departments' => bool]
     */
    public static function scopeFor($highestAction, $gibbonPersonID): ?array
    {
        if ($highestAction === 'Reports_all') {
            return null;
        }

        return [
            'gibbonPersonID' => $gibbonPersonID,
            'departments'    => $highestAction === 'Reports_department',
        ];
    }

    /**
     * The scope of the current viewer's highest Reports action. For pages
     * outside the Reports URLList, such as Manage Records, which may only
     * touch what the viewer could see in a report.
     */
    public static function viewerScope($guid, $connection2, $gibbonPersonID): ?array
    {
        $highestAction = getHighestGroupedAction(
            $guid,
            '/modules/Seating Plan/report_rewards.php',
            $connection2
        );

        return self::scopeFor($highestAction, $gibbonPersonID);
    }

    /**
     * Whether one reward or room exit record is inside the scope.
     *
     * @param string $table Either seatingPlanReward or seatingPlanRoomExit.
     */
    public function canSeeRecord($table, $id, ?array $scope): bool
    {
        if ($scope === null) {
            return true;
        }

        $key = $table === 'seatingPlanReward' ? 'seatingPlanRewardID' : 'seatingPlanRoomExitID';
        [$condition, $data] = self::condition($scope);
        $data['recordID'] = $id;

        return !empty($this->db->selectOne(
            "SELECT 1 FROM {$table} AS r WHERE r.{$key}=:recordID AND {$condition}",
            $data
        ));
    }

    /**
     * The WHERE condition for a scope, over a fact table aliased `r`.
     * Coordinator and Assistant Coordinator are the department roles core's
     * CourseGateway::queryCoursesByDepartmentStaff() treats as in charge.
     *
     * @param array  $scope  From scopeFor(), not null.
     * @param string $suffix Keeps the parameter names unique in one query.
     *
     * @return array [string $sql, array $data]
     */
    public static function condition(array $scope, $suffix = ''): array
    {
        $sql = 'r.gibbonPersonIDCreator=:viewer'.$suffix;
        $data = ['viewer'.$suffix => $scope['gibbonPersonID']];

        if ($scope['departments']) {
            $sql = "({$sql} OR r.gibbonCourseClassID IN (
                SELECT deptClass.gibbonCourseClassID
                FROM gibbonCourseClass AS deptClass
                JOIN gibbonCourse AS deptCourse
                    ON deptCourse.gibbonCourseID=deptClass.gibbonCourseID
                JOIN gibbonDepartmentStaff AS deptStaff
                    ON deptStaff.gibbonDepartmentID=deptCourse.gibbonDepartmentID
                WHERE deptStaff.gibbonPersonID=:coordinator{$suffix}
                AND deptStaff.role IN ('Coordinator', 'Assistant Coordinator')))";
            $data['coordinator'.$suffix] = $scope['gibbonPersonID'];
        }

        return [$sql, $data];
    }

    /**
     * A student is reportable only when at least one visible fact row exists.
     * The check runs before the UserGateway lookup so URLs cannot disclose a
     * student's name or photo merely by supplying a person ID.
     */
    public function canViewStudent($gibbonPersonID, ?array $scope): bool
    {
        $data = [
            'gibbonPersonIDReward' => $gibbonPersonID,
            'gibbonPersonIDExit'   => $gibbonPersonID,
        ];

        $rewardScope = '1=1';
        $exitScope = '1=1';

        if ($scope !== null) {
            [$rewardScope, $rewardData] = self::condition($scope, 'Reward');
            [$exitScope, $exitData] = self::condition($scope, 'Exit');
            $data = array_merge($data, $rewardData, $exitData);
        }

        $sql = "SELECT 1 FROM seatingPlanReward AS r
            WHERE r.gibbonPersonID=:gibbonPersonIDReward
                AND {$rewardScope}
            UNION ALL
            SELECT 1 FROM seatingPlanRoomExit AS r
            WHERE r.gibbonPersonID=:gibbonPersonIDExit
                AND {$exitScope}
            LIMIT 1";

        return !empty($this->db->selectOne($sql, $data));
    }
}