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

use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;
use Gibbon\Module\SeatingPlan\Domain\TimetableSlotGateway;

/**
 * Turns whatever the caller has into a RoomContext.
 */
class RoomContextResolver
{
    /**
     * @var RoomLayoutGateway
     */
    private $layoutGateway;

    /**
     * @var TimetableSlotGateway
     */
    private $slotGateway;

    /**
     * @param RoomLayoutGateway    $layoutGateway Room layouts.
     * @param TimetableSlotGateway $slotGateway   Timetable slots.
     */
    public function __construct(
        RoomLayoutGateway $layoutGateway,
        TimetableSlotGateway $slotGateway
    ) {
        $this->layoutGateway = $layoutGateway;
        $this->slotGateway = $slotGateway;
    }

    /**
     * A room to draw furniture in. No class, no date.
     *
     * @param string $seatingPlanRoomLayoutID The layout.
     * @param string $gibbonPersonID          The viewer.
     *
     * @return RoomContext|null Null when the layout does not exist.
     */
    public function fromLayout($seatingPlanRoomLayoutID, $gibbonPersonID): ?RoomContext
    {
        $layout = $this->layoutGateway->getLayoutByID($seatingPlanRoomLayoutID);

        if (empty($layout)) {
            return null;
        }

        // A shared layout may be rearranged by anyone who can open it. That
        // does not put their changes into the owner's drawing: the save
        // endpoint lands a non-owner's work on a copy of their own instead
        // (see layout_saveAjax.php). An unshared layout belonging to
        // somebody else stays read-only, since it is not on offer at all.
        $editable = $layout['gibbonPersonIDOwner'] == $gibbonPersonID
            || $layout['shared'] == 'Y';

        return new RoomContext(RoomContext::FROM_LAYOUT, $layout, [], [], $editable);
    }

    /**
     * A room with a lesson in it: the same room, plus every class sharing it.
     *
     * @param string $gibbonTTDayRowClassID The timetabled class.
     * @param string $date                  Y-m-d.
     * @param string $gibbonPersonID        The viewer.
     *
     * @return RoomContext|null Null when the lesson does not run that day, or
     *                          has no room on the timetable.
     */
    public function fromPeriod(
        $gibbonTTDayRowClassID,
        $date,
        $gibbonPersonID
    ): ?RoomContext {
        $slot = $this->slotGateway->getSlot($gibbonTTDayRowClassID, $date);

        if (empty($slot) || empty($slot['gibbonSpaceID'])) {
            return null;
        }

        $classes = $this->slotGateway->selectClassesInSlot(
            $slot['gibbonSpaceID'],
            $slot['gibbonTTDayID'],
            $slot['gibbonTTColumnRowID'],
            $date
        );

        $layout = $this->pickLayoutForRoom($slot['gibbonSpaceID'], $gibbonPersonID);

        // Without a layout there is still a room, just an empty one. Fall back
        // to a default grid so the screen opens and the teacher can draw it.
        if (empty($layout)) {
            $layout = [
                'seatingPlanRoomLayoutID' => '',
                'gibbonSpaceID'           => $slot['gibbonSpaceID'],
                'spaceName'               => $slot['spaceName'] ?? '',
                'name'                    => '',
                'gridCols'                => 20,
                'gridRows'                => 14,
                'gibbonPersonIDOwner'     => '',
                'shared'                  => 'Y',
            ];
        }

        // Anyone teaching one of the classes in the room may work in it.
        $editable = $this->slotGateway->isStaffOfAnyClass(
            array_column($classes, 'gibbonCourseClassID'),
            $gibbonPersonID
        );

        return new RoomContext(
            RoomContext::FROM_PERIOD,
            $layout,
            $slot,
            $classes,
            $editable
        );
    }

    /**
     * The layout to open for a room: the viewer's own if they have one, else
     * whichever shared layout a colleague has already drawn.
     *
     * @param string $gibbonSpaceID  The room.
     * @param string $gibbonPersonID The viewer.
     *
     * @return array
     */
    private function pickLayoutForRoom($gibbonSpaceID, $gibbonPersonID): array
    {
        $layouts = $this->layoutGateway
            ->selectLayoutsBySpace($gibbonSpaceID, $gibbonPersonID)
            ->fetchAll();

        if (empty($layouts)) {
            return [];
        }

        return $this->layoutGateway->getLayoutByID($layouts[0]['value']);
    }
}
