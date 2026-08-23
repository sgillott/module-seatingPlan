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
     * A student is reportable only when at least one visible fact row exists.
     * The check runs before the UserGateway lookup so URLs cannot disclose a
     * student's name or photo merely by supplying a person ID.
     */
    public function canViewStudent($gibbonPersonID, ?string $ownClassesOf): bool
    {
        $data = [
            'gibbonPersonIDReward' => $gibbonPersonID,
            'gibbonPersonIDExit'   => $gibbonPersonID,
        ];

        if ($ownClassesOf === null) {
            $rewardScope = '1=1';
            $exitScope = '1=1';
        } else {
            $rewardScope = "(r.gibbonPersonIDCreator=:viewerReward
                OR EXISTS (
                    SELECT 1 FROM gibbonCourseClassPerson AS mine
                    WHERE mine.gibbonCourseClassID=r.gibbonCourseClassID
                        AND mine.gibbonPersonID=:viewerRewardTeacher
                        AND mine.role='Teacher'
                ))";
            $exitScope = "(r.gibbonPersonIDCreator=:viewerExit
                OR EXISTS (
                    SELECT 1 FROM gibbonCourseClassPerson AS mine
                    WHERE mine.gibbonCourseClassID=r.gibbonCourseClassID
                        AND mine.gibbonPersonID=:viewerExitTeacher
                        AND mine.role='Teacher'
                ))";
            $data['viewerReward'] = $ownClassesOf;
            $data['viewerRewardTeacher'] = $ownClassesOf;
            $data['viewerExit'] = $ownClassesOf;
            $data['viewerExitTeacher'] = $ownClassesOf;
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