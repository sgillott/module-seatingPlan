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
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;

require __DIR__.'/../../gibbon.php';

$URL = Url::fromModuleRoute('Seating Plan', 'layouts');

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/layout_add.php')
    == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$gibbonSpaceID = $_POST['gibbonSpaceID'] ?? '';
$name = trim($_POST['name'] ?? '');
$cols = (int) ($_POST['cols'] ?? 0);
$rows = (int) ($_POST['rows'] ?? 0);
$shared = ($_POST['shared'] ?? 'Y') == 'N' ? 'N' : 'Y';

if (empty($gibbonSpaceID) || $name === '') {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

if ($cols < 10 || $cols > 40 || $rows < 10 || $rows > 40) {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

$roomLayoutGateway = $container->get(RoomLayoutGateway::class);

$data = [
    'gibbonSpaceID'       => $gibbonSpaceID,
    'name'                => $name,
    'gridCols'            => $cols,
    'gridRows'            => $rows,
    'gibbonPersonIDOwner' => $session->get('gibbonPersonID'),
    'shared'              => $shared,
    'timestampModified'   => date('Y-m-d H:i:s'),
];

$seatingPlanRoomLayoutID = $roomLayoutGateway->insert($data);

if (empty($seatingPlanRoomLayoutID)) {
    $URL = $URL->withReturn('error2');
    header("Location: {$URL}");
    exit;
}

// Straight into the designer: an empty layout is of no use on the list page.
$URL = Url::fromHandlerRoute('fullscreen.php')
    ->withQueryParams(
        [
            'q'      => '/modules/Seating Plan/room.php',
            'layout' => $seatingPlanRoomLayoutID,
            'mode'   => 'furniture',
        ]
    );

header("Location: {$URL}");
