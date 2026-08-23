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
use Gibbon\Services\Format;
use Gibbon\Module\SeatingPlan\MyLessonsTable;
use Gibbon\Module\SeatingPlan\Domain\TimetableSlotGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/seatingPlans.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('My Lessons'));

    $gibbonPersonID = $session->get('gibbonPersonID');
    $dateChosen = isset($_GET['date']);
    // The picker submits in the school's display format, so normalise before
    // anything touches the database.
    $date = $dateChosen
        ? Format::dateConvert($_GET['date'])
        : date('Y-m-d');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
        $date = date('Y-m-d');
        $dateChosen = false;
    }

    $slotGateway = $container->get(TimetableSlotGateway::class);

    // Only fall back when the viewer has not chosen a day themselves. A day
    // picked on purpose still shows that day, empty, rather than being
    // silently swapped for another one.
    if (!$dateChosen) {
        $date = $slotGateway->selectDefaultDate($gibbonPersonID, $date);
    }

    echo '<p>';
    echo __(
        'Pick a lesson to open its room. Where more than one class is '
        . 'timetabled into the same room at the same time, you get all of them '
        . 'together.'
    );
    echo '</p>';

    // DATE PICKER
    $form = Form::create('chooseDay', $session->get('absoluteURL').'/index.php', 'get');
    $form->setTitle(__('Day'));
    $form->setClass('noIntBorder w-full');
    $form->addHiddenValue('q', '/modules/Seating Plan/seatingPlans.php');

    $row = $form->addRow();
    $row->addLabel('date', __('Date'));
    $row->addDate('date')->setValue(Format::date($date))->required();

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Go'));

    echo $form->getOutput();

    // The table itself - roster, grouping and attendance completion - lives
    // in MyLessonsTable, shared with the Staff Dashboard hook
    // (hook_staffDashboard.php) so the two never drift into forks of the
    // same logic.
    echo $container->get(MyLessonsTable::class)->renderForDate($gibbonPersonID, $date);
}
