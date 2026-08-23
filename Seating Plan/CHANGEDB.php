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

// Database changes, one block per version. Every schema change after the first
// release goes here, so an installed module migrates in place and nobody loses
// the layouts they have drawn.

$sql = [];
$count = 0;

// v0.1.06
$sql[$count][0] = "0.1.06";
$sql[$count][1] = "ALTER TABLE seatingPlanFurniture
    ADD COLUMN flipped enum('N','Y') NOT NULL DEFAULT 'N' AFTER rotation
;end
-- Adds a mirror flag so a door can have its hinge on either side. Rotation
-- alone gives four orientations; with the flip a door has all eight.";
++$count;

// v0.6.00
$sql[$count][0] = "0.6.00";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `seatingPlanHouseColour` (
    `gibbonHouseID` int(3) UNSIGNED ZEROFILL NOT NULL,
    `colour` varchar(7) NOT NULL,
    PRIMARY KEY (`gibbonHouseID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
;end
-- Phase 5: corner badges. One colour per house, since gibbonHouse itself
-- has no colour column.
INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category,
        description, URLList, entryURL, entrySidebar, menuShow,
        defaultPermissionAdmin, defaultPermissionTeacher,
        defaultPermissionStudent, defaultPermissionParent,
        defaultPermissionSupport, categoryPermissionStaff,
        categoryPermissionStudent, categoryPermissionParent,
        categoryPermissionOther)
    SELECT gibbonModuleID, 'Badge Settings', '0', 'Plans',
        'Set the colour shown for each house on a student\'s seating tile badge.',
        'houseColourSettings.php,houseColourSettingsProcess.php',
        'houseColourSettings.php', 'Y', 'Y',
        'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'
    FROM gibbonModule
    WHERE name='Seating Plan'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonAction
        WHERE name='Badge Settings'
        AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
            WHERE name='Seating Plan')
    )
;end
-- New action added after the initial install: an existing install's
-- database Update only runs this file, never re-reads manifest.php's own
-- actionRows, so the action row has to be created here by hand. Admin-only,
-- matching the manifest's own defaultPermission flags for this action.
INSERT INTO gibbonPermission (gibbonActionID, gibbonRoleID)
    SELECT gibbonActionID, '001'
    FROM gibbonAction
    WHERE name='Badge Settings'
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
    AND NOT EXISTS (
        SELECT 1 FROM gibbonPermission
        WHERE gibbonActionID=gibbonAction.gibbonActionID
        AND gibbonRoleID='001'
    )
;end
-- Grants the Admin role (001) access to the new action, matching what a
-- fresh install's defaultPermissionAdmin='Y' would have granted.
INSERT INTO gibbonHook (name, type, options, gibbonModuleID)
    SELECT 'Seating Plans', 'Staff Dashboard',
        'a:3:{s:16:\"sourceModuleName\";s:12:\"Seating Plan\";s:18:\"sourceModuleAction\";s:10:\"My Lessons\";s:19:\"sourceModuleInclude\";s:23:\"hook_staffDashboard.php\";}',
        gibbonModuleID
    FROM gibbonModule
    WHERE name='Seating Plan'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonHook
        WHERE name='Seating Plans' AND type='Staff Dashboard'
    )
;end
-- Staff Dashboard hook, same options shape manifest.php's own hooks array
-- builds with serialize() at install time - hand-serialized here since
-- CHANGEDB.php cannot run PHP, only SQL.
INSERT INTO gibbonHook (name, type, options, gibbonModuleID)
    SELECT 'Seating Plans', 'Lesson Planner',
        'a:3:{s:16:\"sourceModuleName\";s:12:\"Seating Plan\";s:18:\"sourceModuleAction\";s:10:\"My Lessons\";s:19:\"sourceModuleInclude\";s:22:\"hook_lessonPlanner.php\";}',
        gibbonModuleID
    FROM gibbonModule
    WHERE name='Seating Plan'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonHook
        WHERE name='Seating Plans' AND type='Lesson Planner'
    )
;end
-- Lesson Planner hook.";
++$count;

// v0.7.00
$sql[$count][0] = "0.7.00";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `seatingPlanBadgeConfig` (
    `gibbonPersonID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `badges` text,
    PRIMARY KEY (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
;end
-- Corner badges moved from a per-plan setting to a per-teacher one: set
-- once, it now applies to every room a teacher opens, rather than being
-- configured separately for every class. seatingPlanPlan.options is no
-- longer used for badges (still holds the schema's original reservation
-- for a future border-mode/name-format setting, untouched).
UPDATE gibbonAction
    SET URLList=CONCAT(URLList, ',badge_saveAjax.php')
    WHERE name='My Lessons'
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
    AND URLList NOT LIKE '%badge_saveAjax.php%'
;end
-- The new per-teacher save endpoint needs to be covered by the same
-- action's URLList as the module's other AJAX endpoints, on an install
-- that already has this row from before this version.";
++$count;

// v0.9.00
$sql[$count][0] = "0.9.00";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `seatingPlanRoomExit` (
    `seatingPlanRoomExitID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonPersonID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSpaceID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `timeOut` datetime NOT NULL,
    `timeIn` datetime DEFAULT NULL,
    `gibbonPersonIDCreator` int(10) UNSIGNED ZEROFILL NOT NULL,
    PRIMARY KEY (`seatingPlanRoomExitID`),
    KEY `lookup` (`gibbonSpaceID`,`timeIn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
;end
-- Phase 7: Rewards mode writes straight to core's own gibbonBehaviour, no
-- table of its own needed. Room Exits has no core concept to reuse, so it
-- gets this one, owned entirely by this module.
UPDATE gibbonAction
    SET URLList=CONCAT(URLList, ',reward_saveAjax.php,roomExit_saveAjax.php')
    WHERE name='My Lessons'
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
    AND URLList NOT LIKE '%reward_saveAjax.php%'
;end
-- Both new endpoints need to be covered by the same action's URLList as
-- the module's other AJAX endpoints, on an install that already has this
-- row from before this version.";
++$count;

// v0.10.00
$sql[$count][0] = "0.10.00";
$sql[$count][1] = "-- This module's own ledger of reward and sanction points.
-- gibbonBehaviour has no room, period or class column, so it cannot answer
-- 'what happened in this lesson'. Everything the Reports pages read is
-- counted here, whether or not a gibbonBehaviour record is also written.
CREATE TABLE IF NOT EXISTS `seatingPlanReward` (
    `seatingPlanRewardID` int(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonPersonID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonCourseClassID` int(8) UNSIGNED ZEROFILL NOT NULL DEFAULT 0,
    `gibbonSpaceID` int(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonTTColumnRowID` int(8) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSchoolYearID` int(3) UNSIGNED ZEROFILL NOT NULL,
    `date` date NOT NULL,
    `gibbonPersonIDCreator` int(10) UNSIGNED ZEROFILL NOT NULL,
    `positive` int(4) NOT NULL DEFAULT 0,
    `negative` int(4) NOT NULL DEFAULT 0,
    `timestampModified` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`seatingPlanRewardID`),
    UNIQUE KEY `lesson` (`gibbonPersonID`,`date`,`gibbonTTColumnRowID`,
        `gibbonCourseClassID`,`gibbonPersonIDCreator`),
    KEY `reporting` (`gibbonSchoolYearID`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
;end
-- Room exits gain the same lesson context the reward ledger carries, so
-- they can be reported by class, subject and period rather than only by
-- room and time. Rows written before this version keep the 0 default,
-- which reads as 'not attributable' exactly as it does for a student
-- enrolled in more than one of a room's classes.
ALTER TABLE seatingPlanRoomExit
    ADD COLUMN gibbonCourseClassID int(8) UNSIGNED ZEROFILL NOT NULL DEFAULT 0
        AFTER gibbonSpaceID,
    ADD COLUMN gibbonTTColumnRowID int(8) UNSIGNED ZEROFILL NOT NULL DEFAULT 0
        AFTER gibbonCourseClassID,
    ADD COLUMN gibbonSchoolYearID int(3) UNSIGNED ZEROFILL NOT NULL DEFAULT 0
        AFTER gibbonTTColumnRowID,
    ADD KEY reporting (gibbonSchoolYearID, timeOut)
;end
-- Module settings. manifest.php's own \$gibbonSetting array runs at fresh
-- install only, so an existing install needs each row created here. Each
-- is guarded so re-running cannot duplicate it.
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourWriteEnabled', 'Record in Behaviour',
        'Also write rewards and sanctions into the Behaviour module.', 'N'
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourWriteEnabled')
;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourThreshold', 'Threshold',
        'How many points in one lesson before the first Behaviour record is written.',
        '3'
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourThreshold')
;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourRepeatEvery', 'Then Every',
        'One more Behaviour record every this many points after the threshold. Set to 0 for a single record per lesson.',
        '1'
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourRepeatEvery')
;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourPositiveDescriptor', 'Reward Descriptor',
        'Descriptor written on a positive Behaviour record.', ''
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourPositiveDescriptor')
;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourPositiveLevel', 'Reward Level',
        'Level written on a positive Behaviour record.', ''
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourPositiveLevel')
;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourPositiveComment', 'Reward Incident',
        'Incident text written on a positive Behaviour record.',
        'Recorded from the seating plan.'
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourPositiveComment')
;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourNegativeDescriptor', 'Sanction Descriptor',
        'Descriptor written on a negative Behaviour record.', ''
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourNegativeDescriptor')
;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourNegativeLevel', 'Sanction Level',
        'Level written on a negative Behaviour record.', ''
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourNegativeLevel')
;end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
    SELECT 'Seating Plan', 'behaviourNegativeComment', 'Sanction Incident',
        'Incident text written on a negative Behaviour record.',
        'Recorded from the seating plan.'
    FROM gibbonModule
    WHERE gibbonModule.name='Seating Plan'
    AND NOT EXISTS (SELECT 1 FROM gibbonSetting AS existing
        WHERE existing.scope='Seating Plan'
        AND existing.name='behaviourNegativeComment')
;end
-- Badge Settings moves out of the Plans menu group and into a Settings
-- group of its own, alongside the new Behaviour Settings page. The sidebar
-- groups a module's actions by gibbonAction.category, and manifest.php's
-- own actionRows are read at fresh install only, so an existing install
-- needs the category changed here.
UPDATE gibbonAction
    SET category='Settings'
    WHERE name='Badge Settings'
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
;end
INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category,
        description, URLList, entryURL, entrySidebar, menuShow,
        defaultPermissionAdmin, defaultPermissionTeacher,
        defaultPermissionStudent, defaultPermissionParent,
        defaultPermissionSupport, categoryPermissionStaff,
        categoryPermissionStudent, categoryPermissionParent,
        categoryPermissionOther)
    SELECT gibbonModuleID, 'Behaviour Settings', '0', 'Settings',
        'Choose whether rewards and sanctions are also written into the Behaviour module, and how.',
        'behaviourSettings.php,behaviourSettingsProcess.php',
        'behaviourSettings.php', 'Y', 'Y',
        'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'
    FROM gibbonModule
    WHERE name='Seating Plan'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonAction AS existing
        WHERE existing.name='Behaviour Settings'
        AND existing.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
            WHERE name='Seating Plan')
    )
;end
-- Reports are a grouped action, the same shape core's own Behaviour module
-- uses: one menu entry, and whichever of _all / _my the viewer holds
-- decides whose data the pages show.
INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category,
        description, URLList, entryURL, entrySidebar, menuShow,
        defaultPermissionAdmin, defaultPermissionTeacher,
        defaultPermissionStudent, defaultPermissionParent,
        defaultPermissionSupport, categoryPermissionStaff,
        categoryPermissionStudent, categoryPermissionParent,
        categoryPermissionOther)
    SELECT gibbonModuleID, 'Reports_all', '1', 'Reports',
        'Report on rewards, sanctions and room exits for every student.',
        'report_rewards.php,report_roomExits.php,report_student.php',
        'report_rewards.php', 'Y', 'Y',
        'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'
    FROM gibbonModule
    WHERE name='Seating Plan'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonAction AS existing
        WHERE existing.name='Reports_all'
        AND existing.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
            WHERE name='Seating Plan')
    )
;end
INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category,
        description, URLList, entryURL, entrySidebar, menuShow,
        defaultPermissionAdmin, defaultPermissionTeacher,
        defaultPermissionStudent, defaultPermissionParent,
        defaultPermissionSupport, categoryPermissionStaff,
        categoryPermissionStudent, categoryPermissionParent,
        categoryPermissionOther)
    SELECT gibbonModuleID, 'Reports_my', '0', 'Reports',
        'Report on rewards, sanctions and room exits for students in your own classes.',
        'report_rewards.php,report_roomExits.php,report_student.php',
        'report_rewards.php', 'Y', 'Y',
        'N', 'Y', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'
    FROM gibbonModule
    WHERE name='Seating Plan'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonAction AS existing
        WHERE existing.name='Reports_my'
        AND existing.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
            WHERE name='Seating Plan')
    )
;end
-- Grants matching what a fresh install's defaultPermission flags would
-- have granted: Administrator (001) on the admin-only actions, Teacher
-- (002) on the my-classes report.
INSERT INTO gibbonPermission (gibbonActionID, gibbonRoleID)
    SELECT gibbonActionID, '001'
    FROM gibbonAction
    WHERE name IN ('Behaviour Settings', 'Reports_all')
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
    AND NOT EXISTS (
        SELECT 1 FROM gibbonPermission AS existing
        WHERE existing.gibbonActionID=gibbonAction.gibbonActionID
        AND existing.gibbonRoleID='001'
    )
;end
INSERT INTO gibbonPermission (gibbonActionID, gibbonRoleID)
    SELECT gibbonActionID, '002'
    FROM gibbonAction
    WHERE name='Reports_my'
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
    AND NOT EXISTS (
        SELECT 1 FROM gibbonPermission AS existing
        WHERE existing.gibbonActionID=gibbonAction.gibbonActionID
        AND existing.gibbonRoleID='002'
    )";
++$count;

// v0.11.00
$sql[$count][0] = "0.11.00";
$sql[$count][1] = "-- Room layouts can now be exported to a JSON file and
-- imported back, so a layout can be sent to a colleague, kept as a backup
-- before a room is rearranged, or moved to another Gibbon installation. No
-- new tables: a layout is only furniture, and the file carries nothing else.
-- The two new pages belong to the existing Room Layouts action, whose
-- URLList an already-installed copy has to be told about here.
UPDATE gibbonAction
    SET URLList=CONCAT(URLList, ',layout_transfer.php,layout_transferProcess.php')
    WHERE name='Room Layouts'
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
    AND URLList NOT LIKE '%layout_transfer.php%'";
++$count;

// v0.12.00
$sql[$count][0] = "0.12.00";
$sql[$count][1] = "-- Where a layout came from, so a copy can be told when the
-- original has been improved. sourceTimestamp records the source's own
-- timestampModified at the moment of the copy - an update is available when
-- the source has moved on since. Both are null for a layout drawn from
-- scratch, which is every layout that exists before this version.
ALTER TABLE seatingPlanRoomLayout
    ADD COLUMN seatingPlanRoomLayoutIDSource int(8) UNSIGNED ZEROFILL DEFAULT NULL
        AFTER shared,
    ADD COLUMN sourceTimestamp datetime DEFAULT NULL
        AFTER seatingPlanRoomLayoutIDSource,
    ADD KEY source (seatingPlanRoomLayoutIDSource)
;end
-- The accept/dismiss pages belong to the existing Room Layouts action.
UPDATE gibbonAction
    SET URLList=CONCAT(URLList, ',layout_update.php,layout_updateProcess.php')
    WHERE name='Room Layouts'
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
    AND URLList NOT LIKE '%layout_update.php%'";
++$count;

// v0.12.01
$sql[$count][0] = "0.12.01";
$sql[$count][1] = "-- Add a monotonic layout version for optimistic save checks.
ALTER TABLE seatingPlanRoomLayout
    ADD COLUMN editVersion int(10) UNSIGNED NOT NULL DEFAULT 1
        AFTER timestampModified
;end
-- Validate and canonicalise every legacy class list before the identity key.
-- The CHECK constraint makes malformed data fail the migration rather than
-- silently inventing a class set. The sequence covers the maximum number of
-- comma-separated IDs that fit in the varchar(255) column.
CREATE TEMPORARY TABLE seatingPlanClassNumber (n smallint PRIMARY KEY)
;end
INSERT INTO seatingPlanClassNumber (n)
    SELECT ones.n + tens.n * 10 + hundreds.n * 100 + 1
    FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
          SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL
          SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS ones
    CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
          SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL
          SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS tens
    CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2) AS hundreds
    WHERE ones.n + tens.n * 10 + hundreds.n * 100 < 255
;end
CREATE TEMPORARY TABLE seatingPlanClassToken (
    seatingPlanPlanID int(10) UNSIGNED NOT NULL,
    token varchar(32) NOT NULL,
    CHECK (token REGEXP '^[0-9]+$' AND token REGEXP '[1-9]')
)
;end
INSERT INTO seatingPlanClassToken (seatingPlanPlanID, token)
    SELECT p.seatingPlanPlanID,
        TRIM(SUBSTRING_INDEX(
            SUBSTRING_INDEX(p.classList, ',', n.n), ',', -1
        ))
    FROM seatingPlanPlan AS p
    JOIN seatingPlanClassNumber AS n
        ON n.n <= 1 + LENGTH(p.classList)
            - LENGTH(REPLACE(p.classList, ',', ''))
;end
UPDATE seatingPlanPlan AS p
JOIN (
    SELECT seatingPlanPlanID,
        GROUP_CONCAT(
            DISTINCT CAST(token AS UNSIGNED)
            ORDER BY CAST(token AS UNSIGNED)
            SEPARATOR ','
        ) AS canonicalClassList
    FROM seatingPlanClassToken
    GROUP BY seatingPlanPlanID
) AS canonical
    ON canonical.seatingPlanPlanID=p.seatingPlanPlanID
SET p.classList=canonical.canonicalClassList
;end
DROP TEMPORARY TABLE seatingPlanClassToken
;end
DROP TEMPORARY TABLE seatingPlanClassNumber
;end-- Keep the row selected by the gateway and merge exact duplicate plan keys.
-- Non-conflicting seats move to the retained plan; a retained-plan seat wins
-- when both plans contain the same student.
CREATE TEMPORARY TABLE seatingPlanPlanKeep AS
    SELECT p.seatingPlanRoomLayoutID, p.classList, p.seatingPlanPlanID
    FROM seatingPlanPlan AS p
    WHERE NOT EXISTS (
        SELECT 1 FROM seatingPlanPlan AS better
        WHERE better.seatingPlanRoomLayoutID=p.seatingPlanRoomLayoutID
            AND better.classList=p.classList
            AND (better.isDefault='Y' AND p.isDefault<>'Y'
                OR better.isDefault=p.isDefault
                    AND better.seatingPlanPlanID<p.seatingPlanPlanID)
    )
;end
CREATE TEMPORARY TABLE seatingPlanPlanDuplicate AS
    SELECT p.seatingPlanPlanID, k.seatingPlanPlanID AS keepID
    FROM seatingPlanPlan AS p
    JOIN seatingPlanPlanKeep AS k
        ON k.seatingPlanRoomLayoutID=p.seatingPlanRoomLayoutID
        AND k.classList=p.classList
    WHERE p.seatingPlanPlanID<>k.seatingPlanPlanID
;end
-- The students already seated on a retained plan, listed against the
-- duplicate plan they are about to collide with. MySQL will not let a
-- DELETE read the table it is deleting from, not even in a subquery
-- (error 1093), so the conflicts are materialised here first and the
-- delete below joins against this instead.
CREATE TEMPORARY TABLE seatingPlanSeatConflict AS
    SELECT duplicatePlan.seatingPlanPlanID AS duplicateID,
        keptSeat.gibbonPersonID
    FROM seatingPlanPlanDuplicate AS duplicatePlan
    JOIN seatingPlanSeat AS keptSeat
        ON keptSeat.seatingPlanPlanID=duplicatePlan.keepID
;end
DELETE duplicateSeat
FROM seatingPlanSeat AS duplicateSeat
JOIN seatingPlanSeatConflict AS conflict
    ON conflict.duplicateID=duplicateSeat.seatingPlanPlanID
    AND conflict.gibbonPersonID=duplicateSeat.gibbonPersonID
;end
DROP TEMPORARY TABLE seatingPlanSeatConflict
;end
UPDATE seatingPlanSeat AS seat
JOIN seatingPlanPlanDuplicate AS duplicatePlan
    ON duplicatePlan.seatingPlanPlanID=seat.seatingPlanPlanID
SET seat.seatingPlanPlanID=duplicatePlan.keepID
;end
DELETE plan
FROM seatingPlanPlan AS plan
JOIN seatingPlanPlanDuplicate AS duplicatePlan
    ON duplicatePlan.seatingPlanPlanID=plan.seatingPlanPlanID
;end
DROP TEMPORARY TABLE seatingPlanPlanDuplicate
;end
DROP TEMPORARY TABLE seatingPlanPlanKeep
;end
ALTER TABLE seatingPlanPlan
    ADD UNIQUE KEY planIdentity (seatingPlanRoomLayoutID, classList)";
++$count;

// v0.13.00
$sql[$count][0] = "0.13.00";
$sql[$count][1] = "-- How many Behaviour records this module has actually
-- written for each lesson tally. A point can now be taken back with a
-- right-click, so 'did this point cross the threshold' is no longer a safe
-- test: taking a count from 3 to 2 and back to 3 crosses it twice and would
-- write the same record again. Counting what was written, and comparing it
-- with what the current count deserves, cannot do that.
--
-- Existing rows start at 0. That is deliberately understated for any lesson
-- that already produced a record: the count would have to climb past its
-- next interval before another was written, so the worst case is one record
-- fewer than the settings ask for, on lessons already in the past. The
-- alternative - back-filling a guess from the current counts - would invent
-- history and could suppress a record that is genuinely due.
ALTER TABLE seatingPlanReward
    ADD COLUMN positiveLogged int(4) NOT NULL DEFAULT 0 AFTER negative,
    ADD COLUMN negativeLogged int(4) NOT NULL DEFAULT 0 AFTER positiveLogged
;end
-- Correcting a record afterwards is an administrator's job, and needs a
-- permission of its own: seeing this data (Reports) and amending it are
-- different things.
INSERT INTO gibbonAction (gibbonModuleID, name, precedence, category,
        description, URLList, entryURL, entrySidebar, menuShow,
        defaultPermissionAdmin, defaultPermissionTeacher,
        defaultPermissionStudent, defaultPermissionParent,
        defaultPermissionSupport, categoryPermissionStaff,
        categoryPermissionStudent, categoryPermissionParent,
        categoryPermissionOther)
    SELECT gibbonModuleID, 'Manage Records', '0', 'Reports',
        'Correct or remove a student''s reward, sanction or room exit record for a lesson.',
        'record_edit.php,record_editProcess.php,record_delete.php,record_deleteProcess.php',
        'record_edit.php', 'N', 'N',
        'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'
    FROM gibbonModule
    WHERE name='Seating Plan'
    AND NOT EXISTS (
        SELECT 1 FROM gibbonAction AS existing
        WHERE existing.name='Manage Records'
        AND existing.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
            WHERE name='Seating Plan')
    )
;end
-- menuShow is N: this action has no page of its own to visit, it only turns
-- on the edit and delete links inside the per-student report.
INSERT INTO gibbonPermission (gibbonActionID, gibbonRoleID)
    SELECT gibbonActionID, '001'
    FROM gibbonAction
    WHERE name='Manage Records'
    AND gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule
        WHERE name='Seating Plan')
    AND NOT EXISTS (
        SELECT 1 FROM gibbonPermission AS existing
        WHERE existing.gibbonActionID=gibbonAction.gibbonActionID
        AND existing.gibbonRoleID='001'
    )";
++$count;