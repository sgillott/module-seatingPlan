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

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Module\SeatingPlan\Domain\BadgeGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/houseColourSettings.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Badge Settings'));

    $houses = $container->get(BadgeGateway::class)->selectHouses();

    if (empty($houses)) {
        echo Format::alert(
            __('This school has no houses set up (School Admin > Houses), '
                . 'so there is nothing to colour yet. The house badge '
                . 'simply does not show until a house exists.'),
            'message'
        );
    } else {
        echo '<p>';
        echo __(
            'Set the colour shown behind a student\'s House badge on the '
            . 'seating plan. A house with no colour set here shows the '
            . 'module\'s own default colour instead.'
        );
        echo '</p>';

        $form = Form::create(
            'houseColours',
            $session->get('absoluteURL').'/modules/'.$session->get('module')
                .'/houseColourSettingsProcess.php'
        );
        $form->addHiddenValue('address', $session->get('address'));

        foreach ($houses as $house) {
            $row = $form->addRow();
            $row->addLabel(
                'colour'.$house['gibbonHouseID'],
                $house['name']
            );
            $row->addTextField('colour'.$house['gibbonHouseID'])
                ->setType('color')
                ->setValue($house['colour'] ?: '#0f6156');
        }

        $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit();

        echo $form->getOutput();
    }
}
