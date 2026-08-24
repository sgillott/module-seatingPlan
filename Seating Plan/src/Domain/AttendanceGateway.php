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
use Gibbon\Contracts\Database\Connection;
use Gibbon\Domain\Attendance\AttendanceCodeGateway;
use Gibbon\Domain\Attendance\AttendanceLogPersonGateway;
use Gibbon\Domain\Attendance\AttendanceLogCourseClassGateway;

/**
 * This module's own thin service over core's Attendance domain, for the
 * register.
 *
 * Core has no single AttendanceGateway; this wraps the three real ones
 * (AttendanceLogPersonGateway, AttendanceLogCourseClassGateway,
 * AttendanceCodeGateway) rather than duplicating their work, following the
 * exact shape Gibbon\Domain\Messenger\MessengerGateway already uses for a
 * gateway that needs more than a bare Connection.
 *
 * The per-student log (gibbonAttendanceLogPerson) is append-only here,
 * deliberately: core's own dedup lookup in
 * attendance_take_byCourseClassProcess.php is unreachable in practice (its
 * WHERE excludes context='Class' rows, then the caller filters for exactly
 * that), so core's real, observed behaviour is a fresh INSERT on every
 * mark, never an update - confirmed empirically, not just by reading the
 * code, in the scratchpad's test_core_attendance_probe.php. This gateway
 * matches that: saveMark() never updates or deletes a log row. It also
 * never writes context='Person' rows: some schools mirror class attendance
 * to whole-school attendance (Attendance/recordFirstClassAsSchool), and
 * this module deliberately does not replicate that, regardless of a
 * school's setting.
 */
class AttendanceGateway extends Gateway
{
    /**
     * @var AttendanceLogPersonGateway
     */
    private $logPersonGateway;

    /**
     * @var AttendanceLogCourseClassGateway
     */
    private $logClassGateway;

    /**
     * @var AttendanceCodeGateway
     */
    private $codeGateway;

    /**
     * @param Connection                       $db              The database.
     * @param AttendanceLogPersonGateway       $logPersonGateway Core's per-student log.
     * @param AttendanceLogCourseClassGateway  $logClassGateway  Core's per-class marker.
     * @param AttendanceCodeGateway            $codeGateway      Core's code catalogue.
     */
    public function __construct(
        Connection $db,
        AttendanceLogPersonGateway $logPersonGateway,
        AttendanceLogCourseClassGateway $logClassGateway,
        AttendanceCodeGateway $codeGateway
    ) {
        parent::__construct($db);
        $this->logPersonGateway = $logPersonGateway;
        $this->logClassGateway = $logClassGateway;
        $this->codeGateway = $codeGateway;
    }

    /**
     * The codes a click may cycle through: active, and either unrestricted
     * or explicitly allowed for the taking teacher's own role. In
     * sequenceNumber order, which is the school's own configured display
     * order - exactly "common ones first" once a school has set it up that
     * way.
     *
     * @param array $gibbonRoleIDs The taking teacher's own role IDs, as a
     *                             plain array - e.g.
     *                             array_column($session->get('gibbonRoleIDAll'),
     *                             0), matching how core's own AttendanceView
     *                             reads the same session value. That session
     *                             value is an array of role rows, not a CSV
     *                             string - passing it through unconverted
     *                             silently matches nothing.
     *
     * @return array Each row also carries a 'bucket': present, late or
     *                absent, derived from direction/scope the same way core's
     *                own AttendanceView does, without depending on that
     *                class (not autoloadable, session/$_GET coupled).
     */
    public function selectActiveCodesForRole(array $gibbonRoleIDs): array
    {
        $sql = "SELECT gibbonAttendanceCodeID, name, nameShort, direction, scope,
                prefill, gibbonRoleIDAll
            FROM gibbonAttendanceCode
            WHERE active='Y'
            ORDER BY sequenceNumber";

        $roles = array_filter($gibbonRoleIDs);

        $codes = array_values(array_filter(
            $this->db()->select($sql)->fetchAll(),
            function ($code) use ($roles) {
                if (empty($code['gibbonRoleIDAll'])) {
                    return true;
                }

                return !empty(array_intersect(
                    $roles,
                    explode(',', $code['gibbonRoleIDAll'])
                ));
            }
        ));

        foreach ($codes as &$code) {
            $code['bucket'] = self::bucketOf($code['direction'], $code['scope']);
        }

        return $codes;
    }

    /**
     * The colour bucket for a code, from its direction and scope alone -
     * never its name, since a code's name is school-configurable free text
     * and its direction/scope are the only structurally stable thing about
     * it. 'present' and 'late' reuse core's own AttendanceView rules
     * (isTypePresent/isTypeLate), reimplemented here as pure functions on
     * the two enum columns so this module never needs AttendanceView
     * itself.
     *
     * 'absent' is deliberately narrower than "any Out code": it matches
     * only core's own literal Absent code's own (direction, scope) pair
     * (Out, Offsite). Any other Out code - core's own Left/Left - Early,
     * or a school's own "Additional" codes with a different scope, such as
     * a half-day trip or a sports fixture - falls to 'other' instead of
     * being visually lumped in with Absent, which would misrepresent them.
     *
     * @param string $direction 'In' or 'Out'.
     * @param string $scope     One of gibbonAttendanceCode's scope values.
     *
     * @return string 'present', 'late', 'absent' or 'other'.
     */
    public static function bucketOf($direction, $scope): string
    {
        if ($direction === 'In') {
            if (in_array($scope, ['Onsite - Late', 'Offsite - Late'], true)) {
                return 'late';
            }

            return in_array($scope, ['Onsite', 'Offsite'], true) ? 'present' : 'other';
        }

        return $scope === 'Offsite' ? 'absent' : 'other';
    }

    /**
     * The current mark for every roster student who has exactly one class
     * in this room, in one query rather than one per student.
     *
     * "Current" is whichever row wins under core's own wildcard/crossfill
     * rule (selectAttendanceLogByStudentAndClassID's shape:
     * gibbonTTDayRowClassID matching the exact period, or NULL, or - with
     * crossFillClasses='Y' - any period whose own code has prefill='Y'),
     * tie-broken by timestampTaken then gibbonAttendanceLogPersonID, both
     * descending, so two rows sharing a timestamp still resolve
     * deterministically.
     *
     * A mark's own code is read via a join here, independently of
     * selectActiveCodesForRole()'s active-and-allowed list, so a mark whose
     * code has since been deactivated or role-restricted still resolves to
     * a real bucket for display - see AttendanceGateway's class docblock.
     *
     * Two classes can share a room for the same period, and each still has
     * its own gibbonTTDayRowClass row. The period a mark has to match is
     * therefore the student's own class's period, which is why the anchor
     * arrives here per class rather than as one value for the room.
     *
     * @param array  $personIDsByClass [gibbonPersonID => their own
     *                                  gibbonCourseClassID], one entry per
     *                                  unambiguous roster student.
     * @param array  $anchorByClass    [(int) gibbonCourseClassID =>
     *                                  gibbonTTDayRowClassID], the period
     *                                  being viewed for each class in the
     *                                  room. Keyed on the integer value
     *                                  because the IDs arrive zerofilled
     *                                  from some queries and unpadded from
     *                                  others, and PHP keeps a zerofilled
     *                                  string as a string key.
     * @param string $date             Y-m-d.
     * @param string $crossFillClasses The Attendance/crossFillClasses
     *                                  setting, 'Y' or 'N'.
     *
     * @return array [gibbonPersonID => ['gibbonAttendanceCodeID', 'name',
     *                'nameShort', 'bucket', 'exact']], only for students who
     *                have a mark. 'exact' is true only when the resolved
     *                row's own gibbonTTDayRowClassID is that student's own
     *                anchor period, false when it was pulled in via the
     *                wildcard or crossfill fallback.
     */
    public function selectCurrentMarksForRoster(
        array $personIDsByClass,
        array $anchorByClass,
        $date,
        $crossFillClasses
    ): array {
        if (empty($personIDsByClass)) {
            return [];
        }

        $data = [
            'date'         => $date,
            'personIDList' => implode(',', array_keys($personIDsByClass)),
            // Unpadded, to match the CAST on the column below - see
            // StudentRosterGateway for the same reasoning.
            'classList'    => implode(
                ',',
                array_unique(array_map('intval', array_values($personIDsByClass)))
            ),
        ];
        $sql = "SELECT log.gibbonAttendanceLogPersonID, log.gibbonPersonID,
                log.gibbonCourseClassID, log.gibbonTTDayRowClassID,
                log.gibbonAttendanceCodeID, log.timestampTaken,
                code.name, code.nameShort, code.direction, code.scope, code.prefill
            FROM gibbonAttendanceLogPerson AS log
            LEFT JOIN gibbonAttendanceCode AS code
                ON (code.gibbonAttendanceCodeID=log.gibbonAttendanceCodeID)
            WHERE log.context='Class' AND log.date=:date
                AND FIND_IN_SET(log.gibbonPersonID, :personIDList)
                AND FIND_IN_SET(
                    CAST(log.gibbonCourseClassID AS UNSIGNED), :classList)";

        $byStudent = [];
        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $byStudent[$row['gibbonPersonID']][] = $row;
        }

        $marks = [];

        foreach ($personIDsByClass as $gibbonPersonID => $gibbonCourseClassID) {
            // The student's own class's period, not the room's. Where a room
            // holds two classes at once, marks for the class the room was
            // not opened on carry that class's own period row, and matching
            // them against a single shared anchor found nothing at all.
            $anchor = $anchorByClass[(int) $gibbonCourseClassID] ?? '';

            $candidates = array_values(array_filter(
                $byStudent[$gibbonPersonID] ?? [],
                function ($row) use ($gibbonCourseClassID, $anchor, $crossFillClasses) {
                    if ($row['gibbonCourseClassID'] != $gibbonCourseClassID) {
                        return false;
                    }
                    if ($anchor !== '' && $row['gibbonTTDayRowClassID'] == $anchor) {
                        return true;
                    }
                    if ($crossFillClasses === 'Y') {
                        return $row['prefill'] === 'Y';
                    }

                    return empty($row['gibbonTTDayRowClassID']);
                }
            ));

            if (empty($candidates)) {
                continue;
            }

            usort($candidates, function ($a, $b) {
                $byTime = strcmp($b['timestampTaken'], $a['timestampTaken']);

                return $byTime !== 0
                    ? $byTime
                    : strcmp($b['gibbonAttendanceLogPersonID'], $a['gibbonAttendanceLogPersonID']);
            });

            $current = $candidates[0];

            $marks[$gibbonPersonID] = [
                'gibbonAttendanceCodeID' => $current['gibbonAttendanceCodeID'],
                'name'                   => $current['name'],
                'nameShort'              => $current['nameShort'],
                'bucket'                 => self::bucketOf(
                    $current['direction'],
                    $current['scope']
                ),
                // Whether this row is a genuine record for the exact period
                // being viewed, or was only pulled in via the NULL-period
                // wildcard/crossfill fallback above. A caller that needs to
                // know "has this actually been recorded for today's period"
                // (not just "what does it currently display") needs this -
                // a crossfilled row displays a real mark without there being
                // any row at all for this specific period yet.
                'exact' => $anchor !== ''
                    && $current['gibbonTTDayRowClassID'] == $anchor,
            ];
        }

        return $marks;
    }

    /**
     * How many of a room's eligible students have a mark for their own
     * class's own period on a date - for a My Lessons status indicator, not
     * a legal record.
     *
     * "Eligible" excludes anyone ambiguous (enrolled in more than one of the
     * room's classes), matching Register mode's own tiles exactly - those
     * students can never be marked here, so requiring them would make
     * completion unreachable. Deliberately does NOT apply
     * selectCurrentMarksForRoster()'s wildcard/crossfill fallbacks beyond
     * its own NULL-period wildcard: a completion count should reflect
     * whether *this* lesson's own register is done, not be satisfied by an
     * unrelated cross-filled period. Each class in the room is queried
     * against its own gibbonTTDayRowClassID (not one shared anchor), since
     * co-taught classes sharing a room and period each still have their own
     * period row.
     *
     * @param array $roster    Rows from StudentRosterGateway::selectRoster(),
     *                         each carrying gibbonPersonID and
     *                         courseClassIDs.
     * @param array $classRows Rows from
     *                         TimetableSlotGateway::selectClassesInSlot(),
     *                         each carrying gibbonCourseClassID and
     *                         gibbonTTDayRowClassID.
     * @param string $date     Y-m-d.
     *
     * @return array ['eligible' => int, 'marked' => int].
     */
    public function countRoomCompletion(array $roster, array $classRows, $date): array
    {
        $anchorByClass = [];
        foreach ($classRows as $row) {
            $anchorByClass[(int) $row['gibbonCourseClassID']]
                = $row['gibbonTTDayRowClassID'];
        }

        $classOfStudent = [];
        foreach ($roster as $student) {
            $classIDs = array_values(array_filter(
                explode(',', (string) ($student['courseClassIDs'] ?? ''))
            ));

            if (count($classIDs) === 1) {
                $classOfStudent[$student['gibbonPersonID']] = $classIDs[0];
            }
        }

        $eligible = count($classOfStudent);

        if ($eligible === 0) {
            return ['eligible' => 0, 'marked' => 0];
        }

        // One query for the whole room: selectCurrentMarksForRoster() takes
        // the anchor per class, so the classes no longer have to be counted
        // one at a time.
        $marks = $this->selectCurrentMarksForRoster(
            $classOfStudent,
            $anchorByClass,
            $date,
            'N'
        );

        return ['eligible' => $eligible, 'marked' => count($marks)];
    }

    /**
     * Records one student's attendance code against every period in
     * $periods, and keeps the per-class marker current for each of them.
     *
     * Always inserts a new log row - see the class docblock for why an
     * update-in-place would not match core's real behaviour. The marker
     * lookup uses SELECT ... FOR UPDATE inside the transaction, since
     * gibbonAttendanceLogCourseClass has no unique constraint either and
     * this module (unlike the log) does touch it on every save; the lock
     * closes the window where two concurrent saves for the same class and
     * period could each find no existing marker and both insert one.
     *
     * @param string $gibbonPersonID      The student.
     * @param string $gibbonCourseClassID Their own class.
     * @param array  $periods             gibbonTTDayRowClassID values, one
     *                                    per period the lesson spans.
     * @param string $date                Y-m-d.
     * @param array  $code                One row from
     *                                    selectActiveCodesForRole():
     *                                    gibbonAttendanceCodeID, name,
     *                                    direction.
     * @param string $gibbonPersonIDTaker Whoever is taking the register.
     *
     * @return void
     */
    public function saveMark(
        $gibbonPersonID,
        $gibbonCourseClassID,
        array $periods,
        $date,
        array $code,
        $gibbonPersonIDTaker
    ): void {
        $now = date('Y-m-d H:i:s');

        $this->db()->beginTransaction();

        try {
            foreach ($periods as $gibbonTTDayRowClassID) {
                $this->logPersonGateway->insert([
                    'gibbonAttendanceCodeID' => $code['gibbonAttendanceCodeID'],
                    'gibbonPersonID'         => $gibbonPersonID,
                    'direction'              => $code['direction'],
                    'type'                   => $code['name'],
                    'reason'                 => '',
                    'comment'                => '',
                    'context'                => 'Class',
                    'gibbonPersonIDTaker'    => $gibbonPersonIDTaker,
                    'gibbonCourseClassID'    => $gibbonCourseClassID,
                    'gibbonTTDayRowClassID'  => $gibbonTTDayRowClassID,
                    'date'                   => $date,
                    'timestampTaken'         => $now,
                ]);

                $this->upsertMarker(
                    $gibbonCourseClassID,
                    $gibbonTTDayRowClassID,
                    $date,
                    $gibbonPersonIDTaker,
                    $now
                );
            }

            $this->db()->commit();
        } catch (\Throwable $e) {
            $this->db()->rollBack();
            throw $e;
        }
    }

    /**
     * Finds (locking the row, if one exists) or creates the
     * gibbonAttendanceLogCourseClass marker for one class and period,
     * matching core's own upsert in attendance_take_byCourseClassProcess.php
     * column for column: exact equality on class, date and period; update
     * touches only gibbonPersonIDTaker and timestampTaken; insert adds
     * gibbonCourseClassID, gibbonTTDayRowClassID and date alongside them.
     *
     * @param string $gibbonCourseClassID   The class.
     * @param string $gibbonTTDayRowClassID The period.
     * @param string $date                  Y-m-d.
     * @param string $gibbonPersonIDTaker   Whoever is taking the register.
     * @param string $now                   Y-m-d H:i:s, shared with the log
     *                                       insert this accompanies.
     *
     * @return void
     */
    private function upsertMarker(
        $gibbonCourseClassID,
        $gibbonTTDayRowClassID,
        $date,
        $gibbonPersonIDTaker,
        $now
    ): void {
        $data = [
            'gibbonCourseClassID'   => $gibbonCourseClassID,
            'gibbonTTDayRowClassID' => $gibbonTTDayRowClassID,
            'date'                  => $date,
        ];
        $existing = $this->db()->selectOne(
            "SELECT gibbonAttendanceLogCourseClassID
                FROM gibbonAttendanceLogCourseClass
                WHERE gibbonCourseClassID=:gibbonCourseClassID
                    AND gibbonTTDayRowClassID=:gibbonTTDayRowClassID
                    AND date=:date
                FOR UPDATE",
            $data
        );

        if (!empty($existing)) {
            $this->logClassGateway->update($existing, [
                'gibbonPersonIDTaker' => $gibbonPersonIDTaker,
                'timestampTaken'      => $now,
            ]);

            return;
        }

        $this->logClassGateway->insert([
            'gibbonPersonIDTaker'   => $gibbonPersonIDTaker,
            'gibbonCourseClassID'   => $gibbonCourseClassID,
            'gibbonTTDayRowClassID' => $gibbonTTDayRowClassID,
            'date'                  => $date,
            'timestampTaken'        => $now,
        ]);
    }
}
