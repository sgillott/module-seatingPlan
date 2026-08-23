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
use Gibbon\Tables\DataTable;
use Gibbon\Domain\User\UserGateway;
use Gibbon\Module\SeatingPlan\Reports\ReportAccess;
use Gibbon\Module\SeatingPlan\Reports\ReportView;
use Gibbon\Module\SeatingPlan\Domain\RewardTallyGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomExitGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/report_student.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));

    return;
}

$highestAction = getHighestGroupedAction($guid, $_GET['q'], $connection2);

if ($highestAction === false) {
    $page->addError(__('The highest grouped action cannot be determined.'));

    return;
}

$gibbonPersonID = $_GET['gibbonPersonID'] ?? '';

if ($gibbonPersonID === '') {
    $page->addError(__('You have not specified one or more required parameters.'));

    return;
}

$scopeTo = $highestAction === 'Reports_my'
    ? $session->get('gibbonPersonID')
    : null;

if (!$container->get(ReportAccess::class)->canViewStudent($gibbonPersonID, $scopeTo)) {
    $page->addError(__('The specified record does not exist.'));
    return;
}

$student = $container->get(UserGateway::class)->getByID($gibbonPersonID);

if (empty($student)) {
    $page->addError(__('The specified record does not exist.'));
    return;
}

$rewardGateway = $container->get(RewardTallyGateway::class);
$exitGateway = $container->get(RoomExitGateway::class);


$dateStart = !empty($_GET['dateStart'])
    ? Format::dateConvert($_GET['dateStart'])
    : $session->get('gibbonSchoolYearFirstDay');
$dateEnd = !empty($_GET['dateEnd'])
    ? Format::dateConvert($_GET['dateEnd'])
    : date('Y-m-d');

$filters = ['dateStart' => $dateStart, 'dateEnd' => $dateEnd];

$page->breadcrumbs
    ->add(__('Rewards & Sanctions'), 'report_rewards.php')
    ->add(Format::name(
        '',
        $student['preferredName'],
        $student['surname'],
        'Student',
        true
    ));

echo '<div class="flex items-center gap-4 mb-4">';
echo Format::userPhoto($student['image_240'], 'md', 'rounded-full');
echo '<div>';
echo '<div class="text-xl font-bold">'
    .Format::name('', $student['preferredName'], $student['surname'], 'Student')
    .'</div>';
echo '<div class="text-sm text-gray-600">'
    .Format::dateRange($dateStart, $dateEnd).'</div>';
echo '<div class="mt-2"><a class="text-sm underline" href="'
    .htmlspecialchars(
        (string) Url::fromModuleRoute('Seating Plan', 'report_rewards'),
        ENT_QUOTES,
        'UTF-8'
    ).'">'.__('Back to the report').'</a></div>';
echo '</div></div>';

/* ------------------------------------------- rewards and sanctions */

$rewardCriteria = $rewardGateway->newQueryCriteria(true)
    ->sortBy('r.date', 'DESC')
    ->pageSize(25)
    ->fromPOST('seatingPlanStudentRewards');

$rewards = $rewardGateway->queryStudentHistory(
    $rewardCriteria,
    $gibbonPersonID,
    $filters,
    $scopeTo
);

// Totals across the whole date range, not just the page being shown.
$totals = $rewardGateway->summariseTally(
    array_merge($filters, ['gibbonPersonID' => $gibbonPersonID]),
    $scopeTo
);
$exitTotals = $exitGateway->summariseExits(
    array_merge($filters, ['gibbonPersonID' => $gibbonPersonID]),
    $scopeTo
);

echo ReportView::summary([
    __('Rewards')    => $totals['positive'],
    __('Sanctions')  => $totals['negative'],
    __('Lessons')    => $totals['lessons'],
    __('Room Exits') => $exitTotals['exits'],
    __('Time Out')   => ReportView::duration($exitTotals['minutes']),
]);

$table = DataTable::createPaginated('seatingPlanStudentRewards', $rewardCriteria);
$table->setTitle(__('Rewards & Sanctions'));

$table->addColumn('date', __('Date'))
    ->format(function ($row) {
        return Format::date($row['date']);
    });

$table->addColumn('lesson', __('Lesson'))
    ->notSortable()
    ->format(function ($row) {
        return ReportView::lessonLabel($row);
    });

$table->addColumn('positive', __('Rewards'))
    ->format(function ($row) {
        return ReportView::count($row['positive'], 'text-green-700');
    });

$table->addColumn('negative', __('Sanctions'))
    ->format(function ($row) {
        return ReportView::count($row['negative'], 'text-red-700');
    });

$table->addColumn('teacher', __('Recorded By'))
    ->notSortable()
    ->format(function ($row) {
        return Format::name(
            $row['teacherTitle'],
            $row['teacherPreferredName'],
            $row['teacherSurname'],
            'Staff'
        );
    });

// Correcting a record is a separate permission from being allowed to look
// at one, so a teacher with Reports_my sees these rows and no buttons.
$canManage = isActionAccessible(
    $guid,
    $connection2,
    '/modules/Seating Plan/record_edit.php'
);

if ($canManage) {
    $table->addActionColumn()
        ->format(function ($row, $actions) {
            $actions->addAction('edit', __('Correct'))
                ->setURL('/modules/Seating Plan/record_edit.php')
                ->addParam('type', 'reward')
                ->addParam('id', $row['seatingPlanRewardID']);

            $actions->addAction('delete', __('Delete'))
                ->setURL('/modules/Seating Plan/record_delete.php')
                ->addParam('type', 'reward')
                ->addParam('id', $row['seatingPlanRewardID']);
        });
}

echo $table->render($rewards);

/* ------------------------------------------------------- room exits */

$exitCriteria = $exitGateway->newQueryCriteria(true)
    ->sortBy('r.timeOut', 'DESC')
    ->pageSize(25)
    ->fromPOST('seatingPlanStudentExits');

$exits = $exitGateway->queryStudentHistory(
    $exitCriteria,
    $gibbonPersonID,
    $filters,
    $scopeTo
);

$table = DataTable::createPaginated('seatingPlanStudentExits', $exitCriteria);
$table->setTitle(__('Room Exits'));

$table->addColumn('timeOut', __('Out'))
    ->format(function ($row) {
        return Format::dateTime($row['timeOut']);
    });

$table->addColumn('timeIn', __('Back'))
    ->format(function ($row) {
        return !empty($row['timeIn'])
            ? Format::time($row['timeIn'])
            : '<span class="text-red-700">'.__('Still out').'</span>';
    });

$table->addColumn('minutes', __('Time Out'))
    ->format(function ($row) {
        return $row['minutes'] !== null
            ? ReportView::muted(
                ReportView::duration($row['minutes']),
                (int) $row['minutes'] === 0
            )
            : '';
    });

$table->addColumn('lesson', __('Lesson'))
    ->notSortable()
    ->format(function ($row) {
        return ReportView::lessonLabel($row);
    });

$table->addColumn('teacher', __('Recorded By'))
    ->notSortable()
    ->format(function ($row) {
        return Format::name(
            $row['teacherTitle'],
            $row['teacherPreferredName'],
            $row['teacherSurname'],
            'Staff'
        );
    });

if ($canManage) {
    $table->addActionColumn()
        ->format(function ($row, $actions) {
            $actions->addAction('edit', __('Correct'))
                ->setURL('/modules/Seating Plan/record_edit.php')
                ->addParam('type', 'exit')
                ->addParam('id', $row['seatingPlanRoomExitID']);

            $actions->addAction('delete', __('Delete'))
                ->setURL('/modules/Seating Plan/record_delete.php')
                ->addParam('type', 'exit')
                ->addParam('id', $row['seatingPlanRoomExitID']);
        });
}

echo $table->render($exits);
