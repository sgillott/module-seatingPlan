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
use Gibbon\Module\SeatingPlan\Domain\FurnitureGateway;
use Gibbon\Module\SeatingPlan\LayoutCopyName;
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
$itemsRaw = $_POST['items'] ?? '';

$roomLayoutGateway = $container->get(RoomLayoutGateway::class);
$furnitureGateway = $container->get(FurnitureGateway::class);

$layout = $roomLayoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

if (empty($layout)) {
    seatingPlanRespond(false, __('The specified record cannot be found.'), 404);
}

// A colleague's layout is a starting point, not a wall: anyone may open a
// shared layout and rearrange it, and their save lands on a copy of their
// own rather than on the original. The original owner's drawing is never
// touched by anybody else - which is exactly why this is a fork and not a
// permission.
$isOwner = $layout['gibbonPersonIDOwner'] == $session->get('gibbonPersonID');

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
$gridCols = (int) ($_POST['gridCols'] ?? $layout['gridCols']);
$gridRows = (int) ($_POST['gridRows'] ?? $layout['gridRows']);

if ($gridCols < 10 || $gridCols > 40 || $gridRows < 10 || $gridRows > 40) {
    seatingPlanRespond(false, __('A room must be between 10 and 40 cells each way.'), 400);
}

$targetID = $seatingPlanRoomLayoutID;
$forkedTo = '';
$expectedEditVersion = (int) ($_POST['editVersion'] ?? 0);
$timestampModified = date('Y-m-d H:i:s');
$transactionStarted = false;

try {
    $pdo->beginTransaction();
    $transactionStarted = true;

    if (!$isOwner) {
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

$currentVersion = $isOwner
    ? $expectedEditVersion + 1
    : 1;

seatingPlanRespond(
    true,
    $forkedTo === ''
        ? __('Your request was completed successfully.')
        : __('Saved as your own copy of this layout.'),
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
