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

/**
 * What the room screen is looking at.
 *
 * There are two ways in and they carry different things. Coming from the Room
 * Layouts list gives a layout and nothing else: a room to draw furniture in,
 * with no class and no date. Coming from a timetable, a lesson plan or the
 * dashboard gives a period: the same room, plus every class in it, the students
 * and the day.
 *
 * Both resolve to one of these, so the screen renders the same way either way.
 * Modes that need students are simply not offered when there are none.
 */
class RoomContext
{
    public const FROM_LAYOUT = 'layout';
    public const FROM_PERIOD = 'period';

    /**
     * Which way the screen was entered.
     *
     * @var string
     */
    private $source;

    /**
     * The room layout row, including its grid size and owner.
     *
     * @var array
     */
    private $layout;

    /**
     * The timetable slot, when entered from a period. Empty otherwise.
     *
     * @var array
     */
    private $slot;

    /**
     * Every class sharing the room at that time. Empty when from a layout.
     *
     * @var array
     */
    private $classes;

    /**
     * Whether the viewer may change what they are looking at.
     *
     * @var bool
     */
    private $editable;

    /**
     * @param string $source   One of the FROM_ constants.
     * @param array  $layout   The room layout row.
     * @param array  $slot     The timetable slot, or an empty array.
     * @param array  $classes  Classes in the room, or an empty array.
     * @param bool   $editable Whether the viewer may make changes.
     */
    public function __construct(
        string $source,
        array $layout,
        array $slot = [],
        array $classes = [],
        bool $editable = false
    ) {
        $this->source = $source;
        $this->layout = $layout;
        $this->slot = $slot;
        $this->classes = $classes;
        $this->editable = $editable;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function hasPeriod(): bool
    {
        return $this->source === self::FROM_PERIOD && !empty($this->slot);
    }

    public function getLayout(): array
    {
        return $this->layout;
    }

    public function getLayoutID(): string
    {
        return (string) ($this->layout['seatingPlanRoomLayoutID'] ?? '');
    }

    public function getSpaceID(): string
    {
        return (string) ($this->layout['gibbonSpaceID'] ?? '');
    }

    public function getRoomName(): string
    {
        return (string) ($this->layout['spaceName'] ?? '');
    }

    public function getGridCols(): int
    {
        return (int) ($this->layout['gridCols'] ?? 20);
    }

    public function getGridRows(): int
    {
        return (int) ($this->layout['gridRows'] ?? 14);
    }

    public function getSlot(): array
    {
        return $this->slot;
    }

    public function getDate(): string
    {
        return (string) ($this->slot['date'] ?? '');
    }

    /**
     * The period this room is in, as the whole room shares it.
     *
     * Every co-taught class in the room has its own gibbonTTDayRowClassID but
     * they all sit on one gibbonTTColumnRowID, so the column row - not the
     * day-row class - is what identifies "this lesson" for anything counted
     * per lesson, such as reward and sanction points.
     *
     * @return string Empty when the room was opened from a bare layout.
     */
    public function getPeriodID(): string
    {
        return (string) ($this->slot['gibbonTTColumnRowID'] ?? '');
    }

    public function getClasses(): array
    {
        return $this->classes;
    }

    /**
     * The classes in this room, sorted, as the key a seating plan is filed
     * under. One plan then serves every period where the same group meets in
     * the same room.
     *
     * @return string
     */
    public function getClassList(): string
    {
        $ids = array_column($this->classes, 'gibbonCourseClassID');

        if (empty($ids)) {
            return '';
        }

        // Canonical form - unpadded and numerically sorted - because that
        // is what seatingPlanPlan.classList stores, and a plan is found by
        // matching that column exactly. The IDs arrive here zerofilled
        // from the database ("00000897"), so without this the lookup would
        // never match the row it is looking for.
        return ClassListNormalizer::normalize($ids);
    }

    /**
     * A readable name for the group, such as "12BioHL.1 + 12BioSL.1".
     *
     * @return string
     */
    public function getClassNames(): string
    {
        $names = array_map(
            function ($class) {
                return $class['courseNameShort'].'.'.$class['classNameShort'];
            },
            $this->classes
        );

        return implode(' + ', $names);
    }

    public function isEditable(): bool
    {
        return $this->editable;
    }

    /**
     * What to call the screen.
     *
     * @return string
     */
    public function getTitle(): string
    {
        if ($this->hasPeriod()) {
            return $this->getRoomName().' — '.$this->getClassNames();
        }

        return $this->getRoomName().' — '.($this->layout['name'] ?? '');
    }
}
