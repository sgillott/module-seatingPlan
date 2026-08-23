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
 * @param bool   $ok      Whether the save succeeded.
 * @param string $message Text for the user.
 * @param int    $status  HTTP status code.
 *
 * @return void
 */
function seatingPlanRespond(bool $ok, string $message, int $status = 200)
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
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

if ($seatingPlanRoomLayoutID === '' || $gibbonSpaceID === '') {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

try {
    $classList = ClassListNormalizer::normalize($classListRaw);
} catch (\InvalidArgumentException $e) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

$courseClassIDs = explode(',', $classList);

$layoutGateway = $container->get(RoomLayoutGateway::class);
$layout = $layoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

if (empty($layout) || $layout['gibbonSpaceID'] != $gibbonSpaceID) {
    seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
}

// Any teacher of one of the classes may arrange the room, not only the
// layout's owner: co-teachers share one seating plan for the group.
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

$rosterGateway = $container->get(StudentRosterGateway::class);
$roster = $rosterGateway->selectRoster($courseClassIDs, [], null);
$validPersonIDs = array_column($roster, 'gibbonPersonID');

$furnitureGateway = $container->get(FurnitureGateway::class);
$chairPositions = [];

foreach ($furnitureGateway->selectFurnitureByLayout($seatingPlanRoomLayoutID) as $item) {
    // The catalogue decides what a student can sit on, so this and the
    // re-snap after a layout update cannot drift apart on the answer.
    if (FurnitureCatalogue::isSeat($item['type'])) {
        $chairPositions[] = $item['posX'].','.$item['posY'];
    }
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
    $planGateway->replaceSeatsForPlan(
        $seatingPlanPlanID,
        $seats,
        $validPersonIDs,
        $chairPositions
    );
} catch (\InvalidArgumentException $e) {
    seatingPlanRespond(false, $e->getMessage(), 400);
} catch (\Throwable $e) {
    seatingPlanRespond(false, __('Your request failed due to a database error.'), 500);
}

$planGateway->update($seatingPlanPlanID, ['timestampModified' => date('Y-m-d H:i:s')]);

seatingPlanRespond(true, __('Your request was completed successfully.'));
