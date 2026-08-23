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

namespace Gibbon\Module\SeatingPlan\Domain;

use Gibbon\Domain\Gateway;
use Gibbon\Contracts\Database\Connection;
use Gibbon\Domain\Behaviour\BehaviourGateway;

/**
 * This module's own thin service over core's Behaviour domain, for
 * Rewards mode.
 *
 * A click here writes a real gibbonBehaviour row - found by every existing
 * Behaviour report and the student's own profile - but deliberately skips
 * everything else core's own bulk-add form does on the same insert: tutor/
 * EA email notifications, Individual-Needs-student flags, an alert
 * recalculation. Rapid clicking through a class during a live lesson is a
 * different use case from a considered admin entry, and firing a
 * notification per click would flood tutors' inboxes for a school that
 * already has tutor notifications turned on. No descriptor or level is
 * recorded either - Rewards mode offers only two generic buttons, Reward
 * and Sanction; anyone wanting a specific descriptor still has core's own
 * Behaviour module for that.
 */
class RewardGateway extends Gateway
{
    /**
     * @var BehaviourGateway
     */
    private $behaviourGateway;

    /**
     * @param Connection        $db               The database.
     * @param BehaviourGateway  $behaviourGateway Core's own gibbonBehaviour gateway.
     */
    public function __construct(Connection $db, BehaviourGateway $behaviourGateway)
    {
        parent::__construct($db);
        $this->behaviourGateway = $behaviourGateway;
    }

    /**
     * The comment written when the school has not configured one of its own.
     * gibbonBehaviour.comment is NOT NULL with no database default, and
     * core's own form always passes it even when empty, so there is always
     * something to write here.
     */
    public const DEFAULT_COMMENT = 'Recorded from the seating plan.';

    /**
     * Writes one behaviour record for one student.
     *
     * The descriptor, level and comment are the school's own configured
     * values from the module's Behaviour Settings page - Rewards mode itself
     * still offers only two generic buttons, so every record of one type
     * carries the same three values. Anyone wanting to choose per incident
     * still has core's own Behaviour module.
     *
     * @param string $gibbonPersonID        The student.
     * @param string $type                  'Positive' or 'Negative'.
     * @param string $date                  Y-m-d - the room's own context date.
     * @param string $gibbonSchoolYearID    The current school year.
     * @param string $gibbonPersonIDCreator The teacher recording it.
     * @param string $descriptor            Configured descriptor, or ''.
     * @param string $level                 Configured level, or ''.
     * @param string $comment               Configured incident text.
     *
     * @return string The new gibbonBehaviourID.
     */
    public function recordReward(
        $gibbonPersonID,
        $type,
        $date,
        $gibbonSchoolYearID,
        $gibbonPersonIDCreator,
        $descriptor = '',
        $level = '',
        $comment = self::DEFAULT_COMMENT
    ) {
        return $this->behaviourGateway->insert([
            'gibbonPersonID'        => $gibbonPersonID,
            'gibbonSchoolYearID'    => $gibbonSchoolYearID,
            'date'                  => $date,
            'type'                  => $type,
            // Both columns are nullable, and core's own edit form treats an
            // unset descriptor/level as null rather than an empty string.
            'descriptor'            => $descriptor !== '' ? $descriptor : null,
            'level'                 => $level !== '' ? $level : null,
            'comment'               => $comment !== '' ? $comment : self::DEFAULT_COMMENT,
            'followup'              => '',
            'gibbonPersonIDCreator' => $gibbonPersonIDCreator,
        ]);
    }
}
