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
use Gibbon\Domain\System\LogGateway;
use Gibbon\Module\SeatingPlan\RecordAdmin;
use Gibbon\Module\SeatingPlan\Domain\RewardTallyGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomExitGateway;

require __DIR__.'/../../gibbon.php';

$type = RecordAdmin::resolveType($_GET['type'] ?? '');
$recordID = $_GET['id'] ?? '';

$URL = Url::fromModuleRoute('Seating Plan', 'record_delete')
    ->withQueryParams(['type' => $type, 'id' => $recordID]);

if (
    isActionAccessible(
        $guid,
        $connection2,
        '/modules/Seating Plan/record_delete.php'
    ) == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$record = $container->get(RecordAdmin::class)->find($type, $recordID);

if (empty($record)) {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

// Everything worth keeping is captured before the row goes, so the log
// entry can say what was removed rather than merely that something was.
$removed = $type === RecordAdmin::REWARD
    ? [
        'positive'       => (int) $record['positive'],
        'negative'       => (int) $record['negative'],
        'positiveLogged' => (int) $record['positiveLogged'],
        'negativeLogged' => (int) $record['negativeLogged'],
        'date'           => $record['date'],
    ]
    : [
        'timeOut' => $record['timeOut'],
        'timeIn'  => $record['timeIn'],
    ];

$deleted = $type === RecordAdmin::REWARD
    ? $container->get(RewardTallyGateway::class)->delete($recordID)
    : $container->get(RoomExitGateway::class)->delete($recordID);

if (!$deleted) {
    $URL = $URL->withReturn('error2');
    header("Location: {$URL}");
    exit;
}

$container->get(LogGateway::class)->addLog(
    $session->get('gibbonSchoolYearIDCurrent'),
    'Seating Plan',
    $session->get('gibbonPersonID'),
    $type === RecordAdmin::REWARD
        ? 'Reward Record - Delete'
        : 'Room Exit Record - Delete',
    [
        'record'         => $recordID,
        'gibbonPersonID' => $record['gibbonPersonID'],
        'removed'        => $removed,
    ]
);

$URL = Url::fromModuleRoute('Seating Plan', 'report_student')
    ->withQueryParam('gibbonPersonID', $record['gibbonPersonID'])
    ->withReturn('success0');
header("Location: {$URL}");
