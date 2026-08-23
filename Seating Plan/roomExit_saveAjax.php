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

// Toggles one student's room-exit state per request - no arming, a click on
// a seated tile in Room Exits mode always means "the opposite of whatever
// they are right now" (see js/mode.roomexit.js). The toggle decision is
// made here, server-side, from the real open-exit rows - never trusted
// from whatever state the client last rendered.

use Gibbon\Session\TokenHandler;
use Gibbon\Module\SeatingPlan\Domain\RoomExitGateway;
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
 * @param string $state   On success only: 'out' or 'in', the state the
 *                         student is in *after* this toggle.
 * @param string $timeOut On success and state='out' only: when they left.
 *
 * @return void
 */
function seatingPlanRespond(
    bool $ok,
    string $message,
    int $status = 200,
    string $state = '',
    string $timeOut = ''
) {
    http_response_code($status);
    $body = ['ok' => $ok, 'message' => $message];
    if ($ok) {
        $body['state'] = $state;
        if ($state === 'out') {
            $body['timeOut'] = $timeOut;
        }
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
$anchorTTDayRowClassID = $_POST['anchorTTDayRowClassID'] ?? '';

if (
    $gibbonSpaceID === ''
    || $gibbonPersonID === ''
    || !preg_match('/^\d+(,\d+)*$/', $classList)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    || !preg_match('/^\d+$/', $anchorTTDayRowClassID)
) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

$courseClassIDs = explode(',', $classList);
$gibbonPersonIDCreator = $session->get('gibbonPersonID');

// Any teacher of one of the room's classes may mark a student out or in.
$slotGateway = $container->get(TimetableSlotGateway::class);

if (!$slotGateway->isStaffOfAnyClass($courseClassIDs, $gibbonPersonIDCreator)) {
    seatingPlanRespond(false, __('You do not have access to this action.'), 403);
}

// The lesson an exit belongs to is resolved from the timetable here, never
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

// Which of the room's classes this exit belongs to. A student enrolled in
// more than one co-taught class here cannot be attributed to either without
// guessing, so the exit is recorded against class 0, "not attributable" -
// the same rule the reward ledger uses.
$studentClasses = array_filter(explode(',', $student['courseClassIDs'] ?? ''));
$gibbonCourseClassID = count($studentClasses) === 1 ? reset($studentClasses) : '0';

$exitGateway = $container->get(RoomExitGateway::class);

try {
    $result = $exitGateway->toggleForStudent(
        $gibbonPersonID,
        $gibbonSpaceID,
        $gibbonPersonIDCreator,
        $gibbonCourseClassID,
        $slot['gibbonTTColumnRowID'],
        $session->get('gibbonSchoolYearID')
    );

    seatingPlanRespond(
        true,
        __('Your request was completed successfully.'),
        200,
        $result['state'],
        $result['timeOut']
    );
} catch (\Throwable $e) {
    seatingPlanRespond(false, __('Your request failed due to a database error.'), 500);
}
