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

use Gibbon\Forms\Prefab\DeleteForm;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/layout_delete.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $seatingPlanRoomLayoutID = $_GET['seatingPlanRoomLayoutID'] ?? '';

    $layout = $container->get(RoomLayoutGateway::class)
        ->getLayoutByID($seatingPlanRoomLayoutID);

    if (empty($layout)) {
        $page->addError(__('The specified record cannot be found.'));
    } elseif ($layout['gibbonPersonIDOwner'] != $session->get('gibbonPersonID')) {
        $page->addError(__('You do not have access to this action.'));
    } else {
        $form = DeleteForm::createForm(
            $session->get('absoluteURL')
                . '/modules/Seating Plan/layout_deleteProcess.php'
        );

        $form->addHiddenValue('seatingPlanRoomLayoutID', $seatingPlanRoomLayoutID);

        echo $form->getOutput();
    }
}
