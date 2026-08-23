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

use Gibbon\Session\TokenHandler;
use Gibbon\Module\SeatingPlan\Domain\AttendanceGateway;
use Gibbon\Module\SeatingPlan\Domain\StudentRosterGateway;
use Gibbon\Module\SeatingPlan\Domain\TimetableSlotGateway;

include '../../gibbon.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Sends a JSON response and stops.
 *
 * @param bool   $ok             Whether the save succeeded.
 * @param string $message        Text for the user.
 * @param int    $status         HTTP status code.
 * @param array  $marks          On success only: gibbonPersonID => bucket
 *                                for every mark actually written.
 * @param string $gibbonPersonID On a validation failure only: which entry
 *                                in the batch was the problem, so the
 *                                client can point the teacher at it.
 *
 * @return void
 */
function seatingPlanRespond(
    bool $ok,
    string $message,
    int $status = 200,
    array $marks = [],
    string $gibbonPersonID = ''
) {
    http_response_code($status);
    $body = ['ok' => $ok, 'message' => $message];
    if ($ok) {
        $body['marks'] = $marks;
    }
    if ($gibbonPersonID !== '') {
        $body['gibbonPersonID'] = $gibbonPersonID;
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

// Register mode is only ever offered to a teacher with core's own Attendance
// permission; re-checked here rather than trusted from the client-side gate.
if (
    isActionAccessible(
        $guid,
        $connection2,
        '/modules/Attendance/attendance_take_byCourseClass.php'
    ) == false
) {
    seatingPlanRespond(false, __('You do not have access to this action.'), 403);
}

// This endpoint is not named *Process.php, so the core CSRF check in gibbon.php
// does not cover it. Check the session token here instead.
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    seatingPlanRespond(false, __('Your request failed due to a security error.'), 403);
}

$classList = $_POST['classList'] ?? '';
$gibbonSpaceID = $_POST['gibbonSpaceID'] ?? '';
$date = $_POST['date'] ?? '';
$anchorTTDayRowClassID = $_POST['anchorTTDayRowClassID'] ?? '';
$marksRaw = $_POST['marks'] ?? '';

if (
    $gibbonSpaceID === ''
    || $anchorTTDayRowClassID === ''
    || !preg_match('/^\d+(,\d+)*$/', $classList)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

$courseClassIDs = explode(',', $classList);
$gibbonPersonIDTaker = $session->get('gibbonPersonID');

// Any teacher of one of the room's classes may take the register, not only
// their own class's teacher: co-teachers share one room and one register.
$slotGateway = $container->get(TimetableSlotGateway::class);

if (!$slotGateway->isStaffOfAnyClass($courseClassIDs, $gibbonPersonIDTaker)) {
    seatingPlanRespond(false, __('You do not have access to this action.'), 403);
}

// Lesson-context validation: a posted date that does not match the anchor's
// real day resolves to an empty slot here and is rejected by the same path
// as every other mismatch - there is no separate date-equality check
// because getSlot() already joins on the exact date given.
$slot = $slotGateway->getSlot($anchorTTDayRowClassID, $date);

if (empty($slot) || empty($slot['gibbonSpaceID'])) {
    seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
}

if (!in_array($slot['gibbonCourseClassID'], $courseClassIDs)) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

if ($slot['gibbonSpaceID'] != $gibbonSpaceID) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

// Everything downstream uses the date getSlot() itself resolved, not a
// second, independently-trusted copy of the posted value.
$date = $slot['date'];

$marks = json_decode((string) $marksRaw, true);

if (!is_array($marks)) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

if (count($marks) > 100) {
    seatingPlanRespond(false, __('That is more marks than one save can hold.'), 400);
}

if (empty($marks)) {
    // Nothing to do - Ctrl+S can fire with nothing dirty. A harmless no-op,
    // not an error.
    seatingPlanRespond(true, __('Your request was completed successfully.'));
}

$seenPersonIDs = [];
$entries = [];

foreach ($marks as $entry) {
    $gibbonPersonID = (string) ($entry['gibbonPersonID'] ?? '');
    $gibbonAttendanceCodeID = (string) ($entry['gibbonAttendanceCodeID'] ?? '');

    if ($gibbonPersonID === '' || $gibbonAttendanceCodeID === '') {
        seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
    }

    // A tampered batch could repeat a student; a legitimate client never
    // would, since each tile appears once. Reject outright rather than
    // silently taking the last value.
    if (isset($seenPersonIDs[$gibbonPersonID])) {
        seatingPlanRespond(
            false,
            __('Your request failed due to malformed data.'),
            400,
            [],
            $gibbonPersonID
        );
    }
    $seenPersonIDs[$gibbonPersonID] = true;

    $entries[] = [
        'gibbonPersonID' => $gibbonPersonID,
        'gibbonAttendanceCodeID' => $gibbonAttendanceCodeID,
    ];
}

// Re-derive the roster and the active/allowed codes rather than trusting
// anything the client sent: a legal record never guesses.
$rosterGateway = $container->get(StudentRosterGateway::class);
$roster = $rosterGateway->selectRoster($courseClassIDs, [], $date);

// Both IDs below are zerofilled columns (int(10)/int(3) unsigned zerofill).
// Indexing by the raw DB string and looking up with (int) cast on both
// sides avoids the classic pitfall here: a zerofilled string with leading
// zeros ("0000001135") is never int-folded as a PHP array key, so a lookup
// with an unpadded value ("1135") would silently miss it even though the
// two represent the same ID.
$studentsByID = [];
foreach ($roster as $row) {
    $studentsByID[(int) $row['gibbonPersonID']] = $row;
}

$attendanceGateway = $container->get(AttendanceGateway::class);
// Session's own value is an array of role rows, not a CSV - matching core's
// own AttendanceView, which reads it the same way.
$codes = $attendanceGateway->selectActiveCodesForRole(
    array_column($session->get('gibbonRoleIDAll'), 0)
);

$codesByID = [];
foreach ($codes as $row) {
    $codesByID[(int) $row['gibbonAttendanceCodeID']] = $row;
}

// Validation pass: every entry in the batch must be fully valid before any
// of them is written. One bad entry rejects the whole save rather than
// writing some marks and silently dropping others - the teacher would have
// no way to tell which had actually been recorded.
$periodsByClass = [];
$resolved = [];

foreach ($entries as $entry) {
    $gibbonPersonID = $entry['gibbonPersonID'];
    $gibbonAttendanceCodeID = $entry['gibbonAttendanceCodeID'];

    $student = $studentsByID[(int) $gibbonPersonID] ?? null;

    if (empty($student)) {
        seatingPlanRespond(
            false,
            __('The specified record cannot be found.'),
            404,
            [],
            $gibbonPersonID
        );
    }

    $studentClassIDs = array_values(array_filter(
        explode(',', (string) $student['courseClassIDs'])
    ));

    if (count($studentClassIDs) !== 1) {
        seatingPlanRespond(
            false,
            __('This student is enrolled in more than one class in this '
                . 'room, so attendance cannot be recorded for them here.'),
            400,
            [],
            $gibbonPersonID
        );
    }

    $gibbonCourseClassID = $studentClassIDs[0];
    $code = $codesByID[(int) $gibbonAttendanceCodeID] ?? null;

    if (empty($code)) {
        seatingPlanRespond(
            false,
            __('Your request failed due to malformed data.'),
            400,
            [],
            $gibbonPersonID
        );
    }

    // Every period the student's own class occupies, back-to-back, in this
    // room, today - not just the one period the room was opened on. Cached
    // per class, so a class with many changed students only resolves this
    // once.
    if (!array_key_exists($gibbonCourseClassID, $periodsByClass)) {
        $daySlots = $slotGateway->selectClassSlotsOnDay(
            $gibbonCourseClassID,
            $slot['gibbonTTDayID'],
            $date
        );
        $periodsByClass[$gibbonCourseClassID] = TimetableSlotGateway::findContiguousRun(
            $daySlots,
            $gibbonSpaceID,
            $slot['gibbonTTColumnRowID']
        );
    }

    $periods = $periodsByClass[$gibbonCourseClassID];

    if (empty($periods)) {
        seatingPlanRespond(
            false,
            __('The specified record cannot be found.'),
            404,
            [],
            $gibbonPersonID
        );
    }

    $resolved[] = [
        'gibbonPersonID' => $gibbonPersonID,
        'gibbonCourseClassID' => $gibbonCourseClassID,
        'periods' => $periods,
        'code' => $code,
    ];
}

// Write pass: only reached once every entry above has passed. Each
// student's own saveMark() call keeps its own transaction (insert-only log
// rows, a locked upsert on the per-class marker) - there is deliberately no
// single transaction wrapping this whole loop. Connection::beginTransaction()/
// commit() use a reference count, so wrapping this loop in one of our own
// while saveMark() keeps opening and committing its own per call would not
// nest: the outer commit would already have fired after the first student,
// leaving every later student in its own separately-committed transaction -
// no real atomicity, just the appearance of it. True whole-batch atomicity
// would mean restructuring saveMark() to not own its transaction boundary,
// which is out of scope here. The validation pass above already rules out
// every realistic failure before this point; a genuine mid-loop DB
// exception is rare, and every saveMark() call that already ran is a
// complete, valid write on its own - the teacher can simply save again for
// whoever is left.
$marksWritten = [];

try {
    foreach ($resolved as $entry) {
        $attendanceGateway->saveMark(
            $entry['gibbonPersonID'],
            $entry['gibbonCourseClassID'],
            $entry['periods'],
            $date,
            $entry['code'],
            $gibbonPersonIDTaker
        );

        $marksWritten[$entry['gibbonPersonID']] = AttendanceGateway::bucketOf(
            $entry['code']['direction'],
            $entry['code']['scope']
        );
    }
} catch (\Throwable $e) {
    seatingPlanRespond(false, __('Your request failed due to a database error.'), 500);
}

seatingPlanRespond(
    true,
    __('Your request was completed successfully.'),
    200,
    $marksWritten
);
