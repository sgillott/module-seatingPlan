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
use Gibbon\Domain\School\FacilityGateway;
use Gibbon\Module\SeatingPlan\ClassListNormalizer;
use Gibbon\Module\SeatingPlan\FurnitureCatalogue;
use Gibbon\Module\SeatingPlan\Domain\FurnitureGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;
use Gibbon\Module\SeatingPlan\Domain\SeatingPlanGateway;
use Gibbon\Module\SeatingPlan\Domain\StudentRosterGateway;
use Gibbon\Module\SeatingPlan\Domain\TimetableSlotGateway;

include '../../gibbon.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Sends a JSON response and stops.
 *
 * @param bool   $ok       Whether the save succeeded.
 * @param string $message  Text for the user.
 * @param int    $status   HTTP status code.
 * @param string $forkedTo When this save had to start a layout for the room,
 *                         because nobody had drawn one, this is its ID. The
 *                         client picks it up so a second save arranges the
 *                         same layout instead of starting another.
 *
 * @return void
 */
function seatingPlanRespond(
    bool $ok,
    string $message,
    int $status = 200,
    string $forkedTo = ''
) {
    http_response_code($status);
    $body = ['ok' => $ok, 'message' => $message];

    if ($forkedTo !== '') {
        $body['forkedTo'] = $forkedTo;
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

// This endpoint is not named *Process.php, so the core CSRF check in gibbon.php
// does not cover it. Check the session token here instead.
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    seatingPlanRespond(false, __('Your request failed due to a security error.'), 403);
}

$seatingPlanRoomLayoutID = $_POST['seatingPlanRoomLayoutID'] ?? '';
$gibbonSpaceID = $_POST['gibbonSpaceID'] ?? '';
$classListRaw = $_POST['classList'] ?? '';
$seatsRaw = $_POST['seats'] ?? '';

// The layout may legitimately be missing: a room nobody has drawn yet still
// has students in it, and they can be arranged in the bare room. The room
// itself, however, always has to be named.
if ($gibbonSpaceID === '') {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

try {
    $classList = ClassListNormalizer::normalize($classListRaw);
} catch (\InvalidArgumentException $e) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

$courseClassIDs = explode(',', $classList);

$layoutGateway = $container->get(RoomLayoutGateway::class);
$layout = [];

if ($seatingPlanRoomLayoutID !== '') {
    $layout = $layoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

    if (empty($layout) || $layout['gibbonSpaceID'] != $gibbonSpaceID) {
        seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
    }
} elseif (!$container->get(FacilityGateway::class)->exists($gibbonSpaceID)) {
    seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
}

// Any teacher of one of the classes may arrange the room, not only the
// layout's owner: co-teachers share one seating plan for the group. It is
// also what entitles the first of them to save to start the room's layout,
// below.
$slotGateway = $container->get(TimetableSlotGateway::class);

if (
    !$slotGateway->isStaffOfAnyClass($courseClassIDs, $session->get('gibbonPersonID'))
) {
    seatingPlanRespond(false, __('You do not have access to this action.'), 403);
}

$seats = json_decode($seatsRaw, true);

if (!is_array($seats)) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

if (count($seats) > 60) {
    seatingPlanRespond(false, __('That is more students than a room can seat.'), 400);
}

// The room's size travels with the save, the same way the designer sends it.
// For a room with no layout yet there is nothing else to take it from.
$gridCols = (int) ($_POST['gridCols'] ?? ($layout['gridCols'] ?? 0));
$gridRows = (int) ($_POST['gridRows'] ?? ($layout['gridRows'] ?? 0));

if ($gridCols < 10 || $gridCols > 40 || $gridRows < 10 || $gridRows > 40) {
    seatingPlanRespond(false, __('A room must be between 10 and 40 cells each way.'), 400);
}

$rosterGateway = $container->get(StudentRosterGateway::class);
$roster = $rosterGateway->selectRoster($courseClassIDs, [], null);
$validPersonIDs = array_column($roster, 'gibbonPersonID');

$furnitureGateway = $container->get(FurnitureGateway::class);
$chairPositions = [];

if ($seatingPlanRoomLayoutID !== '') {
    foreach ($furnitureGateway->selectFurnitureByLayout($seatingPlanRoomLayoutID) as $item) {
        // The catalogue decides what a student can sit on, so this and the
        // re-snap after a layout update cannot drift apart on the answer.
        if (FurnitureCatalogue::isSeat($item['type'])) {
            $chairPositions[] = $item['posX'].','.$item['posY'];
        }
    }
}

// Checked before anything is created: a layout started for a save that then
// turns out to be bad would be left behind empty.
try {
    $clean = SeatingPlanGateway::validateSeats(
        $seats,
        $validPersonIDs,
        $chairPositions,
        $gridCols,
        $gridRows
    );
} catch (\InvalidArgumentException $e) {
    seatingPlanRespond(false, $e->getMessage(), 400);
}

$forkedTo = '';

if ($seatingPlanRoomLayoutID === '') {
    $seatingPlanRoomLayoutID = $layoutGateway->createForSpace(
        $gibbonSpaceID,
        $session->get('gibbonPersonID'),
        $gridCols,
        $gridRows
    );

    if ($seatingPlanRoomLayoutID === '') {
        seatingPlanRespond(false, __('Your request failed due to a database error.'), 500);
    }

    $forkedTo = $seatingPlanRoomLayoutID;
}

$planGateway = $container->get(SeatingPlanGateway::class);
$plan = $planGateway->getOrCreateForLayoutAndClasses(
    $seatingPlanRoomLayoutID,
    $gibbonSpaceID,
    $classList,
    $session->get('gibbonPersonID')
);
$seatingPlanPlanID = $plan['seatingPlanPlanID'] ?? '';

if ($seatingPlanPlanID === '') {
    seatingPlanRespond(false, __('Your request failed due to a database error.'), 500);
}

try {
    $planGateway->replaceSeatsForPlan($seatingPlanPlanID, $clean);
} catch (\Throwable $e) {
    seatingPlanRespond(false, __('Your request failed due to a database error.'), 500);
}

$planGateway->update($seatingPlanPlanID, ['timestampModified' => date('Y-m-d H:i:s')]);

seatingPlanRespond(
    true,
    $forkedTo === ''
        ? __('Your request was completed successfully.')
        : __('Saved. This room now has a layout of its own, ready for furniture.'),
    200,
    $forkedTo
);
