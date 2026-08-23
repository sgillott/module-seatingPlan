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
use Gibbon\Forms\DatabaseFormFactory;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/layout_add.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs
        ->add(__('Room Layouts'), 'layouts.php')
        ->add(__('Add Room Layout'));

    $form = Form::create(
        'roomLayout',
        $session->get('absoluteURL')
            . '/modules/Seating Plan/layout_addProcess.php'
    );

    // addSelectSpace lives on the database-backed factory, not the plain one.
    $form->setFactory(DatabaseFormFactory::create($pdo));

    $form->setDescription(
        '<p>'
        . __(
            'Choose the room and how big the grid should be. One cell is about '
            . 'half a metre, so a typical classroom is around 20 by 14. You can '
            . 'change the size later without losing your furniture.'
        )
        . '</p>'
    );

    $form->addHiddenValue('address', $session->get('address'));

    $row = $form->addRow();
    $row->addLabel('gibbonSpaceID', __('Room'));
    $row->addSelectSpace('gibbonSpaceID')->required()->placeholder();

    $row = $form->addRow();
    $row->addLabel('name', __('Layout Name'))
        ->description(__('For example: Exam Rows, Group Tables'));
    $row->addTextField('name')->required()->maxLength(40)->setValue(__('Default'));

    $row = $form->addRow();
    $row->addLabel('orientation', __('Orientation'))
        ->description(__('Sets the starting shape. Fine-tune it below.'));
    $row->addSelect('orientation')
        ->fromArray(
            [
                'Landscape' => __('Landscape').' — 20 × 14',
                'Portrait'  => __('Portrait').' — 14 × 20',
            ]
        )
        ->required()
        ->selected('Landscape');

    $row = $form->addRow();
    $row->addLabel('cols', __('Columns'))->description(__('Across the room, 10 to 40'));
    $row->addNumber('cols')->required()->minimum(10)->maximum(40)->setValue(20);

    $row = $form->addRow();
    $row->addLabel('rows', __('Rows'))->description(__('Front to back, 10 to 40'));
    $row->addNumber('rows')->required()->minimum(10)->maximum(40)->setValue(14);

    $row = $form->addRow();
    $row->addLabel('shared', __('Share With Colleagues'))
        ->description(
            __('Lets anyone else who teaches in this room open your layout. '
                . 'They can use it and copy it, but only you can change it.')
        );
    $row->addYesNo('shared')->required()->selected('Y');

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Create & Open Designer'));

    echo $form->getOutput();
    ?>
    <script type="text/javascript">
    // Picking an orientation just swaps the two numbers below it. They stay
    // editable, so the choice is a starting point and not a constraint.
    (function () {
        var orientation = document.getElementById('orientation');
        var cols = document.getElementById('cols');
        var rows = document.getElementById('rows');

        if (!orientation || !cols || !rows) {
            return;
        }

        orientation.addEventListener('change', function () {
            var long = Math.max(cols.value, rows.value) || 20;
            var short = Math.min(cols.value, rows.value) || 14;

            if (orientation.value === 'Portrait') {
                cols.value = short;
                rows.value = long;
            } else {
                cols.value = long;
                rows.value = short;
            }
        });
    }());
    </script>
    <?php
}
