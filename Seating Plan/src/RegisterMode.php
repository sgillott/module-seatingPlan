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

use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\SeatingPlan\Domain\AttendanceGateway;

/**
 * Builds the extra payload room.php needs for register mode: the roster and
 * seats Seating mode already assembles, plus each student's current mark
 * and the code palette to cycle through.
 */
class RegisterMode
{
    /**
     * @var SeatingMode
     */
    private $seatingMode;

    /**
     * @var AttendanceGateway
     */
    private $attendanceGateway;

    /**
     * @var SettingGateway
     */
    private $settingGateway;

    /**
     * @param SeatingMode        $seatingMode       Reused for roster+seats.
     * @param AttendanceGateway  $attendanceGateway This module's attendance
     *                                               service.
     * @param SettingGateway     $settingGateway    Core settings.
     */
    public function __construct(
        SeatingMode $seatingMode,
        AttendanceGateway $attendanceGateway,
        SettingGateway $settingGateway
    ) {
        $this->seatingMode = $seatingMode;
        $this->attendanceGateway = $attendanceGateway;
        $this->settingGateway = $settingGateway;
    }

    /**
     * The roster, seats, current marks and code palette for a room context.
     *
     * A roster student enrolled in more than one of the room's co-taught
     * classes is marked 'ambiguous' rather than assigned a guessed class:
     * their tile still shows, but carries no class and no mark, and the
     * client is expected to leave it non-interactive. This mirrors the
     * server-side rejection in the save endpoint, which independently
     * re-resolves the same thing rather than trusting the client.
     *
     * @param RoomContext $context       The room being viewed.
     * @param array       $gibbonRoleIDs The viewer's own role IDs, as a
     *                                   plain array - which codes they may
     *                                   use. See
     *                                   AttendanceGateway::selectActiveCodesForRole()
     *                                   for why this must already be an
     *                                   array, not the raw session value.
     *
     * @return array ['roster' => [...], 'seats' => [...], 'marks' => [...],
     *                'codes' => [...]]
     */
    public function buildPayload(RoomContext $context, array $gibbonRoleIDs): array
    {
        $seatingPayload = $this->seatingMode->buildPayload($context);

        $personIDsByClass = [];
        $roster = array_map(
            function ($student) use (&$personIDsByClass) {
                $classIDs = array_values(array_filter(
                    explode(',', (string) ($student['courseClassIDs'] ?? ''))
                ));
                $ambiguous = count($classIDs) !== 1;

                if (!$ambiguous) {
                    $personIDsByClass[$student['gibbonPersonID']] = $classIDs[0];
                }

                return [
                    'gibbonPersonID' => $student['gibbonPersonID'],
                    'name'           => $student['name'],
                    'photo'          => $student['photo'],
                    'ambiguous'      => $ambiguous,
                ];
            },
            $seatingPayload['roster']
        );

        // Every class in this room this period, each with its own period
        // row. Where two classes meet here at once, a mark belongs to the
        // student's own class's period - the room has no single anchor that
        // would find both.
        $anchorByClass = [];

        foreach ($context->getClasses() as $classRow) {
            $anchorByClass[(int) $classRow['gibbonCourseClassID']]
                = $classRow['gibbonTTDayRowClassID'];
        }

        $crossFillClasses = $this->settingGateway
            ->getSettingByScope('Attendance', 'crossFillClasses');

        $marks = $this->attendanceGateway->selectCurrentMarksForRoster(
            $personIDsByClass,
            $anchorByClass,
            $context->getDate(),
            $crossFillClasses
        );

        $codes = $this->attendanceGateway->selectActiveCodesForRole($gibbonRoleIDs);

        return [
            'roster' => $roster,
            'seats'  => $seatingPayload['seats'],
            'marks'  => $marks,
            'codes'  => $codes,
        ];
    }
}
