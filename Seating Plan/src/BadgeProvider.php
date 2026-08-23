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

use Gibbon\Contracts\Database\Connection;
use Gibbon\Contracts\Services\Session;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\UI\Components\Alert;
use Gibbon\Module\SeatingPlan\Domain\BadgeGateway;

/**
 * Computes every badge value for every student on a room's roster.
 *
 * A badge slot is configured per plan (see SeatingPlanGateway), but the
 * values themselves are computed once per room load for all eleven
 * possible sources, not just the four currently in a slot - so dragging a
 * badge into a different slot in the config panel is a client-side change
 * only, no server round trip.
 *
 * The eleven sources: the five core Gibbon alert types (Individual Needs,
 * Academic, Behaviour, Medical, Privacy), house, form group, year group,
 * target grade, current (cumulative) grade, and CAT4 Mean SAS.
 */
class BadgeProvider
{
    /**
     * @var Connection
     */
    private $db;

    /**
     * @var Alert
     */
    private $alert;

    /**
     * @var BadgeGateway
     */
    private $badgeGateway;

    /**
     * @var MarkbookGradeProvider
     */
    private $markbookGradeProvider;

    public function __construct(
        Connection $db,
        Alert $alert,
        BadgeGateway $badgeGateway,
        MarkbookGradeProvider $markbookGradeProvider
    ) {
        $this->db = $db;
        $this->alert = $alert;
        $this->badgeGateway = $badgeGateway;
        $this->markbookGradeProvider = $markbookGradeProvider;
    }

    /**
     * Every badge value for every student in $roster.
     *
     * @param array $roster  Rows from StudentRosterGateway::selectRoster(),
     *                       already carrying houseName/gibbonHouseID/
     *                       formGroup/yearGroup/courseClassIDs.
     * @param array $classes The room's classes (RoomContext::getClasses()),
     *                       each with at least gibbonCourseClassID.
     *
     * @return array Keyed by gibbonPersonID, each an array keyed by badge
     *                source key (see getCatalogue()), each entry
     *                ['label' => string, 'value' => string,
     *                'colour' => ?string, 'tone' => 'good'|'bad'|'neutral'].
     *                A source with nothing to show for a student is simply
     *                absent from their array.
     */
    public function buildBadgeValues(array $roster, array $classes): array
    {
        $badges = [];

        foreach ($roster as $student) {
            $badges[$student['gibbonPersonID']] = [];
        }

        $this->addAlertBadges($badges, $roster);
        $this->addFieldBadges($badges, $roster);
        $this->addMarkbookBadges($badges, $roster, $classes);

        $catScores = $this->badgeGateway->selectCATScores(
            array_column($roster, 'gibbonPersonID')
        );

        foreach ($roster as $student) {
            $score = $catScores[$student['gibbonPersonID']] ?? null;

            if ($score === null || $score['value'] === null) {
                continue;
            }

            $badges[$student['gibbonPersonID']]['cat'] = [
                'label'  => __('CAT'),
                'value'  => $score['value'],
                'colour' => null,
                // Neutral by design: a CAT score has no per-student target
                // to judge it against, unlike target/current grade.
                'tone'   => 'neutral',
            ];
        }

        return $badges;
    }

    /**
     * Every badge source the config panel may offer, whether or not any
     * student on the current roster actually has a value for it - the
     * panel's palette is the same regardless of who is in the room.
     *
     * @return array Each entry ['key' => string, 'label' => string].
     */
    public function getCatalogue(): array
    {
        $catalogue = [];

        foreach ($this->alert->getActiveAlertTypes() as $name => $type) {
            $catalogue[] = ['key' => 'alert:'.$name, 'label' => __($name)];
        }

        $catalogue[] = ['key' => 'house', 'label' => __('House')];
        $catalogue[] = ['key' => 'formGroup', 'label' => __('Form Group')];
        $catalogue[] = ['key' => 'yearGroup', 'label' => __('Year Group')];
        $catalogue[] = ['key' => 'targetGrade', 'label' => __('Target')];
        $catalogue[] = ['key' => 'currentGrade', 'label' => __('Current Grade')];
        $catalogue[] = ['key' => 'cat', 'label' => __('CAT')];

        return $catalogue;
    }

    /**
     * The five core alert types, via core's own Alert component - the same
     * class the student profile page uses for its own alert bar.
     */
    private function addAlertBadges(array &$badges, array $roster): void
    {
        foreach ($roster as $student) {
            $alerts = $this->alert->getAlertsByStudent($student['gibbonPersonID']);

            foreach ($alerts as $type => $alert) {
                // Privacy alerts carry no gibbonAlertLevel row (no 'level'),
                // only the student's own privacy value - fall back to that
                // before the alert type's generic tag.
                $badges[$student['gibbonPersonID']]['alert:'.$type] = [
                    'label'  => __($type),
                    'value'  => $alert['level']
                        ?? $alert['privacy']
                        ?? $alert['tag']
                        ?? '',
                    'colour' => $alert['levelColor'] ?? $alert['color'] ?? null,
                    'tone'   => 'neutral',
                ];
            }
        }
    }

    /**
     * House, form group, year group - plain fields already on the roster
     * row, plus this module's own house colour table.
     */
    private function addFieldBadges(array &$badges, array $roster): void
    {
        $houseColours = $this->badgeGateway->selectHouseColours();

        foreach ($roster as $student) {
            $personID = $student['gibbonPersonID'];

            if (!empty($student['houseName'])) {
                $badges[$personID]['house'] = [
                    'label'  => __('House'),
                    'value'  => $student['houseName'],
                    'colour' => $houseColours[$student['gibbonHouseID']] ?? null,
                    'tone'   => 'neutral',
                ];
            }

            if (!empty($student['formGroup'])) {
                $badges[$personID]['formGroup'] = [
                    'label'  => __('Form Group'),
                    'value'  => $student['formGroup'],
                    'colour' => null,
                    'tone'   => 'neutral',
                ];
            }

            if (!empty($student['yearGroup'])) {
                $badges[$personID]['yearGroup'] = [
                    'label'  => __('Year Group'),
                    'value'  => $student['yearGroup'],
                    'colour' => null,
                    'tone'   => 'neutral',
                ];
            }
        }
    }

    /**
     * Target grade and current (cumulative) grade, from core's own Markbook
     * domain. Both are percent-scale only, matching core's own
     * renderStudentCumulativeMarks() in modules/Markbook/moduleFunctions.php,
     * which silently skips a non-percent school rather than showing a
     * meaningless number - this does the same, system-wide, not per class.
     *
     * A student enrolled in more than one of the room's classes is skipped
     * here entirely, the same "never guess" stance Register mode already
     * takes for exactly this ambiguity - which class's grade would even be
     * meant is not knowable.
     */
    private function addMarkbookBadges(
        array &$badges,
        array $roster,
        array $classes
    ): void {
        foreach ($this->markbookGradeProvider->getGrades($roster, $classes) as $personID => $grade) {
            $target = $grade['target'];
            $current = $grade['current'];

            if ($target !== '') {
                $badges[$personID]['targetGrade'] = [
                    'label'  => __('Target'),
                    'value'  => $target,
                    'colour' => null,
                    'tone'   => 'neutral',
                ];
            }

            if ($current !== '') {
                $badges[$personID]['currentGrade'] = [
                    'label'  => __('Current Grade'),
                    'value'  => $current,
                    'colour' => null,
                    'tone'   => $target !== ''
                        ? ((float) $current >= (float) $target ? 'good' : 'bad')
                        : 'neutral',
                ];
            }
        }
    }
}
