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
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\SeatingPlan\BehaviourPolicy;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/behaviourSettings.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    $page->breadcrumbs->add(__('Behaviour Settings'));

    $settingGateway = $container->get(SettingGateway::class);
    $settings = $container->get(BehaviourPolicy::class)->getSettings();

    // The descriptor and level lists belong to core's own Behaviour module,
    // and so does the choice of whether either is used at all. Read them
    // exactly as behaviour_manage_edit.php does, so this page can never
    // offer a descriptor the Behaviour module itself would reject.
    $enableDescriptors = $settingGateway
        ->getSettingByScope('Behaviour', 'enableDescriptors') == 'Y';
    $enableLevels = $settingGateway
        ->getSettingByScope('Behaviour', 'enableLevels') == 'Y';

    /**
     * Turns one of core's comma-separated Behaviour setting lists into
     * select options.
     *
     * @param string $value The raw setting value.
     *
     * @return array
     */
    $optionsFromSetting = function ($value) {
        $options = !empty($value) ? explode(',', $value) : [];

        // Core's own levels list starts with an empty entry, which would
        // render as a blank option next to the placeholder.
        return array_filter(array_map('trim', $options), 'strlen');
    };

    $positiveDescriptors = $optionsFromSetting(
        $settingGateway->getSettingByScope('Behaviour', 'positiveDescriptors')
    );
    $negativeDescriptors = $optionsFromSetting(
        $settingGateway->getSettingByScope('Behaviour', 'negativeDescriptors')
    );
    $levels = $optionsFromSetting(
        $settingGateway->getSettingByScope('Behaviour', 'levels')
    );

    echo '<p>';
    echo __(
        'Every reward and sanction given in a room is recorded by this '
        . 'module, and reported on under Reports. Separately from that, a '
        . 'record can also be written into the Behaviour module. That is '
        . 'off unless you turn it on here, so a quick tap during a lesson '
        . 'does not become a permanent behaviour record unless the school '
        . 'wants it to.'
    );
    echo '</p>';

    $form = Form::create(
        'behaviourSettings',
        $session->get('absoluteURL').'/modules/'.$session->get('module')
            .'/behaviourSettingsProcess.php'
    );
    $form->addHiddenValue('address', $session->get('address'));

    $form->addRow()->addHeading('recording', __('Recording'));

    $row = $form->addRow();
    $row->addLabel('behaviourWriteEnabled', __('Record in Behaviour'))
        ->description(__('Also write a Behaviour record, as set out below.'));
    $row->addYesNo('behaviourWriteEnabled')
        ->selected($settings['enabled'] ? 'Y' : 'N')
        ->required();

    $row = $form->addRow();
    $row->addLabel('behaviourThreshold', __('Threshold'))
        ->description(__(
            'How many points of one type a student needs in one lesson '
            . 'before the first Behaviour record is written.'
        ));
    $row->addNumber('behaviourThreshold')
        ->minimum(1)
        ->maximum(99)
        ->setValue($settings['threshold'] > 0 ? $settings['threshold'] : 3)
        ->required();

    $row = $form->addRow();
    $row->addLabel('behaviourRepeatEvery', __('Then Every'))
        ->description(__(
            'One more Behaviour record every this many points after the '
            . 'threshold. Set to 0 for a single record per lesson.'
        ));
    $row->addNumber('behaviourRepeatEvery')
        ->minimum(0)
        ->maximum(99)
        ->setValue($settings['repeat'])
        ->required();

    // Two sections, one per type. Rewards mode offers only a Reward button
    // and a Sanction button, so every record of one type carries the same
    // descriptor, level and incident text - there is nothing to choose from
    // at the moment of the click.
    $types = [
        'Positive' => [
            'heading'     => __('Rewards'),
            'descriptors' => $positiveDescriptors,
        ],
        'Negative' => [
            'heading'     => __('Sanctions'),
            'descriptors' => $negativeDescriptors,
        ],
    ];

    foreach ($types as $type => $definition) {
        $form->addRow()->addHeading('heading'.$type, $definition['heading']);

        if ($enableDescriptors) {
            $row = $form->addRow();
            $row->addLabel('behaviour'.$type.'Descriptor', __('Descriptor'));
            $row->addSelect('behaviour'.$type.'Descriptor')
                ->fromArray($definition['descriptors'])
                ->selected($settings[$type]['descriptor'])
                ->placeholder(__('None'));
        }

        if ($enableLevels) {
            $row = $form->addRow();
            $row->addLabel('behaviour'.$type.'Level', __('Level'));
            $row->addSelect('behaviour'.$type.'Level')
                ->fromArray($levels)
                ->selected($settings[$type]['level'])
                ->placeholder(__('None'));
        }

        $row = $form->addRow();
        $column = $row->addColumn();
        $column->addLabel('behaviour'.$type.'Comment', __('Incident'))
            ->description(__('Written as the incident text on every record.'));
        $column->addTextArea('behaviour'.$type.'Comment')
            ->setRows(3)
            ->setClass('w-full')
            ->setValue($settings[$type]['comment']);
    }

    if (!$enableDescriptors || !$enableLevels) {
        echo Format::alert(
            __(
                'Descriptors and levels are only offered here when the '
                . 'Behaviour module itself has them turned on. Turn them on '
                . 'in Behaviour\'s own settings to choose one.'
            ),
            'message'
        );
    }

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

    echo $form->getOutput();
}
