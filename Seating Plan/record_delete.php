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

use Gibbon\Services\Format;
use Gibbon\Forms\Prefab\DeleteForm;
use Gibbon\Module\SeatingPlan\BehaviourPolicy;
use Gibbon\Module\SeatingPlan\RecordAdmin;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/record_delete.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));

    return;
}

$type = RecordAdmin::resolveType($_GET['type'] ?? '');
$recordID = $_GET['id'] ?? '';

$page->breadcrumbs
    ->add(__('Rewards & Sanctions'), 'report_rewards.php')
    ->add(__('Delete Record'));

if ($type === '' || $recordID === '') {
    $page->addError(__('You have not specified one or more required parameters.'));

    return;
}

$record = $container->get(RecordAdmin::class)->find($type, $recordID);

if (empty($record)) {
    $page->addError(__('The specified record does not exist.'));

    return;
}

echo '<p>';

if ($type === RecordAdmin::REWARD) {
    echo __(
        'This would remove {student}\'s record of {rewards} rewards and '
        . '{sanctions} sanctions for {lesson} on {date}.',
        [
            'student'   => '<b>'.htmlspecialchars(
                RecordAdmin::studentName($record),
                ENT_QUOTES,
                'UTF-8'
            ).'</b>',
            'rewards'   => (int) $record['positive'],
            'sanctions' => (int) $record['negative'],
            'lesson'    => '<b>'.htmlspecialchars(
                RecordAdmin::lessonLabel($record),
                ENT_QUOTES,
                'UTF-8'
            ).'</b>',
            'date'      => Format::date(RecordAdmin::dateOf($record)),
        ]
    );
} else {
    echo __(
        'This would remove {student}\'s room exit from {lesson} on {date}.',
        [
            'student' => '<b>'.htmlspecialchars(
                RecordAdmin::studentName($record),
                ENT_QUOTES,
                'UTF-8'
            ).'</b>',
            'lesson'  => '<b>'.htmlspecialchars(
                RecordAdmin::lessonLabel($record),
                ENT_QUOTES,
                'UTF-8'
            ).'</b>',
            'date'    => Format::date(RecordAdmin::dateOf($record)),
        ]
    );
}

echo '</p>';

if (
    $type === RecordAdmin::REWARD
    && $container->get(BehaviourPolicy::class)->getSettings()['enabled']
) {
    echo Format::alert(
        __(
            'Any Behaviour record already written for this lesson stays as '
            . 'it is. Remove it in Manage Behaviour Records if it should '
            . 'go too.'
        ),
        'message'
    );
}

$form = DeleteForm::createForm(
    $session->get('absoluteURL').'/modules/'.$session->get('module')
        .'/record_deleteProcess.php?type='.$type.'&id='.$recordID
);

echo $form->getOutput();
