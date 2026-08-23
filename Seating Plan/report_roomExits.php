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
use Gibbon\Tables\DataTable;
use Gibbon\Module\SeatingPlan\Reports\ReportFilters;
use Gibbon\Module\SeatingPlan\Reports\ReportGrouping;
use Gibbon\Module\SeatingPlan\Reports\ReportView;
use Gibbon\Module\SeatingPlan\Domain\RoomExitGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/report_roomExits.php')
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

$page->breadcrumbs->add(__('Room Exits'));
$page->scripts->add('chart');

echo ReportView::navigation('report_roomExits');

$filters = new ReportFilters($session, $pdo);
$values = $filters->values();
$groupBy = $filters->grouping();
$grouping = ReportGrouping::definition($groupBy);

echo $filters->getOutput($values);

$gateway = $container->get(RoomExitGateway::class);

$criteria = $gateway->newQueryCriteria(true)
    ->pageSize(50)
    ->fromPOST('seatingPlanRoomExits');

$records = $gateway->queryExits(
    $criteria,
    $groupBy,
    $values,
    $highestAction === 'Reports_my' ? $session->get('gibbonPersonID') : null
);

// Totals across everything the filters select, not just this page - see
// the same note in report_rewards.php.
$totals = $gateway->summariseExits(
    $values,
    $highestAction === 'Reports_my' ? $session->get('gibbonPersonID') : null
);

echo ReportView::summary([
    __('Exits')     => $totals['exits'],
    __('Time Out')  => ReportView::duration($totals['minutes']),
    __('Still Out') => $totals['stillOut'],
    __('Students')  => $totals['students'],
]);

echo ReportView::stackedChart(
    'seatingPlanRoomExitsChart',
    $records,
    $grouping,
    ['exits' => ['label' => __('Exits'), 'colour' => 'rgba(29, 109, 163, 1.0)']],
    __('Room Exits by {group}')
);

$table = DataTable::createPaginated('seatingPlanRoomExits', $criteria);
$table->setTitle($grouping['label']);
$table->setDescription(__(
    'Every time a student was marked out of a room from a seating plan, and '
    . 'how long they were gone.'
));

ReportView::addGroupColumn($table, $grouping);

$table->addColumn('exits', __('Exits'))
    ->format(function ($row) {
        return ReportView::count($row['exits'], 'text-blue-700');
    });

$table->addColumn('minutes', __('Time Out'))
    ->description(__('Completed exits only'))
    ->format(function ($row) {
        return ReportView::muted(
            ReportView::duration($row['minutes']),
            (int) $row['minutes'] === 0
        );
    });

// Not sortable: unlike the columns above it, this one has no matching
// expression in the query, so a sort link would order by a name the
// database has never heard of.
$table->addColumn('average', __('Average'))
    ->description(__('Per completed exit'))
    ->notSortable()
    ->format(function ($row) {
        // Open exits have no duration yet, so they are left out of both
        // halves of this average rather than counted as zero minutes.
        $completed = (int) $row['exits'] - (int) $row['stillOut'];

        return $completed > 0
            ? ReportView::duration((int) round($row['minutes'] / $completed))
            : '';
    });

$table->addColumn('stillOut', __('Still Out'))
    ->format(function ($row) {
        return (int) $row['stillOut'] > 0
            ? ReportView::count($row['stillOut'], 'text-red-700')
            : '';
    });

$table->addColumn('dates', __('Range'))
    ->sortable(['dateFirst'])
    ->format(function ($row) {
        if (empty($row['dateFirst'])) {
            return '';
        }

        return $row['dateFirst'] === $row['dateLast']
            ? Format::date($row['dateFirst'])
            : Format::dateRange($row['dateFirst'], $row['dateLast']);
    });

if ($groupBy === 'student') {
    $dateStart = Format::date($values['dateStart']);
    $dateEnd = Format::date($values['dateEnd']);

    $table->addActionColumn()
        ->format(function ($row, $actions) use ($dateStart, $dateEnd) {
            $actions->addAction('view', __('View Details'))
                ->setURL('/modules/Seating Plan/report_student.php')
                ->addParam('gibbonPersonID', $row['groupID'])
                ->addParam('dateStart', $dateStart)
                ->addParam('dateEnd', $dateEnd);
        });
}

echo $table->render($records);
