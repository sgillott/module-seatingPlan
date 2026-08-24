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
use Gibbon\Module\SeatingPlan\FurnitureCatalogue;
use Gibbon\Module\SeatingPlan\Domain\FurnitureGateway;
use Gibbon\Module\SeatingPlan\Domain\SeatingPlanGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;

include '../../gibbon.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Sends a JSON response and stops.
 *
 * @param bool   $ok       Whether the save succeeded.
 * @param string $message  Text for the user.
 * @param int    $status   HTTP status code.
 * @param string $forkedTo When the save landed on a new copy rather than on
 *                          the layout that was opened, this is the copy's
 *                          ID. The client follows it so a refresh returns to
 *                          the copy and not to the colleague's original.
 *
 * @return void
 */
function seatingPlanRespond(
    bool $ok,
    string $message,
    int $status = 200,
    string $forkedTo = '',
    int $editVersion = 0
) {
    http_response_code($status);
    $body = ['ok' => $ok, 'message' => $message];

    if ($forkedTo !== '') {
        $body['forkedTo'] = $forkedTo;
    }
    if ($editVersion > 0) {
        $body['editVersion'] = $editVersion;
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
$itemsRaw = $_POST['items'] ?? '';

$roomLayoutGateway = $container->get(RoomLayoutGateway::class);
$furnitureGateway = $container->get(FurnitureGateway::class);

// A room reached from a timetabled period has no layout until somebody
// draws one, and this is where they draw it: the layout is started by this
// first save rather than having to exist before the room can be opened.
$startingLayout = $seatingPlanRoomLayoutID === '' && $gibbonSpaceID !== '';
$layout = $startingLayout
    ? []
    : $roomLayoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

if (!$startingLayout && empty($layout)) {
    seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
}

if ($startingLayout && !$container->get(FacilityGateway::class)->exists($gibbonSpaceID)) {
    seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
}

// A colleague's layout is a starting point, not a wall: anyone may open a
// shared layout and rearrange it, and their save lands on a copy of their
// own rather than on the original. The original owner's drawing is never
// touched by anybody else - which is exactly why this is a fork and not a
// permission.
$isOwner = $startingLayout
    || $layout['gibbonPersonIDOwner'] == $session->get('gibbonPersonID');

if (!$isOwner && $layout['shared'] != 'Y') {
    seatingPlanRespond(false, __('You do not have access to this action.'), 403);
}

$items = json_decode($itemsRaw, true);

if (!is_array($items)) {
    seatingPlanRespond(false, __('Your request failed due to malformed data.'), 400);
}

if (count($items) > 400) {
    seatingPlanRespond(false, __('That is more furniture than a room can hold.'), 400);
}

// The designer can resize the room, so the size travels with the save. Fall
// back to the stored size when it is absent.
$gridCols = (int) ($_POST['gridCols'] ?? ($layout['gridCols'] ?? 0));
$gridRows = (int) ($_POST['gridRows'] ?? ($layout['gridRows'] ?? 0));

if ($gridCols < 10 || $gridCols > 40 || $gridRows < 10 || $gridRows > 40) {
    seatingPlanRespond(false, __('A room must be between 10 and 40 cells each way.'), 400);
}

// Whether this room already had chairs before this save. A room with none
// arranges its students on the open floor (see
// SeatingPlanGateway::validateSeats()), so the moment chairs appear those
// positions do need to become real seats - otherwise the arrangement a
// teacher made in the bare room is silently lost the first time somebody
// draws the furniture. A layout that already had chairs is left exactly as
// it behaved before.
$hadChairs = false;

if (!$startingLayout) {
    foreach ($furnitureGateway->selectFurnitureByLayout($seatingPlanRoomLayoutID) as $item) {
        if (FurnitureCatalogue::isSeat($item['type'])) {
            $hadChairs = true;
            break;
        }
    }
}

$targetID = $seatingPlanRoomLayoutID;
$forkedTo = '';
$expectedEditVersion = (int) ($_POST['editVersion'] ?? 0);
$timestampModified = date('Y-m-d H:i:s');
$transactionStarted = false;

try {
    $pdo->beginTransaction();
    $transactionStarted = true;

    if ($startingLayout) {
        $targetID = $roomLayoutGateway->createForSpace(
            $gibbonSpaceID,
            $session->get('gibbonPersonID'),
            $gridCols,
            $gridRows
        );

        if ($targetID === '') {
            throw new \RuntimeException('Could not start a layout for this room.');
        }
        $forkedTo = (string) $targetID;
    } elseif (!$isOwner) {
        $targetID = $roomLayoutGateway->insert([
            'gibbonSpaceID'       => $layout['gibbonSpaceID'],
            'name'                => seatingPlanCopyName($layout['name']),
            'gridCols'            => $gridCols,
            'gridRows'            => $gridRows,
            'gibbonPersonIDOwner' => $session->get('gibbonPersonID'),
            'shared'              => 'N',
            'seatingPlanRoomLayoutIDSource' => $seatingPlanRoomLayoutID,
            'sourceTimestamp'     => $layout['timestampModified'],
            'timestampModified'   => $timestampModified,
        ]);

        if (empty($targetID)) {
            throw new \RuntimeException('Could not create layout copy.');
        }
        $forkedTo = (string) $targetID;
        $roomLayoutGateway->update($targetID, [
            'gridCols' => $gridCols,
            'gridRows' => $gridRows,
            'timestampModified' => $timestampModified,
        ]);
    } else {
        if ($expectedEditVersion < 1) {
            $pdo->rollBack();
            seatingPlanRespond(false, __('Your request was rejected because the layout version is missing.'), 409, '', (int) ($layout['editVersion'] ?? 1));
        }

        if (!$roomLayoutGateway->updateWithVersion(
            $targetID,
            $expectedEditVersion,
            $gridCols,
            $gridRows,
            $timestampModified
        )) {
            $pdo->rollBack();
            $current = $roomLayoutGateway->getLayoutByID($targetID);
            seatingPlanRespond(false, __('This layout changed in another window. Your local changes were kept.'), 409, '', (int) ($current['editVersion'] ?? 1));
        }
    }

    $furnitureGateway->replaceFurnitureForLayout(
        $targetID,
        $items,
        $gridCols,
        $gridRows,
        false
    );

    // The room has just gained its first chairs, so the students standing
    // about on the floor take the chair nearest each of them - see
    // SeatingPlanGateway::matchSeatsToChairs(). Read back from the table
    // rather than trusting the posted items, so this and the seating plan
    // agree on where the chairs actually are.
    if (!$hadChairs) {
        $chairs = [];

        foreach ($furnitureGateway->selectFurnitureByLayout($targetID) as $item) {
            if (FurnitureCatalogue::isSeat($item['type'])) {
                $chairs[] = $item;
            }
        }

        $container->get(SeatingPlanGateway::class)
            ->resnapSeatsToChairs($targetID, $chairs);
    }

    $pdo->commit();
} catch (\InvalidArgumentException $e) {
    if ($transactionStarted) {
        $pdo->rollBack();
    }
    seatingPlanRespond(false, $e->getMessage(), 400);
} catch (\Throwable $e) {
    if ($transactionStarted) {
        $pdo->rollBack();
    }
    seatingPlanRespond(false, __('Your request failed due to a database error.'), 500);
}

// A layout that has just been made is at version 1, as is a copy: only an
// owner saving over their own existing layout advances a version.
$currentVersion = ($startingLayout || !$isOwner)
    ? 1
    : $expectedEditVersion + 1;

if ($forkedTo === '') {
    $message = __('Your request was completed successfully.');
} elseif ($startingLayout) {
    $message = __('Saved. This room now has a layout of its own.');
} else {
    $message = __('Saved as your own copy of this layout.');
}

seatingPlanRespond(
    true,
    $message,
    200,
    $forkedTo,
    $currentVersion
);

/**
 * Names a copy without persisting the copier's identity.
 *
 * @param string $name The original layout's name.
 * @return string At most 40 characters.
 */
function seatingPlanCopyName($name): string
{
    return mb_substr(trim((string) $name).' '.__('(Copy)'), 0, 40);
}
