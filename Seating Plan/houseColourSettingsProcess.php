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
use Gibbon\Module\SeatingPlan\Domain\BadgeGateway;

require __DIR__.'/../../gibbon.php';

$URL = Url::fromModuleRoute('Seating Plan', 'houseColourSettings');

if (
    isActionAccessible(
        $guid,
        $connection2,
        '/modules/Seating Plan/houseColourSettings.php'
    ) == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$badgeGateway = $container->get(BadgeGateway::class);
$houses = $badgeGateway->selectHouses();
$saved = 0;

foreach ($houses as $house) {
    $field = 'colour'.$house['gibbonHouseID'];
    $colour = $_POST[$field] ?? '';

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        continue;
    }

    $badgeGateway->saveHouseColour($house['gibbonHouseID'], $colour);
    ++$saved;
}

$URL = $URL->withReturn($saved > 0 ? 'success0' : 'error1');
header("Location: {$URL}");
