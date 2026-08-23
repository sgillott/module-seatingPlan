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
use Gibbon\Module\SeatingPlan\Domain\RewardTallyGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/report_rewards.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));

    return;
}

// Reports is a grouped action: _all sees every student, _my sees only
// students in classes the viewer teaches, plus anything they recorded
// themselves. Whichever the viewer holds with the higher precedence wins.
$highestAction = getHighestGroupedAction($guid, $_GET['q'], $connection2);

if ($highestAction === false) {
    $page->addError(__('The highest grouped action cannot be determined.'));

    return;
}

$page->breadcrumbs->add(__('Rewards & Sanctions'));
$page->scripts->add('chart');

echo ReportView::navigation('report_rewards');

$filters = new ReportFilters($session, $pdo);
$values = $filters->values();
$groupBy = $filters->grouping();
$grouping = ReportGrouping::definition($groupBy);

echo $filters->getOutput($values);

$gateway = $container->get(RewardTallyGateway::class);

$criteria = $gateway->newQueryCriteria(true)
    ->pageSize(50)
    ->fromPOST('seatingPlanRewards');

$records = $gateway->queryTally(
    $criteria,
    $groupBy,
    $values,
    $highestAction === 'Reports_my' ? $session->get('gibbonPersonID') : null
);

// Totals across everything the filters select, not just this page - a
// headline number that moved when the reader turned the page would be
// telling them something untrue.
$totals = $gateway->summariseTally(
    $values,
    $highestAction === 'Reports_my' ? $session->get('gibbonPersonID') : null
);

echo ReportView::summary([
    __('Rewards')   => $totals['positive'],
    __('Sanctions') => $totals['negative'],
    __('Students')  => $totals['students'],
    __('Lessons')   => $totals['lessons'],
]);

echo ReportView::stackedChart(
    'seatingPlanRewardsChart',
    $records,
    $grouping,
    [
        'positive' => ['label' => __('Rewards'), 'colour' => 'rgba(21, 128, 61, 1.0)'],
        'negative' => ['label' => __('Sanctions'), 'colour' => 'rgba(185, 28, 28, 1.0)'],
    ],
    __('Rewards and Sanctions by {group}')
);

$table = DataTable::createPaginated('seatingPlanRewards', $criteria);
$table->setTitle($grouping['label']);
$table->setDescription(__(
    'Every reward and sanction given from a seating plan, whether or not a '
    . 'Behaviour record was also written for it.'
));

ReportView::addGroupColumn($table, $grouping);

$table->addColumn('positive', __('Rewards'))
    ->format(function ($row) {
        return ReportView::count($row['positive'], 'text-green-700');
    });

$table->addColumn('negative', __('Sanctions'))
    ->format(function ($row) {
        return ReportView::count($row['negative'], 'text-red-700');
    });

// Not sortable: unlike the columns above it, this one has no matching
// expression in the query, so a sort link would order by a name the
// database has never heard of.
$table->addColumn('net', __('Net'))
    ->description(__('Rewards less sanctions'))
    ->notSortable()
    ->format(function ($row) {
        $net = (int) $row['positive'] - (int) $row['negative'];

        return ReportView::count(
            ($net > 0 ? '+' : '').$net,
            $net < 0 ? 'text-red-700' : 'text-green-700'
        );
    });

$table->addColumn('lessons', __('Lessons'))
    ->description(__('Lessons with at least one point'));

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

// Only a student row has a drill-down to go to: the per-student page shows
// one student's own points and exits in date order.
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
