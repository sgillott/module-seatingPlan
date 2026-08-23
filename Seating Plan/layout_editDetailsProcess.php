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
    isActionAccessible(
        $guid,
        $connection2,
        '/modules/Seating Plan/layout_editDetails.php'
    ) == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$seatingPlanRoomLayoutID = $_POST['seatingPlanRoomLayoutID'] ?? '';
$gibbonSpaceID = $_POST['gibbonSpaceID'] ?? '';
$name = trim($_POST['name'] ?? '');
$shared = ($_POST['shared'] ?? 'Y') == 'N' ? 'N' : 'Y';

$roomLayoutGateway = $container->get(RoomLayoutGateway::class);
$layout = $roomLayoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

if (empty($layout)) {
    $URL = $URL->withReturn('error2');
    header("Location: {$URL}");
    exit;
}

if ($layout['gibbonPersonIDOwner'] != $session->get('gibbonPersonID')) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

if (empty($gibbonSpaceID) || $name === '') {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

$data = [
    'gibbonSpaceID'     => $gibbonSpaceID,
    'name'              => mb_substr($name, 0, 40),
    'shared'            => $shared,
    'timestampModified' => date('Y-m-d H:i:s'),
];

$updated = $roomLayoutGateway->update($seatingPlanRoomLayoutID, $data);

$URL = $URL->withReturn($updated ? 'success0' : 'error2');
header("Location: {$URL}");
