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

use Gibbon\Http\Url;
use Gibbon\Module\SeatingPlan\FurnitureCatalogue;
use Gibbon\Module\SeatingPlan\Domain\FurnitureGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;
use Gibbon\Module\SeatingPlan\Domain\SeatingPlanGateway;

require __DIR__.'/../../gibbon.php';

$seatingPlanRoomLayoutID = $_GET['seatingPlanRoomLayoutID'] ?? '';
$action = $_GET['action'] ?? '';

$URL = Url::fromModuleRoute('Seating Plan', 'layout_update')
    ->withQueryParam('seatingPlanRoomLayoutID', $seatingPlanRoomLayoutID);

if (
    isActionAccessible(
        $guid,
        $connection2,
        '/modules/Seating Plan/layout_update.php'
    ) == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

if (!in_array($action, ['accept', 'dismiss'], true)) {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

$roomLayoutGateway = $container->get(RoomLayoutGateway::class);
$layout = $roomLayoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

if (empty($layout)) {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

if ($layout['gibbonPersonIDOwner'] != $session->get('gibbonPersonID')) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

// Re-read the pending update rather than trusting that the confirmation
// page was looking at the same thing: the source may have changed again,
// or been withdrawn, between the page loading and the button being pressed.
$update = $roomLayoutGateway->getPendingUpdate($seatingPlanRoomLayoutID);

if (empty($update)) {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

// Either way the copy is now considered current as at this version of the
// source. Dismissing records that decision and changes nothing else, so the
// same version stops asking while a later one still will.
if ($action === 'dismiss') {
    $roomLayoutGateway->update(
        $seatingPlanRoomLayoutID,
        ['sourceTimestamp' => $update['timestampModified']]
    );

    $URL = Url::fromModuleRoute('Seating Plan', 'layouts')->withReturn('success0');
    header("Location: {$URL}");
    exit;
}

$furnitureGateway = $container->get(FurnitureGateway::class);
$planGateway = $container->get(SeatingPlanGateway::class);

try {
    $pdo->beginTransaction();

    // The copy keeps its own row, and therefore its own ID, name, owner and
    // sharing. Only the drawing is replaced - every seating plan filed
    // against this layout has to survive, and those are keyed to the ID.
    $furnitureGateway->deleteFurnitureByLayout($seatingPlanRoomLayoutID);
    $furnitureGateway->copyFurniture(
        $update['seatingPlanRoomLayoutID'],
        $seatingPlanRoomLayoutID
    );

    $roomLayoutGateway->update(
        $seatingPlanRoomLayoutID,
        [
            'gridCols'          => $update['gridCols'],
            'gridRows'          => $update['gridRows'],
            'sourceTimestamp'   => $update['timestampModified'],
            'timestampModified' => date('Y-m-d H:i:s'),
        ]
    );

    // Any window still open on this layout is now looking at furniture that
    // no longer exists, so its next save must be rejected as a conflict
    // rather than quietly writing the old arrangement back.
    $roomLayoutGateway->bumpEditVersion($seatingPlanRoomLayoutID);

    // The chairs have moved. Students keep their places where a chair is
    // still near them, and are unseated only where there is nothing left to
    // sit on - see SeatingPlanGateway::matchSeatsToChairs().
    $chairs = [];

    foreach ($furnitureGateway->selectFurnitureByLayout($seatingPlanRoomLayoutID) as $item) {
        if (FurnitureCatalogue::isSeat($item['type'])) {
            $chairs[] = $item;
        }
    }

    $moves = $planGateway->resnapSeatsToChairs($seatingPlanRoomLayoutID, $chairs);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();

    $URL = $URL->withReturn('error2');
    header("Location: {$URL}");
    exit;
}

$URL = Url::fromModuleRoute('Seating Plan', 'layouts')
    ->withQueryParams(
        [
            'seatsMoved'    => $moves['moved'],
            'seatsUnseated' => $moves['unseated'],
        ]
    )
    ->withReturn('success0');
header("Location: {$URL}");
