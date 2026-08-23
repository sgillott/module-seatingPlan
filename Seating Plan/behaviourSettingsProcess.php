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
use Gibbon\Domain\System\SettingGateway;

require __DIR__.'/../../gibbon.php';

$URL = Url::fromModuleRoute('Seating Plan', 'behaviourSettings');

if (
    isActionAccessible(
        $guid,
        $connection2,
        '/modules/Seating Plan/behaviourSettings.php'
    ) == false
) {
    $URL = $URL->withReturn('error0');
    header("Location: {$URL}");
    exit;
}

$settingGateway = $container->get(SettingGateway::class);

/**
 * Core's own Behaviour descriptor and level lists, as an array of the
 * values this page is allowed to store. A descriptor is only ever saved if
 * the Behaviour module itself offers it, so a hand-posted value cannot put
 * a descriptor into gibbonBehaviour that Behaviour's own forms would reject.
 *
 * @param SettingGateway $settingGateway Core's settings store.
 * @param string         $name           The Behaviour setting to read.
 *
 * @return array
 */
function seatingPlanBehaviourOptions(SettingGateway $settingGateway, $name): array
{
    $value = (string) $settingGateway->getSettingByScope('Behaviour', $name);
    $options = $value !== '' ? explode(',', $value) : [];

    return array_filter(array_map('trim', $options), 'strlen');
}

$allowed = [
    'Positive' => seatingPlanBehaviourOptions($settingGateway, 'positiveDescriptors'),
    'Negative' => seatingPlanBehaviourOptions($settingGateway, 'negativeDescriptors'),
];
$allowedLevels = seatingPlanBehaviourOptions($settingGateway, 'levels');

$values = [];

$values['behaviourWriteEnabled'] =
    ($_POST['behaviourWriteEnabled'] ?? 'N') === 'Y' ? 'Y' : 'N';

// Clamped rather than rejected: the form already constrains both, and a
// value outside the range only ever comes from a hand-built post.
$values['behaviourThreshold'] = (string) max(
    1,
    min(99, (int) ($_POST['behaviourThreshold'] ?? 3))
);
$values['behaviourRepeatEvery'] = (string) max(
    0,
    min(99, (int) ($_POST['behaviourRepeatEvery'] ?? 1))
);

foreach (['Positive', 'Negative'] as $type) {
    $descriptor = trim($_POST['behaviour'.$type.'Descriptor'] ?? '');
    $level = trim($_POST['behaviour'.$type.'Level'] ?? '');
    $comment = trim($_POST['behaviour'.$type.'Comment'] ?? '');

    $values['behaviour'.$type.'Descriptor'] =
        in_array($descriptor, $allowed[$type], true) ? $descriptor : '';
    $values['behaviour'.$type.'Level'] =
        in_array($level, $allowedLevels, true) ? $level : '';
    // gibbonBehaviour.comment is NOT NULL with no default, so an empty
    // incident falls back to the module's own text rather than being saved
    // as nothing (see RewardGateway::DEFAULT_COMMENT).
    $values['behaviour'.$type.'Comment'] = $comment;
}

foreach ($values as $name => $value) {
    $settingGateway->updateSettingByScope('Seating Plan', $name, $value);
}

$URL = $URL->withReturn('success0');
header("Location: {$URL}");
