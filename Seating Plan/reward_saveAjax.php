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

// Writes one behaviour record per request - Rewards mode has no batch save,
// each click on an armed button records immediately (see js/mode.rewards.js).

use Gibbon\Session\TokenHandler;
use Gibbon\Module\SeatingPlan\BehaviourPolicy;
use Gibbon\Module\SeatingPlan\Domain\RewardGateway;
use Gibbon\Module\SeatingPlan\Domain\RewardTallyGateway;
use Gibbon\Module\SeatingPlan\Domain\StudentRosterGateway;
use Gibbon\Module\SeatingPlan\Domain\TimetableSlotGateway;

include '../../gibbon.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Sends a JSON response and stops.
 *
 * @param bool   $ok      Whether the save succeeded.
 * @param string $message Text for the user.
 * @param int    $status  HTTP status code.
 * @param array  $counts  The student's lesson totals after this point, as
 *                        ['Positive' => int, 'Negative' => int]. The client
 *                        paints its corner badges from these rather than
 *                        incrementing its own copy, so two teachers clicking
 *                        in one room cannot drift apart.
 *
 * @return void
 */
function seatingPlanRespond(
    bool $ok,
    string $message,
    int $status = 200,
    array $counts = []
) {
    http_response_code($status);
    $body = ['ok' => $ok, 'message' => $message];

    if ($ok) {
        $body['counts'] = $counts;
    }

    echo json_encode($body);
    exit;
}

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/room.php')
    == false
) {
    seatingPlanRespond(false, __('You do not have access to this action.'), 403);
}

// This endpoint is not named *Process.php, so the core CSRF check in
// gibbon.php does not cover it. Check the session token here instead.
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    seatingPlanRespond(false, __('Your request failed due to a security error.'), 403);
}

$classList = $_POST['classList'] ?? '';
$gibbonSpaceID = $_POST['gibbonSpaceID'] ?? '';
$date = $_POST['date'] ?? '';
$gibbonPersonID = $_POST['gibbonPersonID'] ?? '';
$type = $_POST['type'] ?? '';
$anchorTTDayRowClassID = $_POST['anchorTTDayRowClassID'] ?? '';
// Right-clicking a tile takes a point back off it. Defaulting to 'add'
// keeps every existing caller behaving exactly as it did.
$action = $_POST['action'] ?? 'add';

if (
    $gibbonSpaceID === ''
    || $gibbonPersonID === ''
    || !in_array($type, ['Positive', 'Negative'], true)
    || !in_array($action, ['add', 'remove'], true)
    || !preg_match('/^\d+(,\d+)*$/', $classList)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    || !preg_match('/^\d+$/', $anchorTTDayRowClassID)
) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

$courseClassIDs = explode(',', $classList);
$gibbonPersonIDCreator = $session->get('gibbonPersonID');

// Any teacher of one of the room's classes may award/sanction, not only
// their own class's teacher - co-teachers share one room.
$slotGateway = $container->get(TimetableSlotGateway::class);

if (!$slotGateway->isStaffOfAnyClass($courseClassIDs, $gibbonPersonIDCreator)) {
    seatingPlanRespond(false, __('You do not have access to this action.'), 403);
}

// The lesson a point belongs to is resolved from the timetable here, never
// taken on trust from the client: getSlot() only returns a row when that
// class genuinely runs on that date, and it resolves any same-day room
// change. The room it reports must be the room the client claims to be in.
$slot = $slotGateway->getSlot($anchorTTDayRowClassID, $date);

if (
    empty($slot)
    || (int) $slot['gibbonSpaceID'] !== (int) $gibbonSpaceID
) {
    seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
}

// Re-derive the roster rather than trusting the posted gibbonPersonID on
// its own - it must actually be a student on this room's roster today.
$rosterGateway = $container->get(StudentRosterGateway::class);
$roster = $rosterGateway->selectRoster($courseClassIDs, [], $date);

$student = [];
foreach ($roster as $row) {
    if ((int) $row['gibbonPersonID'] === (int) $gibbonPersonID) {
        $student = $row;
        break;
    }
}

if (empty($student)) {
    seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
}

// Which of the room's classes this point belongs to. A student enrolled in
// more than one co-taught class here cannot be attributed to either without
// guessing - the same ambiguity Register mode refuses to resolve - so the
// point is recorded against class 0, "not attributable". Nothing is lost
// except class and subject reporting for that one student.
$studentClasses = array_filter(explode(',', $student['courseClassIDs'] ?? ''));
$gibbonCourseClassID = count($studentClasses) === 1 ? reset($studentClasses) : '0';

$tallyGateway = $container->get(RewardTallyGateway::class);
$rewardGateway = $container->get(RewardGateway::class);

$lesson = [
    'gibbonPersonID'        => $student['gibbonPersonID'],
    'gibbonCourseClassID'   => $gibbonCourseClassID,
    'gibbonSpaceID'         => $slot['gibbonSpaceID'],
    'gibbonTTColumnRowID'   => $slot['gibbonTTColumnRowID'],
    'gibbonSchoolYearID'    => $session->get('gibbonSchoolYearID'),
    'date'                  => $date,
    'gibbonPersonIDCreator' => $gibbonPersonIDCreator,
];

try {
    if ($action === 'remove') {
        if (!$tallyGateway->removePoint($lesson, $type)) {
            // Nothing to take back. Reported rather than silently ignored,
            // so a right-click on a tile showing nothing says why.
            seatingPlanRespond(
                false,
                __('There is nothing left to take back for this lesson.'),
                200,
                $tallyGateway->countLesson(
                    $student['gibbonPersonID'],
                    $date,
                    $slot['gibbonTTColumnRowID']
                )
            );
        }
    } else {
        $tallyGateway->addPoint($lesson, $type);
    }

    $counts = $tallyGateway->countLesson(
        $student['gibbonPersonID'],
        $date,
        $slot['gibbonTTColumnRowID']
    );

    // A permanent Behaviour record is written only if the school asked for
    // one, and only once this student's lesson total for this type reaches
    // the configured threshold (and again at the configured interval after
    // that). Off by default - see BehaviourPolicy.
    //
    // The test is how many records the count now deserves against how many
    // have actually been written, not whether this one point crossed the
    // threshold. A point can be taken back, and "did it cross" would answer
    // yes a second time on the way back up and write the same record twice.
    // Taking a point back writes nothing and lowers nothing: a record
    // already written belongs to core's Behaviour module now.
    $settings = $container->get(BehaviourPolicy::class)->getSettings();

    if ($settings['enabled'] && $action === 'add') {
        $due = BehaviourPolicy::logsFor(
            $counts[$type],
            $settings['threshold'],
            $settings['repeat']
        );
        $written = $tallyGateway->countLogged(
            $student['gibbonPersonID'],
            $date,
            $slot['gibbonTTColumnRowID'],
            $type
        );

        for ($i = $written; $i < $due; ++$i) {
            $rewardGateway->recordReward(
                $gibbonPersonID,
                $type,
                $date,
                $session->get('gibbonSchoolYearID'),
                $gibbonPersonIDCreator,
                $settings[$type]['descriptor'],
                $settings[$type]['level'],
                $settings[$type]['comment']
            );
        }

        $tallyGateway->addLogged($lesson, $type, max(0, $due - $written));
    }
} catch (\Throwable $e) {
    seatingPlanRespond(false, __('Your request failed due to a database error.'), 500);
}

seatingPlanRespond(
    true,
    __('Your request was completed successfully.'),
    200,
    $counts
);
