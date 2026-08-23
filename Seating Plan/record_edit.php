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

use Gibbon\Forms\Form;
use Gibbon\Http\Url;
use Gibbon\Services\Format;
use Gibbon\Module\SeatingPlan\BehaviourPolicy;
use Gibbon\Module\SeatingPlan\RecordAdmin;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/record_edit.php')
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
    ->add(__('Correct Record'));

if ($type === '' || $recordID === '') {
    $page->addError(__('You have not specified one or more required parameters.'));

    return;
}

$admin = $container->get(RecordAdmin::class);
$record = $admin->find($type, $recordID);

if (empty($record)) {
    $page->addError(__('The specified record does not exist.'));

    return;
}

echo '<p>';
echo __(
    'Correcting {student}\'s record for {lesson} on {date}, recorded by '
    . '{teacher}.',
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
        'teacher' => htmlspecialchars(
            RecordAdmin::recordedBy($record),
            ENT_QUOTES,
            'UTF-8'
        ),
    ]
);
echo '</p>';

// Where the school writes points into the Behaviour module as well, those
// records belong to Behaviour now. Saying so is better than either leaving
// an administrator to discover it or reaching into another module's data
// from here.
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

$form = Form::create(
    'recordEdit',
    $session->get('absoluteURL').'/modules/'.$session->get('module')
        .'/record_editProcess.php?type='.$type.'&id='.$recordID
);
$form->addHiddenValue('address', $session->get('address'));

if ($type === RecordAdmin::REWARD) {
    $row = $form->addRow();
    $row->addLabel('positive', __('Rewards'));
    $row->addNumber('positive')
        ->minimum(0)
        ->maximum(999)
        ->setValue((int) $record['positive'])
        ->required();

    $row = $form->addRow();
    $row->addLabel('negative', __('Sanctions'));
    $row->addNumber('negative')
        ->minimum(0)
        ->maximum(999)
        ->setValue((int) $record['negative'])
        ->required();

    $form->addRow()->addContent(
        '<span class="text-xs italic">'
        .__('Setting both to zero leaves the record in place. Use Delete to '
            . 'remove it altogether.')
        .'</span>'
    );
} else {
    // One date and two times rather than two full timestamps: a student
    // leaving a room and coming back is a single lesson's worth of
    // minutes, and asking for the day twice invites the two halves to
    // disagree.
    $row = $form->addRow();
    $row->addLabel('date', __('Date'));
    $row->addDate('date')
        ->setValue(Format::date(RecordAdmin::dateOf($record)))
        ->required();

    $row = $form->addRow();
    $row->addLabel('timeOut', __('Out'))
        ->description(__('When the student left the room.'));
    $row->addTime('timeOut')
        ->setValue(substr((string) $record['timeOut'], 11, 5))
        ->required();

    $row = $form->addRow();
    $row->addLabel('timeIn', __('Back'))
        ->description(__('When they returned. Leave blank if they are '
            . 'still out.'));
    $row->addTime('timeIn')
        ->setValue(
            !empty($record['timeIn'])
                ? substr((string) $record['timeIn'], 11, 5)
                : ''
        );
}

$row = $form->addRow();
$row->addFooter();
$row->addSubmit();

echo $form->getOutput();

echo '<p class="mt-4">';
echo '<a class="text-sm underline" href="'
    .htmlspecialchars(
        (string) Url::fromModuleRoute('Seating Plan', 'report_student')
            ->withQueryParam('gibbonPersonID', $record['gibbonPersonID']),
        ENT_QUOTES,
        'UTF-8'
    ).'">'.__('Back to this student\'s records').'</a>';
echo '</p>';
