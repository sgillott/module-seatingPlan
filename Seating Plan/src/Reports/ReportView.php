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

namespace Gibbon\Module\SeatingPlan\Reports;

use Gibbon\Http\Url;
use Gibbon\UI\Chart\Chart;
use Gibbon\Services\Format;
use Gibbon\Domain\DataSet;
use Gibbon\Tables\DataTable;

/**
 * The parts the rewards report and the room-exits report draw the same way:
 * the link strip between them, the headline totals, the stacked bar chart,
 * and the first column of the table - which is a photo and a name when the
 * report is grouped by a person, and a plain label otherwise.
 *
 * Everything here is static and takes only what it needs. It is a view
 * helper, not a service: nothing in it touches the database.
 */
class ReportView
{
    /**
     * The link strip between the module's report pages.
     *
     * The two reports share one grouped action, so they share one menu
     * entry too - which means the only way between them is a link on the
     * page itself.
     *
     * @param string $current The page name currently being viewed.
     *
     * @return string
     */
    public static function navigation($current): string
    {
        $pages = [
            'report_rewards'   => __('Rewards & Sanctions'),
            'report_roomExits' => __('Room Exits'),
        ];

        $links = [];

        foreach ($pages as $page => $label) {
            $classes = $page === $current
                ? 'bg-gray-800 text-white border-gray-800'
                : 'bg-white text-gray-700 border-gray-400 hover:bg-gray-100';

            $links[] = '<a class="inline-block px-4 py-2 text-sm border rounded '
                .$classes.'" href="'
                .htmlspecialchars(
                    (string) Url::fromModuleRoute('Seating Plan', $page),
                    ENT_QUOTES,
                    'UTF-8'
                ).'">'.$label.'</a>';
        }

        return '<div class="flex gap-2 mb-4">'.implode('', $links).'</div>';
    }

    /**
     * Headline totals across everything currently shown.
     *
     * @param array $totals Label => number.
     *
     * @return string
     */
    public static function summary(array $totals): string
    {
        $tiles = [];

        foreach ($totals as $label => $value) {
            $tiles[] = '<div class="flex-1 px-4 py-3 bg-gray-100 border rounded '
                .'text-center">'
                .'<div class="text-2xl font-bold text-gray-800">'
                .htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
                .'</div>'
                .'<div class="text-xs uppercase tracking-wide text-gray-600">'
                .htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8')
                .'</div></div>';
        }

        return '<div class="flex gap-3 mb-4">'.implode('', $tiles).'</div>';
    }

    /**
     * A stacked bar chart over the rows currently on screen.
     *
     * Deliberately the rows on screen and not the whole result set: the
     * chart and the table underneath it must agree, and a chart of five
     * hundred students would be unreadable anyway. Narrow the filters to
     * narrow the chart.
     *
     * @param string  $elementID One HTML id, unique on the page.
     * @param DataSet $records   The rows being shown.
     * @param array   $grouping  The ReportGrouping definition in use.
     * @param array   $series    Column name => ['label' => ..., 'colour' => ...].
     * @param string  $title     Chart title, with a {group} placeholder.
     *
     * @return string Empty when there is nothing to draw.
     */
    public static function stackedChart(
        $elementID,
        DataSet $records,
        array $grouping,
        array $series,
        $title
    ): string {
        if ($records->count() === 0) {
            return '';
        }

        $labels = [];

        foreach ($records as $row) {
            $labels[] = self::groupLabel($row, $grouping);
        }

        $chart = Chart::create($elementID, 'bar');
        $chart->setTitle(str_replace('{group}', $grouping['label'], $title));
        $chart->setLabels($labels);
        $chart->useDefaultColors(false);
        $chart->setOptions([
            'height'    => '28vh',
            'animation' => false,
            'scales'    => [
                'x' => ['stacked' => true, 'gridLines' => ['display' => false]],
                'y' => ['stacked' => true, 'beginAtZero' => true],
            ],
        ]);

        foreach ($series as $column => $definition) {
            $chart->addDataset($column, $definition['label'])
                ->setData(array_map('intval', $records->getColumn($column)))
                ->setProperty('backgroundColor', $definition['colour'])
                ->setProperty('borderColor', $definition['colour']);
        }

        return '<div class="mb-4" style="overflow: visible;">'
            .$chart->render().'</div>';
    }

    /**
     * The report's first column: a circular photo and a properly formatted
     * name when the rows are people, a plain label otherwise.
     *
     * @param DataTable $table    The table being built.
     * @param array     $grouping The ReportGrouping definition in use.
     *
     * @return void
     */
    public static function addGroupColumn(DataTable $table, array $grouping)
    {
        if (empty($grouping['person'])) {
            $table->addColumn('groupName', $grouping['label'])
                ->format(function ($row) {
                    return $row['groupName'] !== '' && $row['groupName'] !== '.'
                        ? $row['groupName']
                        : '<span class="text-gray-500">'.__('Not recorded').'</span>';
                });

            return;
        }

        $role = $grouping['role'];

        $table->addColumn('groupName', $grouping['label'])
            ->format(function ($row) use ($role) {
                $photo = Format::userPhoto(
                    $row['image_240'] ?? '',
                    'xs',
                    'rounded-full mr-2 align-middle'
                );

                $name = Format::name(
                    '',
                    $row['preferredName'] ?? '',
                    $row['surname'] ?? '',
                    $role,
                    true
                );

                $detail = trim(implode(' · ', array_filter([
                    $row['yearGroupName'] ?? '',
                    $row['formGroupName'] ?? '',
                ])));

                return '<div class="flex items-center">'.$photo
                    .'<div><div>'.$name.'</div>'
                    .($detail !== ''
                        ? '<div class="text-xxs text-gray-600">'
                            .htmlspecialchars($detail, ENT_QUOTES, 'UTF-8')
                            .'</div>'
                        : '')
                    .'</div></div>';
            });
    }

    /**
     * A number, greyed out when it is zero so a busy table reads at a
     * glance.
     *
     * @param mixed  $value The count.
     * @param string $class Tailwind classes for a non-zero value.
     *
     * @return string
     */
    public static function count($value, $class = ''): string
    {
        $isZero = (int) $value === 0 || $value === '+0';

        return '<span class="font-bold '
            .($isZero ? 'text-gray-400' : $class).'">'
            .htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
            .'</span>';
    }

    /**
     * Where one entry happened, for the per-student page: the class, the
     * room and the period, with whichever of those the row actually has.
     *
     * A class of "." means the entry was recorded against class 0 - the
     * student was in more than one of the room's co-taught classes at the
     * time, so no class can be named without guessing.
     *
     * @param array $row A row from either gateway's queryStudentHistory().
     *
     * @return string
     */
    public static function lessonLabel(array $row): string
    {
        $className = trim((string) ($row['className'] ?? ''), " \t.");

        $parts = array_filter([
            $className !== '' ? $className : null,
            $row['spaceName'] ?? null,
            $row['periodName'] ?? null,
        ]);

        if (empty($parts)) {
            return '<span class="text-gray-500">'.__('Not recorded').'</span>';
        }

        return htmlspecialchars(implode(' · ', $parts), ENT_QUOTES, 'UTF-8');
    }

    /**
     * A number of minutes as something readable - "45m", "1h 20m".
     *
     * Plain text with no markup of its own: this is used both inside a
     * table cell, which renders HTML, and inside a summary tile, which
     * escapes what it is given. A styled span here would be printed as
     * literal angle brackets in the tile.
     *
     * @param mixed $minutes Whole minutes.
     *
     * @return string
     */
    public static function duration($minutes): string
    {
        $minutes = (int) $minutes;

        if ($minutes <= 0) {
            return '0m';
        }

        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        return $remainder > 0 ? $hours.'h '.$remainder.'m' : $hours.'h';
    }

    /**
     * Greys a value out, for a table cell whose number is zero and so says
     * nothing worth reading.
     *
     * @param string $text  Already-safe text.
     * @param bool   $muted Whether to grey it.
     *
     * @return string
     */
    public static function muted($text, bool $muted = true): string
    {
        $text = htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');

        return $muted ? '<span class="text-gray-400">'.$text.'</span>' : $text;
    }

    /**
     * One row's label, however the report is grouped.
     *
     * @param array $row      The row.
     * @param array $grouping The ReportGrouping definition in use.
     *
     * @return string
     */
    public static function groupLabel(array $row, array $grouping): string
    {
        if (!empty($grouping['person'])) {
            return trim(
                ($row['preferredName'] ?? '').' '.($row['surname'] ?? '')
            );
        }

        $label = trim((string) ($row['groupName'] ?? ''));

        return $label !== '' && $label !== '.' ? $label : __('Not recorded');
    }
}
