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
use Gibbon\Services\Format;
use Gibbon\Domain\System\LogGateway;
use Gibbon\Module\SeatingPlan\RecordAdmin;
use Gibbon\Module\SeatingPlan\Domain\RewardTallyGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomExitGateway;

require __DIR__.'/../../gibbon.php';

$type = RecordAdmin::resolveType($_GET['type'] ?? '');
$recordID = $_GET['id'] ?? '';

$URL = Url::fromModuleRoute('Seating Plan', 'record_edit')
    ->withQueryParams(['type' => $type, 'id' => $recordID]);

if (
    isActionAccessible(
        $guid,
        $connection2,
        '/modules/Seating Plan/record_edit.php'
    ) == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$admin = $container->get(RecordAdmin::class);
$record = $admin->find($type, $recordID);

if (empty($record)) {
    $URL = $URL->withReturn('error1');
    header("Location: {$URL}");
    exit;
}

if ($type === RecordAdmin::REWARD) {
    // Clamped rather than rejected: the form already constrains both, so a
    // value outside the range only ever comes from a hand-built post.
    $data = [
        'positive' => max(0, min(999, (int) ($_POST['positive'] ?? 0))),
        'negative' => max(0, min(999, (int) ($_POST['negative'] ?? 0))),
    ];
    $before = [
        'positive' => (int) $record['positive'],
        'negative' => (int) $record['negative'],
    ];

    // positiveLogged and negativeLogged are deliberately left alone. They
    // count Behaviour records that were genuinely written, and those still
    // exist - lowering the counters here would let the same record be
    // written a second time if the count climbed again.
    $data['timestampModified'] = date('Y-m-d H:i:s');

    $saved = $container->get(RewardTallyGateway::class)
        ->update($recordID, $data);
} else {
    $date = Format::dateConvert($_POST['date'] ?? '');
    $timeOut = trim($_POST['timeOut'] ?? '');
    $timeIn = trim($_POST['timeIn'] ?? '');

    if (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)
        || !preg_match('/^\d{1,2}:\d{2}$/', $timeOut)
        || ($timeIn !== '' && !preg_match('/^\d{1,2}:\d{2}$/', $timeIn))
    ) {
        $URL = $URL->withReturn('error1');
        header("Location: {$URL}");
        exit;
    }

    $out = $date.' '.substr('0'.$timeOut, -5).':00';
    $in = $timeIn !== '' ? $date.' '.substr('0'.$timeIn, -5).':00' : null;

    // An exit that came back before it started is not a correction, it is a
    // typo. Refused rather than stored as a negative duration, which every
    // report downstream would then have to cope with.
    if ($in !== null && $in < $out) {
        $URL = $URL->withReturn('error1');
        header("Location: {$URL}");
        exit;
    }

    $data = ['timeOut' => $out, 'timeIn' => $in];
    $before = [
        'timeOut' => $record['timeOut'],
        'timeIn'  => $record['timeIn'],
    ];

    $saved = $container->get(RoomExitGateway::class)->update($recordID, $data);
}

if (!$saved) {
    $URL = $URL->withReturn('error2');
    header("Location: {$URL}");
    exit;
}

// Behaviour data amended with no record of who amended it would be
// indefensible, and core already provides the place to put it.
$container->get(LogGateway::class)->addLog(
    $session->get('gibbonSchoolYearIDCurrent'),
    'Seating Plan',
    $session->get('gibbonPersonID'),
    $type === RecordAdmin::REWARD
        ? 'Reward Record - Edit'
        : 'Room Exit Record - Edit',
    [
        'record'         => $recordID,
        'gibbonPersonID' => $record['gibbonPersonID'],
        'before'         => $before,
        'after'          => array_diff_key($data, ['timestampModified' => '']),
    ]
);

$URL = Url::fromModuleRoute('Seating Plan', 'report_student')
    ->withQueryParam('gibbonPersonID', $record['gibbonPersonID'])
    ->withReturn('success0');
header("Location: {$URL}");
