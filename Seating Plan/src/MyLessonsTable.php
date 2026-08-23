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

namespace Gibbon\Module\SeatingPlan;

use Gibbon\Http\Url;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\Domain\DataSet;
use Gibbon\Contracts\Services\Session;
use Gibbon\Domain\Timetable\TimetableDayDateGateway;
use Gibbon\Module\SeatingPlan\Domain\AttendanceGateway;
use Gibbon\Module\SeatingPlan\Domain\StudentRosterGateway;
use Gibbon\Module\SeatingPlan\Domain\TimetableSlotGateway;

/**
 * "My Lessons": a teacher's timetabled periods for one day, grouped by room,
 * each with a seat button. This is the module's own entry page
 * (seatingPlans.php) and, since Phase 5, the Staff Dashboard hook - both
 * render from here, rather than one being a fork of the other.
 */
class MyLessonsTable
{
    /**
     * @var TimetableSlotGateway
     */
    private $slotGateway;

    /**
     * @var StudentRosterGateway
     */
    private $rosterGateway;

    /**
     * @var AttendanceGateway
     */
    private $attendanceGateway;

    /**
     * @var TimetableDayDateGateway
     */
    private $ttDayDateGateway;

    /**
     * @var Session
     */
    private $session;

    public function __construct(
        TimetableSlotGateway $slotGateway,
        StudentRosterGateway $rosterGateway,
        AttendanceGateway $attendanceGateway,
        TimetableDayDateGateway $ttDayDateGateway,
        Session $session
    ) {
        $this->slotGateway = $slotGateway;
        $this->rosterGateway = $rosterGateway;
        $this->attendanceGateway = $attendanceGateway;
        $this->ttDayDateGateway = $ttDayDateGateway;
        $this->session = $session;
    }

    /**
     * The rendered table (plus an empty-state message when there is
     * nothing to show) for one teacher's one day.
     *
     * @param string $gibbonPersonID The viewing teacher.
     * @param string $date           Y-m-d.
     *
     * @return string
     */
    public function renderForDate(string $gibbonPersonID, string $date): string
    {
        $rows = $this->buildRows($gibbonPersonID, $date);

        $table = DataTable::create('myLessons');
        $table->setTitle(Format::dateReadable($date));

        $table->addColumn('period', __('Period'))
            ->format(
                function ($values) {
                    return '<b>'.htmlPrep($values['period']).'</b><br/>'
                        . '<span class="text-xs text-gray-600">'
                        . Format::timeRange($values['timeStart'], $values['timeEnd'])
                        . '</span>';
                }
            );

        $table->addColumn('className', __('Class'))
            ->format(
                function ($values) {
                    $name = '<b>'.htmlPrep($values['className']).'</b>';

                    if ($values['extraClassCount'] > 0) {
                        $name .= '<br/><span class="text-xs text-gray-600">'
                            . __n(
                                'with {count} other class',
                                'with {count} other classes',
                                $values['extraClassCount'],
                                ['count' => $values['extraClassCount']]
                            )
                            . '</span>';
                    }

                    if (!empty($values['coveringFor'])) {
                        $name .= '<br/><span class="tag dull">'
                            . __('Covering for {name}', ['name' => htmlPrep($values['coveringFor'])])
                            . '</span>';
                    }

                    return $name;
                }
            );

        $table->addColumn('roomName', __('Room'))
            ->format(
                function ($values) {
                    if (empty($values['roomName'])) {
                        return '<span class="text-xs italic text-gray-600">'
                            . __('No room on the timetable').'</span>';
                    }

                    $room = htmlPrep($values['roomName']);

                    if ($values['moved']) {
                        $room .= ' <span class="tag message">'.__('Moved').'</span>';
                    }

                    return $room;
                }
            );

        $table->addColumn('layoutCount', __('Layout'))
            ->format(
                function ($values) {
                    if (empty($values['roomName'])) {
                        return '';
                    }

                    return $values['layoutCount'] > 0
                        ? '<span class="tag success">'.__('Ready').'</span>'
                        : '<span class="tag dull">'.__('Not drawn yet').'</span>';
                }
            );

        $table->addColumn('attendanceStatus', __('Attendance'))
            ->format(
                function ($values) use ($date) {
                    $eligible = $values['attendanceEligible'] ?? 0;
                    $marked = $values['attendanceMarked'] ?? 0;

                    switch ($values['attendanceStatus'] ?? '') {
                        case 'complete':
                            // Same icon core's own Staff Dashboard Planner
                            // tab uses for this exact question, on
                            // TodaysLessonsTable's Attendance column.
                            $icon = icon('solid', 'check', 'size-6 fill-current text-green-600');
                            $title = __('All {marked} of {eligible} registered', [
                                'marked' => $marked, 'eligible' => $eligible,
                            ]);
                            break;
                        case 'partial':
                            // Core has no circular exclamation icon; 'warning'
                            // (a triangle) is the closest real one, reused
                            // rather than hand-drawn. Red, not amber - matches
                            // register mode's own "not yet recorded" tone
                            // (its tile border uses the same #b91c1c).
                            $icon = icon('solid', 'warning', 'size-6 fill-current text-red-700');
                            $title = __('{marked} of {eligible} registered so far', [
                                'marked' => $marked, 'eligible' => $eligible,
                            ]);
                            break;
                        case 'none':
                            $icon = icon('solid', 'question-mark', 'size-6 fill-current text-gray-400');
                            $title = __('Not yet started');
                            break;
                        default:
                            return '';
                    }

                    // Straight into the room in register mode - if register
                    // mode is not actually available there (no layout drawn
                    // yet, say), room.php itself falls back to furniture
                    // mode, exactly as the "Open Room" action's own mode=
                    // param does.
                    $registerURL = Url::fromHandlerRoute('fullscreen.php')
                        ->withQueryParams([
                            'q'      => '/modules/Seating Plan/room.php',
                            'period' => $values['gibbonTTDayRowClassID'],
                            'date'   => $date,
                            'mode'   => 'register',
                        ]);

                    // A plain anchor, not a DataTable Action: nothing here
                    // opts into htmx boosting the way Action::getOutput()
                    // does by default, so this already behaves as an
                    // ordinary full navigation without needing an
                    // equivalent to directLink().
                    return '<a href="'.htmlspecialchars((string) $registerURL, ENT_QUOTES).'" '
                        . 'title="'.htmlPrep($title).'">'.$icon.'</a>';
                }
            );

        $table->addActionColumn()
            ->addParam('gibbonTTDayRowClassID')
            ->format(
                function ($values, $actions) use ($date) {
                    // Without a room there is nothing to open.
                    if (empty($values['roomName'])) {
                        return;
                    }

                    $params = [
                        'q'      => '/modules/Seating Plan/room.php',
                        'period' => $values['gibbonTTDayRowClassID'],
                        'date'   => $date,
                    ];

                    // A room someone has already seated opens straight back
                    // into seating mode; otherwise the furniture default
                    // stands.
                    if ($values['seatedCount'] > 0) {
                        $params['mode'] = 'seating';
                    }

                    $room = Url::fromHandlerRoute('fullscreen.php')
                        ->withQueryParams($params);

                    // directLink keeps htmx out of it: a full-screen page
                    // has no #content-wrap for a boosted link to swap into.
                    $actions->addAction('open', __('Open Room'))
                        ->setIcon('planner')
                        ->setURL($room)
                        ->directLink()
                        ->displayLabel();
                }
            );

        $output = $table->render(new DataSet(array_values($rows)));

        if (empty($rows)) {
            $output .= Format::alert(
                __('You have no timetabled lessons on this day.'),
                'message'
            );
        }

        return $output;
    }

    /**
     * One grouped row per room+period, with attendance completion attached -
     * exactly the logic seatingPlans.php used to own directly.
     *
     * @param string $gibbonPersonID
     * @param string $date
     *
     * @return array
     */
    private function buildRows(string $gibbonPersonID, string $date): array
    {
        $lessons = $this->ttDayDateGateway
            ->selectTimetabledPeriodsByPersonAndDateRange($gibbonPersonID, $date, $date)
            ->fetchAll();

        $covered = $this->slotGateway->selectCoveredSlots(
            $gibbonPersonID,
            $date,
            $this->session->get('gibbonSchoolYearID')
        );

        $rows = [];

        foreach ($lessons as $lesson) {
            $rows[$lesson['gibbonTTDayRowClassID']] = [
                'gibbonTTDayRowClassID' => $lesson['gibbonTTDayRowClassID'],
                'gibbonTTDayID'         => $lesson['gibbonTTDayID'],
                'gibbonTTColumnRowID'   => $lesson['gibbonTTColumnRowID'],
                'gibbonCourseClassID'   => $lesson['gibbonCourseClassID'],
                'period'    => $lesson['period'],
                'timeStart' => $lesson['timeStart'],
                'timeEnd'   => $lesson['timeEnd'],
                'className' => Format::courseClassName(
                    $lesson['courseNameShort'],
                    $lesson['classNameShort']
                ),
                'roomName'  => !empty($lesson['spaceChanged'])
                    ? ($lesson['roomNameChange'] ?? '')
                    : ($lesson['roomName'] ?? ''),
                'moved'     => !empty($lesson['spaceChanged']),
                'coveringFor' => '',
            ];
        }

        foreach ($covered as $lesson) {
            // A lesson both taught and covered would be odd, but the
            // teacher's own entry is the more informative one, so it wins.
            if (isset($rows[$lesson['gibbonTTDayRowClassID']])) {
                continue;
            }

            $rows[$lesson['gibbonTTDayRowClassID']] = [
                'gibbonTTDayRowClassID' => $lesson['gibbonTTDayRowClassID'],
                'gibbonTTDayID'         => $lesson['gibbonTTDayID'],
                'gibbonTTColumnRowID'   => $lesson['gibbonTTColumnRowID'],
                'gibbonCourseClassID'   => $lesson['gibbonCourseClassID'],
                'period'    => $lesson['period'],
                'timeStart' => $lesson['timeStart'],
                'timeEnd'   => $lesson['timeEnd'],
                'className' => Format::courseClassName(
                    $lesson['courseNameShort'],
                    $lesson['classNameShort']
                ),
                'roomName'  => $lesson['roomName'] ?? '',
                'moved'     => false,
                'coveringFor' => $lesson['coveringFor'] ?? '',
            ];
        }

        // One query for the whole day: how many classes share each room, and
        // whether there is a layout to open it with.
        $summaries = $this->slotGateway->selectSlotSummaries(
            array_keys($rows),
            $date,
            $gibbonPersonID
        );

        foreach ($rows as $id => $lesson) {
            $rows[$id]['gibbonSpaceID'] = $summaries[$id]['gibbonSpaceID'] ?? '';
            $rows[$id]['classCount'] = (int) ($summaries[$id]['classCount'] ?? 1);
            $rows[$id]['layoutCount'] = (int) ($summaries[$id]['layoutCount'] ?? 0);
            $rows[$id]['seatedCount'] = (int) ($summaries[$id]['seatedCount'] ?? 0);
        }

        // A teacher who runs several classes in the same room at the same
        // time - an IB group split into HL/SL/HSD sections is one real
        // example - sees one room, once. Group by period and effective room
        // rather than by class, and list the classes together in that one
        // row. A double lesson still makes two rows, because its two
        // periods do not share a key.
        $grouped = [];

        foreach ($rows as $lesson) {
            // With no room there is nothing shared to group by - each such
            // lesson stays on its own row rather than merging with an
            // unrelated class that also happens to have no room this
            // period.
            $key = $lesson['gibbonSpaceID'] !== ''
                ? $lesson['gibbonTTColumnRowID'].':'.$lesson['gibbonSpaceID']
                : 'room-none:'.$lesson['gibbonTTDayRowClassID'];

            if (!isset($grouped[$key])) {
                $grouped[$key] = $lesson;
                $grouped[$key]['classNames'] = [$lesson['className']];
                $grouped[$key]['coveringForNames'] = $lesson['coveringFor'] !== ''
                    ? [$lesson['coveringFor']] : [];
                $grouped[$key]['ownClassCount'] = 1;
            } else {
                $grouped[$key]['classNames'][] = $lesson['className'];
                if ($lesson['coveringFor'] !== '') {
                    $grouped[$key]['coveringForNames'][] = $lesson['coveringFor'];
                }
                $grouped[$key]['moved'] = $grouped[$key]['moved'] || $lesson['moved'];
                $grouped[$key]['ownClassCount']++;
            }
        }

        foreach ($grouped as $key => $group) {
            $grouped[$key]['className'] = implode(
                ' + ',
                array_unique($group['classNames'])
            );
            $grouped[$key]['coveringFor'] = implode(
                ', ',
                array_unique($group['coveringForNames'])
            );
            $grouped[$key]['extraClassCount'] = max(
                0,
                $group['classCount'] - $group['ownClassCount']
            );
        }

        // Attendance completion, per room+period row: not just "has anyone
        // clicked", but "has everyone who can be marked here been marked".
        // Reuses the same roster rules Register mode itself uses (exactly
        // one of the room's classes, not absent today per
        // gibbonTTDayRowClassException) rather than re-deriving them, so a
        // withdrawn student or an ambiguous co-taught overlap can never
        // make 100% unreachable. One extra pair of queries per room+period
        // row (not per student) - a teacher's day is a handful of rows, not
        // a report.
        foreach ($grouped as $key => $group) {
            if (empty($group['roomName'])) {
                $grouped[$key]['attendanceStatus'] = '';
                continue;
            }

            $classesInSlot = $this->slotGateway->selectClassesInSlot(
                $group['gibbonSpaceID'],
                $group['gibbonTTDayID'],
                $group['gibbonTTColumnRowID'],
                $date
            );

            $exceptionPairs = array_map(
                function ($classRow) {
                    return [
                        'gibbonCourseClassID'   => $classRow['gibbonCourseClassID'],
                        'gibbonTTDayRowClassID' => $classRow['gibbonTTDayRowClassID'],
                    ];
                },
                $classesInSlot
            );

            $roster = $this->rosterGateway->selectRoster(
                array_column($classesInSlot, 'gibbonCourseClassID'),
                $exceptionPairs,
                $date
            );

            $completion = $this->attendanceGateway->countRoomCompletion(
                $roster,
                $classesInSlot,
                $date
            );

            $grouped[$key]['attendanceEligible'] = $completion['eligible'];
            $grouped[$key]['attendanceMarked'] = $completion['marked'];

            if ($completion['eligible'] === 0) {
                // Nobody who could be marked here at all - an empty class,
                // or every student ambiguous. Neither "done" nor "pending"
                // says anything true, so the column stays blank for this
                // row.
                $grouped[$key]['attendanceStatus'] = '';
            } elseif ($completion['marked'] === 0) {
                $grouped[$key]['attendanceStatus'] = 'none';
            } elseif ($completion['marked'] < $completion['eligible']) {
                $grouped[$key]['attendanceStatus'] = 'partial';
            } else {
                $grouped[$key]['attendanceStatus'] = 'complete';
            }
        }

        $rows = $grouped;

        uasort(
            $rows,
            function ($a, $b) {
                return strcmp($a['timeStart'], $b['timeStart']);
            }
        );

        return $rows;
    }
}
