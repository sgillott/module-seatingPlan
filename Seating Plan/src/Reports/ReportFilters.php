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

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Contracts\Services\Session;
use Gibbon\Contracts\Database\Connection;
use Gibbon\Forms\DatabaseFormFactory;

/**
 * The filter bar both report pages share: a date range, then any of
 * student, year group, form group, class, subject, room and recording
 * teacher, then what to group by.
 *
 * Values arrive in the query string, because Form::createSearch() submits
 * by GET - which also means a filtered report is a link somebody can send
 * to a colleague.
 */
class ReportFilters
{
    /**
     * @var Session
     */
    private $session;

    /**
     * @var Connection
     */
    private $pdo;

    /**
     * @param Session    $session The current session.
     * @param Connection $pdo     For the database-backed select elements.
     */
    public function __construct(Session $session, Connection $pdo)
    {
        $this->session = $session;
        $this->pdo = $pdo;
    }

    /**
     * The filters as the report gateways want them: dates already converted
     * from the school's display format to Y-m-d, everything else as it came.
     *
     * The date range defaults to the school year so far, so a report that
     * has just been opened shows something rather than everything ever.
     *
     * @return array
     */
    public function values(): array
    {
        $dateStart = $_GET['dateStart'] ?? '';
        $dateEnd = $_GET['dateEnd'] ?? '';

        $values = [
            'dateStart' => $dateStart !== ''
                ? Format::dateConvert($dateStart)
                : $this->session->get('gibbonSchoolYearFirstDay'),
            'dateEnd'   => $dateEnd !== ''
                ? Format::dateConvert($dateEnd)
                : date('Y-m-d'),
        ];

        foreach ($this->identifiers() as $name) {
            $values[$name] = $_GET[$name] ?? '';
        }

        // An inverted range is corrected rather than reported as an error,
        // the same thing core's own Attendance Trends report does.
        if ($values['dateStart'] > $values['dateEnd']) {
            $swap = $values['dateStart'];
            $values['dateStart'] = $values['dateEnd'];
            $values['dateEnd'] = $swap;
        }

        return $values;
    }

    /**
     * @return array The non-date filter names, in form order.
     */
    public function identifiers(): array
    {
        return [
            'gibbonPersonID',
            'gibbonYearGroupID',
            'gibbonFormGroupID',
            'gibbonCourseID',
            'gibbonCourseClassID',
            'gibbonPersonIDCreator',
            'gibbonSpaceID',
        ];
    }

    /**
     * How the report is grouped, always one of ReportGrouping's own keys.
     *
     * @return string
     */
    public function grouping(): string
    {
        return ReportGrouping::resolve($_GET['groupBy'] ?? '');
    }

    /**
     * The filter form, ready to echo.
     *
     * @param array $values The output of values(), so the form shows what
     *                      is actually being applied rather than what was
     *                      typed.
     *
     * @return string
     */
    public function getOutput(array $values): string
    {
        $schoolYearID = $this->session->get('gibbonSchoolYearID');

        $form = Form::createSearch();
        $form->setFactory(DatabaseFormFactory::create($this->pdo));

        $row = $form->addRow();
        $row->addLabel('dateStart', __('Start Date'));
        $row->addDate('dateStart')->setValue(Format::date($values['dateStart']));

        $row = $form->addRow();
        $row->addLabel('dateEnd', __('End Date'));
        $row->addDate('dateEnd')->setValue(Format::date($values['dateEnd']));

        $row = $form->addRow();
        $row->addLabel('gibbonPersonID', __('Student'));
        $row->addSelectStudent('gibbonPersonID', $schoolYearID)
            ->selected($values['gibbonPersonID'])
            ->placeholder();

        $row = $form->addRow();
        $row->addLabel('gibbonYearGroupID', __('Year Group'));
        $row->addSelectYearGroup('gibbonYearGroupID')
            ->selected($values['gibbonYearGroupID'])
            ->placeholder();

        $row = $form->addRow();
        $row->addLabel('gibbonFormGroupID', __('Form Group'));
        $row->addSelectFormGroup('gibbonFormGroupID', $schoolYearID)
            ->selected($values['gibbonFormGroupID'])
            ->placeholder();

        // Core's own course selects are scoped to a year group, which is
        // not what is wanted here: the filter is "any class of this
        // subject". A plain query gives every course in the school year.
        $row = $form->addRow();
        $row->addLabel('gibbonCourseID', __('Subject'));
        $row->addSelect('gibbonCourseID')
            ->fromQuery(
                $this->pdo,
                "SELECT gibbonCourseID AS value, name
                    FROM gibbonCourse
                    WHERE gibbonSchoolYearID=:gibbonSchoolYearID
                    ORDER BY name",
                ['gibbonSchoolYearID' => $schoolYearID]
            )
            ->selected($values['gibbonCourseID'])
            ->placeholder();

        $row = $form->addRow();
        $row->addLabel('gibbonCourseClassID', __('Class'));
        $row->addSelectClass('gibbonCourseClassID', $schoolYearID)
            ->selected($values['gibbonCourseClassID'])
            ->placeholder();

        $row = $form->addRow();
        $row->addLabel('gibbonPersonIDCreator', __('Recorded By'));
        $row->addSelectStaff('gibbonPersonIDCreator')
            ->selected($values['gibbonPersonIDCreator'])
            ->placeholder();

        $row = $form->addRow();
        $row->addLabel('gibbonSpaceID', __('Room'));
        $row->addSelectSpace('gibbonSpaceID')
            ->selected($values['gibbonSpaceID'])
            ->placeholder();

        $row = $form->addRow();
        $row->addLabel('groupBy', __('Group By'))
            ->description(__('What each row and each bar counts.'));
        $row->addSelect('groupBy')
            ->fromArray(ReportGrouping::options())
            ->selected($this->grouping());

        $row = $form->addRow();
        $row->addSearchSubmit($this->session, __('Clear Filters'));

        return $form->getOutput();
    }
}
