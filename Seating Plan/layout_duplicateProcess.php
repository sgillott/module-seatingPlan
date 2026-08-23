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
use Gibbon\Module\SeatingPlan\Domain\FurnitureGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;

require __DIR__.'/../../gibbon.php';

$URL = Url::fromModuleRoute('Seating Plan', 'layouts');

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/layouts.php')
    == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$seatingPlanRoomLayoutID = $_GET['seatingPlanRoomLayoutID'] ?? '';

$roomLayoutGateway = $container->get(RoomLayoutGateway::class);
$furnitureGateway = $container->get(FurnitureGateway::class);

$source = $roomLayoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

if (empty($source)) {
    $URL = $URL->withReturn('error2');
    header("Location: {$URL}");
    exit;
}

// A shared layout may be copied by anyone who can see it. The copy belongs to
// the person making it and keeps the sharing of the layout it came from.
if (
    $source['gibbonPersonIDOwner'] != $session->get('gibbonPersonID')
    && $source['shared'] != 'Y'
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$data = [
    'gibbonSpaceID'       => $source['gibbonSpaceID'],
    'name'                => mb_substr($source['name'].' '.__('(Copy)'), 0, 40),
    'gridCols'            => $source['gridCols'],
    'gridRows'            => $source['gridRows'],
    'gibbonPersonIDOwner' => $session->get('gibbonPersonID'),
    'shared'              => $source['shared'],
    // Where this came from, and how current it was at the time. If the
    // original is improved later, the copy's owner is offered the newer
    // version rather than being left with a silently stale drawing.
    // Duplicating and saving-as-your-own-copy are the same act, so both
    // record the same thing.
    'seatingPlanRoomLayoutIDSource' => $source['seatingPlanRoomLayoutID'],
    'sourceTimestamp'     => $source['timestampModified'],
    'timestampModified'   => date('Y-m-d H:i:s'),
];

$copyID = $roomLayoutGateway->insert($data);

if (empty($copyID)) {
    $URL = $URL->withReturn('error2');
    header("Location: {$URL}");
    exit;
}

$furnitureGateway->copyFurniture($seatingPlanRoomLayoutID, $copyID);

$URL = Url::fromHandlerRoute('fullscreen.php')
    ->withQueryParams(
        [
            'q'      => '/modules/Seating Plan/room.php',
            'layout' => $copyID,
            'mode'   => 'furniture',
        ]
    );

header("Location: {$URL}");
