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

/**
 * When a lesson's reward or sanction points earn a real Behaviour record.
 *
 * Rewards mode records every click in this module's own ledger. Whether any
 * of those clicks also become a permanent gibbonBehaviour row is the
 * school's choice, and off by default: a classroom tap and a considered
 * behaviour entry are not the same thing, and a school that wants only the
 * former should not have its permanent record filled by it.
 *
 * The rule is deliberately two numbers rather than a mode plus a number.
 * "Write one log at 3 points, then one more every 1 point after that" and
 * "write a single log at 3 points" are the same rule with a different
 * interval, so there is no separate single/multiple switch to keep
 * consistent - an interval of 0 simply means no further logs.
 */
class BehaviourPolicy
{
    /**
     * @var SettingGateway
     */
    private $settingGateway;

    /**
     * @param SettingGateway $settingGateway Core's own settings store.
     */
    public function __construct(SettingGateway $settingGateway)
    {
        $this->settingGateway = $settingGateway;
    }

    /**
     * Whether this point, which took the student's lesson total for its type
     * to $countAfter, earns a Behaviour record.
     *
     * A pure function of its arguments, static so it can be exercised
     * directly without a database or a container.
     *
     * With a threshold of 3 and an interval of 1 this fires at 3, 4, 5, 6...;
     * with an interval of 2 it fires at 3, 5, 7...; with an interval of 0 it
     * fires only at 3.
     *
     * @param int $countAfter The student's lesson total for this type,
     *                        counting the point just recorded.
     * @param int $threshold  Points needed before the first record.
     * @param int $repeat     Points between records after that. 0 or less
     *                        means no further records.
     *
     * @return bool
     */
    public static function shouldWrite(int $countAfter, int $threshold, int $repeat): bool
    {
        return self::logsFor($countAfter, $threshold, $repeat)
            > self::logsFor($countAfter - 1, $threshold, $repeat);
    }

    /**
     * How many Behaviour records a lesson total of $count is worth in total.
     *
     * The counting question rather than the crossing question, and the one
     * that survives a count going down as well as up: a point can be taken
     * back with a right-click, and "does this point cross the threshold"
     * would then say yes a second time on the way back up and write the
     * same record twice. Asking how many records a count deserves, and
     * comparing that with how many were actually written, cannot do that.
     *
     * With a threshold of 3 and an interval of 1 this reads 0,0,1,2,3...;
     * with an interval of 2, 0,0,1,1,2,2,3; with an interval of 0 it stops
     * at 1 however high the count goes.
     *
     * A pure function of its arguments, static so it can be exercised
     * without a database or a container.
     *
     * @param int $count     The student's lesson total for this type.
     * @param int $threshold Points needed before the first record.
     * @param int $repeat    Points between records after that. 0 or less
     *                       means no further records.
     *
     * @return int
     */
    public static function logsFor(int $count, int $threshold, int $repeat): int
    {
        if ($threshold < 1) {
            // A threshold of 0 would mean "write on every point", which the
            // settings page does not allow. Treat it as 1 rather than
            // dividing the meaning of the setting in two places.
            $threshold = 1;
        }

        if ($count < $threshold) {
            return 0;
        }

        if ($repeat < 1) {
            return 1;
        }

        return 1 + intdiv($count - $threshold, $repeat);
    }

    /**
     * The school's configured behaviour-writing settings, already cast to
     * the shapes the rest of the module expects.
     *
     * @return array Keys: enabled (bool), threshold (int), repeat (int),
     *                Positive and Negative (each ['descriptor', 'level',
     *                'comment']).
     */
    public function getSettings(): array
    {
        return [
            'enabled'   => $this->get('behaviourWriteEnabled') === 'Y',
            'threshold' => (int) $this->get('behaviourThreshold'),
            'repeat'    => (int) $this->get('behaviourRepeatEvery'),
            'Positive'  => [
                'descriptor' => $this->get('behaviourPositiveDescriptor'),
                'level'      => $this->get('behaviourPositiveLevel'),
                'comment'    => $this->get('behaviourPositiveComment'),
            ],
            'Negative'  => [
                'descriptor' => $this->get('behaviourNegativeDescriptor'),
                'level'      => $this->get('behaviourNegativeLevel'),
                'comment'    => $this->get('behaviourNegativeComment'),
            ],
        ];
    }

    /**
     * @param string $name The setting name within this module's scope.
     *
     * @return string Empty when the setting row does not exist, which is
     *                what an install predating it looks like.
     */
    private function get($name): string
    {
        return (string) $this->settingGateway
            ->getSettingByScope('Seating Plan', $name);
    }
}
