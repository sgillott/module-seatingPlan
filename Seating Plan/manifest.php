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

// This file describes the module, including database tables.

// Basic variables
$name        = 'Seating Plan';
$description = 'Graphical classroom seating plans. Draw a room, seat students '
    . 'from timetabled classes, and take the register from the plan.';
$entryURL    = 'seatingPlans.php';
$type        = 'Additional';
$category    = 'Learn';
$version     = '0.13.00';
$author      = 'Steve Gillott';
$url         = 'https://github.com/SteveGillott';

// Module tables
$moduleTables[] = "CREATE TABLE `seatingPlanRoomLayout` (
    `seatingPlanRoomLayoutID` int(8) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonSpaceID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `name` varchar(40) NOT NULL,
    `gridCols` int(3) NOT NULL DEFAULT 20,
    `gridRows` int(3) NOT NULL DEFAULT 14,
    `gibbonPersonIDOwner` int(10) UNSIGNED ZEROFILL NOT NULL,
    `shared` enum('Y','N') NOT NULL DEFAULT 'Y',
    `seatingPlanRoomLayoutIDSource` int(8) UNSIGNED ZEROFILL DEFAULT NULL
        COMMENT 'The layout this one was copied from, if any',
    `sourceTimestamp` datetime DEFAULT NULL
        COMMENT 'The source layout timestampModified when it was copied',
    `timestampModified` timestamp NULL DEFAULT NULL,
    `editVersion` int(10) UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`seatingPlanRoomLayoutID`),
    KEY `gibbonSpaceID` (`gibbonSpaceID`),
    KEY `gibbonPersonIDOwner` (`gibbonPersonIDOwner`),
    KEY `source` (`seatingPlanRoomLayoutIDSource`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

$moduleTables[] = "CREATE TABLE `seatingPlanFurniture` (
    `seatingPlanFurnitureID` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `seatingPlanRoomLayoutID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `type` varchar(16) NOT NULL,
    `posX` int(5) NOT NULL COMMENT 'Tenths of a cell from the left wall',
    `posY` int(5) NOT NULL COMMENT 'Tenths of a cell from the front wall',
    `rotation` int(1) NOT NULL DEFAULT 0,
    `flipped` enum('N','Y') NOT NULL DEFAULT 'N' COMMENT 'Mirrored horizontally',
    `sizeX` int(5) NOT NULL DEFAULT 10 COMMENT 'Width in tenths of a cell',
    `sizeY` int(5) NOT NULL DEFAULT 10 COMMENT 'Height in tenths of a cell',
    PRIMARY KEY (`seatingPlanFurnitureID`),
    KEY `seatingPlanRoomLayoutID` (`seatingPlanRoomLayoutID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

$moduleTables[] = "CREATE TABLE `seatingPlanPlan` (
    `seatingPlanPlanID` int(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `seatingPlanRoomLayoutID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSpaceID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `classList` varchar(255) NOT NULL,
    `gibbonPersonIDOwner` int(10) UNSIGNED ZEROFILL NOT NULL,
    `name` varchar(40) NOT NULL,
    `isDefault` enum('Y','N') NOT NULL DEFAULT 'N',
    `options` text,
    `timestampModified` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`seatingPlanPlanID`), KEY `gibbonSpaceClassList` (`gibbonSpaceID`,`classList`(64)), UNIQUE KEY `planIdentity` (`seatingPlanRoomLayoutID`,`classList`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

$moduleTables[] = "CREATE TABLE `seatingPlanSeat` (
    `seatingPlanSeatID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `seatingPlanPlanID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonPersonID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `posX` int(5) NOT NULL COMMENT 'Tenths of a cell from the left wall',
    `posY` int(5) NOT NULL COMMENT 'Tenths of a cell from the front wall',
    PRIMARY KEY (`seatingPlanSeatID`),
    UNIQUE KEY `onePlacePerStudent` (`seatingPlanPlanID`,`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

$moduleTables[] = "CREATE TABLE `seatingPlanHouseColour` (
    `gibbonHouseID` int(3) UNSIGNED ZEROFILL NOT NULL,
    `colour` varchar(7) NOT NULL,
    PRIMARY KEY (`gibbonHouseID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

$moduleTables[] = "CREATE TABLE `seatingPlanBadgeConfig` (
    `gibbonPersonID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `badges` text,
    PRIMARY KEY (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

$moduleTables[] = "CREATE TABLE `seatingPlanRoomExit` (
    `seatingPlanRoomExitID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonPersonID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSpaceID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonCourseClassID` int(8) UNSIGNED ZEROFILL NOT NULL DEFAULT 0
        COMMENT '0 when the student belongs to more than one class in the room',
    `gibbonTTColumnRowID` int(8) UNSIGNED ZEROFILL NOT NULL DEFAULT 0,
    `gibbonSchoolYearID` int(3) UNSIGNED ZEROFILL NOT NULL DEFAULT 0,
    `timeOut` datetime NOT NULL,
    `timeIn` datetime DEFAULT NULL,
    `gibbonPersonIDCreator` int(10) UNSIGNED ZEROFILL NOT NULL,
    PRIMARY KEY (`seatingPlanRoomExitID`),
    KEY `lookup` (`gibbonSpaceID`,`timeIn`),
    KEY `reporting` (`gibbonSchoolYearID`,`timeOut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// This module's own ledger of reward and sanction points. One row per
// (student, lesson, recording teacher), counters incremented in place.
// gibbonBehaviour cannot answer \"what happened in this lesson\" - it has no
// room, period or class column - so everything the module reports on is
// counted here, whether or not a gibbonBehaviour record is also written.
$moduleTables[] = "CREATE TABLE `seatingPlanReward` (
    `seatingPlanRewardID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonPersonID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonCourseClassID` int(8) UNSIGNED ZEROFILL NOT NULL DEFAULT 0
        COMMENT '0 when the student belongs to more than one class in the room',
    `gibbonSpaceID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonTTColumnRowID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSchoolYearID` int(3) UNSIGNED ZEROFILL NOT NULL,
    `date` date NOT NULL,
    `gibbonPersonIDCreator` int(10) UNSIGNED ZEROFILL NOT NULL,
    `positive` int(4) NOT NULL DEFAULT 0,
    `negative` int(4) NOT NULL DEFAULT 0,
    `positiveLogged` int(4) NOT NULL DEFAULT 0
        COMMENT 'Behaviour records already written for the positive count',
    `negativeLogged` int(4) NOT NULL DEFAULT 0
        COMMENT 'Behaviour records already written for the negative count',
    `timestampModified` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`seatingPlanRewardID`),
    UNIQUE KEY `lesson` (`gibbonPersonID`,`date`,`gibbonTTColumnRowID`,
        `gibbonCourseClassID`,`gibbonPersonIDCreator`),
    KEY `reporting` (`gibbonSchoolYearID`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";

// Module settings. Read and written with core's own SettingGateway; there is
// no module gateway for these. Run at install only - an existing install
// picks these up from CHANGEDB.php instead.
$gibbonSetting = [];

$gibbonSetting[] ="INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourWriteEnabled', 'Record in Behaviour', 'Also write rewards and sanctions into the Behaviour module.', 'N');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourThreshold', 'Threshold', 'How many points in one lesson before the first Behaviour record is written.', '3');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourRepeatEvery', 'Then Every', 'One more Behaviour record every this many points after the threshold. Set to 0 for a single record per lesson.', '1');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourPositiveDescriptor', 'Reward Descriptor', 'Descriptor written on a positive Behaviour record.', '');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourPositiveLevel', 'Reward Level', 'Level written on a positive Behaviour record.', '');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourPositiveComment', 'Reward Incident', 'Incident text written on a positive Behaviour record.', 'Recorded from the seating plan.');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourNegativeDescriptor', 'Sanction Descriptor', 'Descriptor written on a negative Behaviour record.', '');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourNegativeLevel', 'Sanction Level', 'Level written on a negative Behaviour record.', '');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Seating Plan', 'behaviourNegativeComment', 'Sanction Incident', 'Incident text written on a negative Behaviour record.', 'Recorded from the seating plan.');";

// Action rows
$actionRows[] = [
    'name'                      => 'My Lessons',
    'precedence'                => '0',
    'category'                  => 'Plans',
    'description'               => 'Open the room for a timetabled lesson.',
    'URLList'                   => 'seatingPlans.php,room.php,layout_saveAjax.php,'
        . 'seating_saveAjax.php,register_saveAjax.php,badge_saveAjax.php,'
        . 'reward_saveAjax.php,roomExit_saveAjax.php',
    'entryURL'                  => 'seatingPlans.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'Y',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Room Layouts',
    'precedence'                => '0',
    'category'                  => 'Plans',
    'description'               => 'Draw the furniture layout of a room.',
    'URLList'                   => 'layouts.php,layout_add.php,layout_addProcess.php,'
        . 'room.php,layout_saveAjax.php,layout_duplicateProcess.php,'
        . 'layout_delete.php,layout_deleteProcess.php,'
        . 'layout_editDetails.php,layout_editDetailsProcess.php,'
        . 'layout_transfer.php,layout_transferProcess.php,'
        . 'layout_update.php,layout_updateProcess.php',
    'entryURL'                  => 'layouts.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'Y',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Badge Settings',
    'precedence'                => '0',
    'category'                  => 'Settings',
    'description'               => 'Set the colour shown for each house on a '
        . 'student\'s seating tile badge.',
    'URLList'                   => 'houseColourSettings.php,'
        . 'houseColourSettingsProcess.php',
    'entryURL'                  => 'houseColourSettings.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Behaviour Settings',
    'precedence'                => '0',
    'category'                  => 'Settings',
    'description'               => 'Choose whether rewards and sanctions are '
        . 'also written into the Behaviour module, and how.',
    'URLList'                   => 'behaviourSettings.php,'
        . 'behaviourSettingsProcess.php',
    'entryURL'                  => 'behaviourSettings.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

// Reports are a grouped action, the convention core's own Behaviour module
// uses: one menu entry, one set of pages, and the highest-precedence action
// the viewer holds decides whose data they see. _all is every student in the
// school; _my is only students in classes the viewer teaches.
$reportURLList = 'report_rewards.php,report_roomExits.php,report_student.php';

$actionRows[] = [
    'name'                      => 'Reports_all',
    'precedence'                => '1',
    'category'                  => 'Reports',
    'description'               => 'Report on rewards, sanctions and room '
        . 'exits for every student.',
    'URLList'                   => $reportURLList,
    'entryURL'                  => 'report_rewards.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

$actionRows[] = [
    'name'                      => 'Reports_my',
    'precedence'                => '0',
    'category'                  => 'Reports',
    'description'               => 'Report on rewards, sanctions and room '
        . 'exits for students in your own classes.',
    'URLList'                   => $reportURLList,
    'entryURL'                  => 'report_rewards.php',
    'entrySidebar'              => 'Y',
    'menuShow'                  => 'Y',
    'defaultPermissionAdmin'    => 'N',
    'defaultPermissionTeacher'  => 'Y',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

// Correcting a record afterwards is a different thing from being allowed to
// look at it, so it gets a permission of its own rather than riding on
// Reports. menuShow is N: there is no page to visit, this action only turns
// on the edit and delete links inside the per-student report.
$actionRows[] = [
    'name'                      => 'Manage Records',
    'precedence'                => '0',
    'category'                  => 'Reports',
    'description'               => 'Correct or remove a student\'s reward, '
        . 'sanction or room exit record for a lesson.',
    'URLList'                   => 'record_edit.php,record_editProcess.php,'
        . 'record_delete.php,record_deleteProcess.php',
    'entryURL'                  => 'record_edit.php',
    'entrySidebar'              => 'N',
    'menuShow'                  => 'N',
    'defaultPermissionAdmin'    => 'Y',
    'defaultPermissionTeacher'  => 'N',
    'defaultPermissionStudent'  => 'N',
    'defaultPermissionParent'   => 'N',
    'defaultPermissionSupport'  => 'N',
    'categoryPermissionStaff'   => 'Y',
    'categoryPermissionStudent' => 'N',
    'categoryPermissionParent'  => 'N',
    'categoryPermissionOther'   => 'N',
];

// Hooks - reachable from the Staff Dashboard and from inside a lesson plan,
// without going through My Lessons first. Gated on the same "My Lessons"
// action the entry page itself requires, via sourceModuleAction. The NOT
// EXISTS guard makes this array safe to run again on an already-installed
// module (used by this project's own non-destructive reinstall script),
// not only on a fresh install.
$staffDashboardOptions = addslashes(serialize([
    'sourceModuleName'    => $name,
    'sourceModuleAction'  => 'My Lessons',
    'sourceModuleInclude' => 'hook_staffDashboard.php',
]));

$hooks[] = "INSERT INTO gibbonHook (name, type, options, gibbonModuleID)
    SELECT 'Seating Plans', 'Staff Dashboard', '{$staffDashboardOptions}',
        gibbonModuleID
    FROM gibbonModule
    WHERE name='{$name}'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonHook
        WHERE name='Seating Plans' AND type='Staff Dashboard'
    )";

$lessonPlannerOptions = addslashes(serialize([
    'sourceModuleName'    => $name,
    'sourceModuleAction'  => 'My Lessons',
    'sourceModuleInclude' => 'hook_lessonPlanner.php',
]));

$hooks[] = "INSERT INTO gibbonHook (name, type, options, gibbonModuleID)
    SELECT 'Seating Plans', 'Lesson Planner', '{$lessonPlannerOptions}',
        gibbonModuleID
    FROM gibbonModule
    WHERE name='{$name}'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonHook
        WHERE name='Seating Plans' AND type='Lesson Planner'
    )";
