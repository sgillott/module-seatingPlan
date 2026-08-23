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

use Gibbon\Services\Format;
use Gibbon\Module\SeatingPlan\Domain\RewardTallyGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomExitGateway;

/**
 * The parts an administrator's correction pages share.
 *
 * A reward tally and a room exit are different rows with different fields,
 * but everything around them is the same: which record type was asked for,
 * looking it up, saying whose it is and which lesson it belongs to, and
 * writing the audit entry. Keeping that here is what allows one edit page
 * and one delete page instead of four of each.
 */
class RecordAdmin
{
    public const REWARD = 'reward';
    public const EXIT = 'exit';

    /**
     * @var RewardTallyGateway
     */
    private $tallyGateway;

    /**
     * @var RoomExitGateway
     */
    private $exitGateway;

    public function __construct(
        RewardTallyGateway $tallyGateway,
        RoomExitGateway $exitGateway
    ) {
        $this->tallyGateway = $tallyGateway;
        $this->exitGateway = $exitGateway;
    }

    /**
     * @param string $type Whatever arrived in the query string.
     *
     * @return string One of the two constants, or '' when it is neither.
     */
    public static function resolveType($type): string
    {
        return in_array($type, [self::REWARD, self::EXIT], true) ? $type : '';
    }

    /**
     * One record of either kind, with its lesson context.
     *
     * @param string $type One of the two constants.
     * @param string $id   The record.
     *
     * @return array Empty when the type is unknown or the row is gone.
     */
    public function find($type, $id): array
    {
        if ($id === '' || self::resolveType($type) === '') {
            return [];
        }

        return $type === self::REWARD
            ? $this->tallyGateway->getRecordForAdmin($id)
            : $this->exitGateway->getRecordForAdmin($id);
    }

    /**
     * The primary key column for a record type, so the caller does not have
     * to know which table it is looking at.
     *
     * @param string $type One of the two constants.
     *
     * @return string
     */
    public static function keyFor($type): string
    {
        return $type === self::REWARD
            ? 'seatingPlanRewardID'
            : 'seatingPlanRoomExitID';
    }

    /**
     * Who the record is about.
     *
     * @param array $record A row from find().
     *
     * @return string
     */
    public static function studentName(array $record): string
    {
        return Format::name(
            '',
            $record['studentPreferredName'] ?? '',
            $record['studentSurname'] ?? '',
            'Student'
        );
    }

    /**
     * Which lesson the record belongs to, as a readable line: the class,
     * the room and the period, with whichever of those the row has.
     *
     * A class of "." means it was recorded against class 0 - the student
     * was in more than one of the room's co-taught classes at the time, so
     * no class could be named without guessing.
     *
     * @param array $record A row from find().
     *
     * @return string
     */
    public static function lessonLabel(array $record): string
    {
        $className = trim((string) ($record['className'] ?? ''), " \t.");

        $parts = array_filter([
            $className !== '' ? $className : null,
            $record['spaceName'] ?? null,
            $record['periodName'] ?? null,
        ]);

        return empty($parts) ? __('Not recorded') : implode(' · ', $parts);
    }

    /**
     * The date a record belongs to. A reward carries the room's own context
     * date; an exit is stamped with the clock when the student left.
     *
     * @param array $record A row from find().
     *
     * @return string Y-m-d.
     */
    public static function dateOf(array $record): string
    {
        if (!empty($record['date'])) {
            return (string) $record['date'];
        }

        return !empty($record['timeOut'])
            ? substr((string) $record['timeOut'], 0, 10)
            : '';
    }

    /**
     * Who recorded it in the first place.
     *
     * @param array $record A row from find().
     *
     * @return string
     */
    public static function recordedBy(array $record): string
    {
        return Format::name(
            $record['teacherTitle'] ?? '',
            $record['teacherPreferredName'] ?? '',
            $record['teacherSurname'] ?? '',
            'Staff'
        );
    }
}
