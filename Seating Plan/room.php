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

use Gibbon\Http\Url;
use Gibbon\Services\Format;
use Gibbon\Session\TokenHandler;
use Gibbon\Module\SeatingPlan\RoomContext;
use Gibbon\Module\SeatingPlan\RoomContextResolver;
use Gibbon\Module\SeatingPlan\FurnitureCatalogue;
use Gibbon\Module\SeatingPlan\SeatingMode;
use Gibbon\Module\SeatingPlan\RegisterMode;
use Gibbon\Module\SeatingPlan\PickerMode;
use Gibbon\Module\SeatingPlan\RewardsMode;
use Gibbon\Module\SeatingPlan\RoomExitMode;
use Gibbon\Domain\User\UserGateway;
use Gibbon\Module\SeatingPlan\Domain\FurnitureGateway;
use Gibbon\Module\SeatingPlan\Domain\RoomLayoutGateway;

if (
    isActionAccessible($guid, $connection2, '/modules/Seating Plan/room.php')
    == false
) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
    return;
}

$resolver = $container->get(RoomContextResolver::class);
$gibbonPersonID = $session->get('gibbonPersonID');

// Two ways in: a layout to draw furniture in, or a timetabled period with a
// class in it. Both resolve to the same room.
if (!empty($_GET['period'])) {
    $context = $resolver->fromPeriod(
        $_GET['period'],
        $_GET['date'] ?? date('Y-m-d'),
        $gibbonPersonID
    );
} else {
    $context = $resolver->fromLayout(
        $_GET['layout'] ?? '',
        $gibbonPersonID
    );
}

if ($context === null) {
    $page->addError(__('The specified record cannot be found.'));
    return;
}

// Modes that need students are only offered when there are students to show.
// A greyed button invites a question nobody can answer, so an unavailable mode
// is simply absent.
//
// A drawn layout is deliberately NOT part of that test. Every one of these
// modes works in a bare room: the students are laid out in rows, they can be
// moved about and saved where they are put, the register can be taken, names
// drawn, points given and exits logged. A room with no furniture in it is a
// room with no chairs, so the floor is the plan - see js/seatPlacement.js and
// SeatingPlanGateway::validateSeats(). The layout itself is started by the
// first save that needs one.
//
// Register additionally needs core's own Attendance permission - a teacher
// without it never sees the tab, matching how
// modules/Planner/planner_view_full.php gates its own attendance UI on the
// same core action.
$seatedModesAvailable = $context->hasPeriod();

$modes = [
    'furniture' => [
        'label'     => __('Furniture'),
        'icon'      => 'squares',
        'available' => true,
    ],
    'seating' => [
        'label'     => __('Seating'),
        'icon'      => 'users',
        'available' => $seatedModesAvailable,
    ],
    'register' => [
        'label'     => __('Register'),
        'icon'      => 'document-check',
        'available' => $seatedModesAvailable && isActionAccessible(
            $guid,
            $connection2,
            '/modules/Attendance/attendance_take_byCourseClass.php'
        ),
    ],
    'picker' => [
        'label'     => __('Picker'),
        'icon'      => 'lightbulb',
        // A live cold-call tool only makes sense for someone who actually
        // teaches one of the room's classes - the same population who can
        // arrange the seats, not a colleague merely viewing a shared plan.
        'available' => $seatedModesAvailable && $context->isEditable(),
    ],
    'rewards' => [
        'label'     => __('Rewards'),
        'icon'      => 'trophy',
        'available' => $seatedModesAvailable && $context->isEditable(),
    ],
    'roomexit' => [
        'label'     => __('Room Exits'),
        'icon'      => 'external-link',
        'available' => $seatedModesAvailable && $context->isEditable(),
    ],
];

$mode = $_GET['mode'] ?? 'furniture';

if (empty($modes[$mode]) || !$modes[$mode]['available']) {
    $mode = 'furniture';
}

$furnitureGateway = $container->get(FurnitureGateway::class);
$canEdit = $context->isEditable();
// The module folder has a space in it, so the segment must be encoded or the
// request fails before it reaches PHP.
$moduleURL = $session->get('absoluteURL').'/modules/'.rawurlencode('Seating Plan');

// Back goes wherever the room was opened from: My Lessons for a timetabled
// period (back to the same day, not just today), Room Layouts for a bare
// layout. Format::date() converts to the school's own display format,
// matching what My Lessons' own date picker expects on the way back in.
$backURL = $context->hasPeriod()
    ? (string) Url::fromModuleRoute('Seating Plan', 'seatingPlans')
        ->withQueryParams(['date' => Format::date($context->getDate())])
    : (string) Url::fromModuleRoute('Seating Plan', 'layouts');

$payload = [
    'mode'         => $mode,
    'layoutID'     => $context->getLayoutID(),
    // Always sent, not only for the student modes: it is what lets a save
    // start a layout for a room that has none yet.
    'gibbonSpaceID' => $context->getSpaceID(),
    'gridCols'     => $context->getGridCols(),
    'gridRows'     => $context->getGridRows(),
    'editVersion'  => (int) ($context->getLayout()['editVersion'] ?? 1),
    'subdivisions' => FurnitureCatalogue::SUBDIVISIONS,
    'canEdit'      => $canEdit,
    'items'        => $context->getLayoutID() !== ''
        ? $furnitureGateway->selectFurnitureByLayout($context->getLayoutID())
        : [],
    'catalogue'    => json_decode(FurnitureCatalogue::toJson(), true),
    'saveURL'      => $moduleURL.'/'.(
        [
            'seating'  => 'seating_saveAjax.php',
            'register' => 'register_saveAjax.php',
        ][$mode] ?? 'layout_saveAjax.php'
    ),
    // Badge slots are a personal setting, saved the instant the config panel
    // changes - independent of the room's own Save button and its own
    // save URL above.
    'badgeSaveURL' => $moduleURL.'/badge_saveAjax.php',
    'csrfToken'    => $container->get(TokenHandler::class)->getCSRF(),
    'backURL'      => $backURL,
    'strings'      => [
        'saved'        => __('Saved'),
        'saving'       => __('Saving'),
        'unsaved'      => __('Unsaved changes'),
        'saveFailed'   => __('Could not save'),
        'saveConflict' => __('This layout changed in another window. Your local changes were kept.'),
        'confirmClear' => __('Remove every item from this layout?'),
        'readOnly'     => __('You are viewing a colleague\'s layout.'),
        'tooSmall'     => __('Move your furniture in first: something is in the way.'),
        'nudge'        => __('Hold Shift while dragging to snap to whole squares.'),
        'chairsTurned' => __('{count} pieces turned to face the right way.'),
        'chairsAlreadyFacing' => __('Everything already faces the right way.'),
        'mirrorNothing' => __('Select a door first, then mirror it.'),
        'mirrorNotAllowed' => __('Only a door can be mirrored.'),
        'outOfBounds'  => __('The highlighted {piece} is outside the room. '
            . 'Move it in, or make the room bigger.'),
        'pulledInside' => __('{count} pieces no longer fitted and have been '
            . 'moved inside. Check them, then save.'),
        'seatAmbiguous' => __('That was between two chairs. Put {name} down '
            . 'on one of them.'),
        'unseated'     => __('{count} still need a seat.'),
        'allSeated'    => __('Everyone has a seat.'),
        // Shown for as long as the room has no chairs in it: without chairs
        // there is nothing to be in or out of, so every position counts and
        // is saved as it stands.
        'freeArrange'  => __('No chairs in this room yet - put the students '
            . 'where they sit, then Save. Draw the furniture whenever you '
            . 'like.'),
        'notMarked'    => __('{count} not yet marked.'),
        'allMarked'    => __('Everyone has been marked.'),
        'unsavedMarks' => __('{count} unsaved marks - click Save Attendance.'),
        'ambiguousClass' => __('This student is enrolled in more than one '
            . 'class in this room, so attendance cannot be recorded for '
            . 'them here.'),
        'noCodesAllowed' => __('No attendance code is available for your '
            . 'role, so the register can be viewed here but not changed.'),
        'badgeSlotEmpty' => __('Empty'),
        'pickerTapToChoose' => __('Tap the left, middle or right of the '
            . 'room to choose from that group.'),
        'pickerTapAgain' => __('Tap again to start over.'),
        // Standing labels, not one-off messages: which button is armed
        // decides what every click in the room does, and a teacher looking
        // at the room rather than the toolbar needs it said in words.
        'rewardArmed'  => __('REWARD - click a student to add one, '
            . 'right-click to take one off.'),
        'sanctionArmed' => __('SANCTION - click a student to add one, '
            . 'right-click to take one off.'),
        'armFirst'     => __('Nothing armed - choose Reward or Sanction.'),
    ],
];

if ($mode === 'seating') {
    $seatingPayload = $container->get(SeatingMode::class)->buildPayload($context);
    $payload['roster'] = $seatingPayload['roster'];
    $payload['seats'] = $seatingPayload['seats'];
    $payload['badges'] = $seatingPayload['badges'];
    $payload['badgeSlots'] = $seatingPayload['badgeSlots'];
    $payload['badgeCatalogue'] = $seatingPayload['badgeCatalogue'];
    $payload['classList'] = $context->getClassList();
} elseif ($mode === 'register') {
    $registerPayload = $container->get(RegisterMode::class)
        ->buildPayload(
            $context,
            // Session's own value is an array of role rows, not a CSV -
            // matching core's own AttendanceView, which reads it the same
            // way.
            array_column($session->get('gibbonRoleIDAll'), 0)
        );
    $payload['roster'] = $registerPayload['roster'];
    $payload['seats'] = $registerPayload['seats'];
    $payload['marks'] = $registerPayload['marks'];
    $payload['codes'] = $registerPayload['codes'];
    $payload['classList'] = $context->getClassList();
    $payload['date'] = $context->getDate();
    $slot = $context->getSlot();
    $payload['anchorTTDayRowClassID'] = $slot['gibbonTTDayRowClassID'] ?? '';
} elseif ($mode === 'picker') {
    $pickerPayload = $container->get(PickerMode::class)->buildPayload($context);
    $payload['roster'] = $pickerPayload['roster'];
    $payload['seats'] = $pickerPayload['seats'];
    $payload['catScores'] = $pickerPayload['catScores'];
    // Nothing is ever written in this mode: room.js's whole pointer/save
    // pipeline is gated on canEdit, and picker mode wires its own click
    // handling entirely independently (see js/mode.picker.js).
    $payload['canEdit'] = false;
} elseif ($mode === 'rewards') {
    $rewardsPayload = $container->get(RewardsMode::class)->buildPayload($context);
    $payload['roster'] = $rewardsPayload['roster'];
    $payload['seats'] = $rewardsPayload['seats'];
    $payload['rewardCounts'] = $rewardsPayload['rewardCounts'];
    $payload['classList'] = $context->getClassList();
    $payload['date'] = $context->getDate();
    // Lets the save endpoint resolve the lesson from the timetable itself,
    // so a point is filed against a real period rather than only a date -
    // the same key Register mode already sends for the same reason.
    $slot = $context->getSlot();
    $payload['anchorTTDayRowClassID'] = $slot['gibbonTTDayRowClassID'] ?? '';
    // Each click writes immediately (reward_saveAjax.php) - there is no
    // batch save for this mode.
    $payload['rewardSaveURL'] = $moduleURL.'/reward_saveAjax.php';
} elseif ($mode === 'roomexit') {
    $roomExitPayload = $container->get(RoomExitMode::class)->buildPayload($context);
    $payload['roster'] = $roomExitPayload['roster'];
    $payload['seats'] = $roomExitPayload['seats'];
    $payload['openExits'] = $roomExitPayload['openExits'];
    $payload['classList'] = $context->getClassList();
    $payload['date'] = $context->getDate();
    // Lets the save endpoint resolve the lesson from the timetable itself,
    // so an exit is filed against a real class and period, not just a room
    // and a clock time.
    $slot = $context->getSlot();
    $payload['anchorTTDayRowClassID'] = $slot['gibbonTTDayRowClassID'] ?? '';
    // Each click toggles immediately (roomExit_saveAjax.php) - there is no
    // batch save for this mode either.
    $payload['roomExitSaveURL'] = $moduleURL.'/roomExit_saveAjax.php';
}

// Whose layout this is, when it is not the viewer's own. Shown before they
// start rearranging, so the copy that a save produces is never a surprise.
$borrowedFrom = '';
$pendingUpdate = [];
$layoutRow = $context->getLayout();

if (
    $context->getLayoutID() !== ''
    && !empty($layoutRow['gibbonPersonIDOwner'])
) {
    if ($layoutRow['gibbonPersonIDOwner'] != $gibbonPersonID) {
        $owner = $container->get(UserGateway::class)
            ->getByID($layoutRow['gibbonPersonIDOwner']);

        $borrowedFrom = !empty($owner)
            ? Format::name(
                $owner['title'],
                $owner['preferredName'],
                $owner['surname'],
                'Staff'
            )
            : __('a colleague');
    } else {
        // Their own layout, so it is theirs to be offered an update on.
        $pendingUpdate = $container->get(RoomLayoutGateway::class)
            ->getPendingUpdate($context->getLayoutID());
    }
}

$title = htmlspecialchars($context->getTitle(), ENT_QUOTES, 'UTF-8');
$available = array_filter(
    $modes,
    function ($definition) {
        return $definition['available'];
    }
);
?>
<script type="text/javascript">
// Chrome's "Translate this page?" prompt is triggered by page language
// detection, which the notranslate class/translate="no" attribute on
// #spDesigner below only partly suppresses - Chrome's own documented,
// reliable opt-out is a <meta name="google" content="notranslate"> tag in
// <head>. fullscreen.php's own <head> belongs to core, outside this
// module's boundary, so it is added here at runtime instead - as early as
// possible in the page, before the room full of student names below is
// parsed.
(function () {
    var meta = document.createElement('meta');
    meta.name = 'google';
    meta.content = 'notranslate';
    document.head.appendChild(meta);
    document.documentElement.classList.add('notranslate');
    document.documentElement.setAttribute('translate', 'no');
}());
</script>
<div class="sp-designer notranslate" id="spDesigner" translate="no" data-payload="<?php
    echo htmlspecialchars(json_encode($payload), ENT_QUOTES, 'UTF-8');
?>">

    <header class="sp-bar">
        <a class="sp-back" href="<?php echo $payload['backURL']; ?>" title="<?php
            echo __('Back to Room Layouts');
        ?>">&larr;</a>

        <h1 class="sp-title"><?php echo $title; ?></h1>

        <?php if (count($available) > 1) { ?>
            <nav class="sp-modes" aria-label="<?php echo __('Mode'); ?>">
                <?php foreach ($available as $id => $definition) {
                    $url = Url::fromHandlerRoute('fullscreen.php')
                        ->withQueryParams(
                            array_replace(
                                $_GET,
                                ['q' => '/modules/Seating Plan/room.php', 'mode' => $id]
                            )
                        );
                    ?>
                    <a class="sp-mode<?php echo $id === $mode ? ' is-current' : ''; ?>"
                        href="<?php echo $url; ?>"><?php
                        echo icon('solid', $definition['icon'], 'sp-mode-icon');
                        echo $definition['label'];
                    ?></a>
                <?php } ?>
            </nav>
        <?php } ?>

        <?php if ($mode === 'furniture') { ?>
            <?php if ($canEdit) { ?>
                <span class="sp-size" title="<?php
                    echo __('Room size in grid cells. One cell is about half a metre.');
                ?>">
                    <input type="number" id="spCols" min="10" max="40"
                        value="<?php echo $context->getGridCols(); ?>"
                        aria-label="<?php echo __('Columns'); ?>">
                    <span class="sp-size-x">&times;</span>
                    <input type="number" id="spRows" min="10" max="40"
                        value="<?php echo $context->getGridRows(); ?>"
                        aria-label="<?php echo __('Rows'); ?>">
                </span>
            <?php } else { ?>
                <span class="sp-dims"><?php
                    echo $context->getGridCols().' &times; '.$context->getGridRows();
                ?></span>
            <?php } ?>
        <?php } ?>

        <span class="sp-status" id="spStatus"></span>

        <?php if ($canEdit && $mode === 'furniture') { ?>
            <button type="button" class="sp-btn" id="spFaceChairs" title="<?php
                echo __('Turn every chair to face its desk, and every computer '
                    . 'to face its chair or away from the wall behind it');
            ?>"><?php echo __('Auto-Face'); ?></button>
            <button type="button" class="sp-btn" id="spRotate" title="<?php
                echo __('Rotate the selected item. Or just click it.');
            ?>"><?php echo __('Rotate'); ?></button>
            <button type="button" class="sp-btn" id="spMirror" title="<?php
                echo __('Mirror the selected door, moving its hinge to the '
                    . 'other side. Right-clicking a door does the same.');
            ?>"><?php echo __('Mirror'); ?></button>
            <button type="button" class="sp-btn" id="spDelete" title="<?php
                echo __('Delete the selected item');
            ?>"><?php echo __('Delete'); ?></button>
            <button type="button" class="sp-btn" id="spClear"><?php
                echo __('Clear All');
            ?></button>
        <?php } ?>
        <?php if ($canEdit && $mode === 'seating') { ?>
            <button type="button" class="sp-btn" id="spBadgesToggle" title="<?php
                echo __('Choose which badges show in each corner of a '
                    . 'student tile.');
            ?>"><?php
                echo icon('solid', 'star', 'sp-mode-icon');
                echo __('Badges');
            ?></button>
        <?php } ?>
        <?php if ($canEdit && $mode === 'rewards') { ?>
            <button type="button" class="sp-btn sp-arm-btn" id="spArmReward" title="<?php
                echo __('Arm, then click a student to record a reward for them.');
            ?>"><?php
                echo icon('solid', 'trophy', 'sp-mode-icon');
                echo __('Reward');
            ?></button>
            <button type="button" class="sp-btn sp-arm-btn" id="spArmSanction" title="<?php
                echo __('Arm, then click a student to record a sanction for them.');
            ?>"><?php
                echo icon('solid', 'warning', 'sp-mode-icon');
                echo __('Sanction');
            ?></button>
        <?php } ?>
        <?php if ($canEdit && !in_array($mode, ['picker', 'rewards', 'roomexit'], true)) { ?>
            <button type="button" class="sp-btn sp-btn-primary" id="spSave"><?php
                echo $mode === 'register' ? __('Save Attendance') : __('Save');
            ?></button>
        <?php } ?>
    </header>

    <?php if ($borrowedFrom !== '' && $mode === 'furniture') { ?>
        <div class="sp-notice" id="spBorrowed"><?php
            echo __(
                'This is {name}\'s layout. Rearrange it as you like - saving '
                . 'makes your own copy and leaves theirs untouched.',
                ['name' => htmlspecialchars($borrowedFrom, ENT_QUOTES, 'UTF-8')]
            );
        ?></div>
    <?php } ?>

    <?php if (!empty($pendingUpdate)) { ?>
        <div class="sp-notice sp-notice-action">
            <span><?php
                echo __(
                    '{name} has updated the layout yours was copied from.',
                    [
                        'name' => htmlspecialchars(
                            Format::name(
                                $pendingUpdate['title'] ?? '',
                                $pendingUpdate['preferredName'] ?? '',
                                $pendingUpdate['surname'] ?? '',
                                'Staff'
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ),
                    ]
                );
            ?></span>
            <a class="sp-btn" href="<?php
                echo htmlspecialchars(
                    (string) Url::fromModuleRoute('Seating Plan', 'layout_update')
                        ->withQueryParam(
                            'seatingPlanRoomLayoutID',
                            $context->getLayoutID()
                        ),
                    ENT_QUOTES,
                    'UTF-8'
                );
            ?>"><?php echo __('See What Changed'); ?></a>
        </div>
    <?php } ?>

    <div class="sp-body">
        <?php if ($canEdit && $mode === 'seating') { ?>
            <aside class="sp-badge-panel" id="spBadgePanel" hidden>
                <h2><?php echo __('Corner Badges'); ?></h2>
                <p class="sp-hint"><?php
                    echo __('Drag a badge into one of the four corners. Drag '
                        . 'it back out, or onto another badge, to remove or '
                        . 'replace it.');
                ?></p>
                <div class="sp-badge-slots" id="spBadgeSlots">
                    <div class="sp-badge-slot" data-slot="0"></div>
                    <div class="sp-badge-slot" data-slot="1"></div>
                    <div class="sp-badge-slot" data-slot="2"></div>
                    <div class="sp-badge-slot" data-slot="3"></div>
                </div>
                <h2><?php echo __('Available'); ?></h2>
                <div class="sp-badge-palette" id="spBadgePalette"></div>
            </aside>
        <?php } ?>
        <?php if ($canEdit && $mode === 'furniture') { ?>
            <aside class="sp-palette" id="spPalette">
                <h2><?php echo __('Furniture'); ?></h2>
                <?php foreach (FurnitureCatalogue::all() as $type => $spec) {
                    // Fill the 46x26 swatch without distorting the piece. The
                    // cap is the swatch height, so a square item like a chair
                    // is drawn big enough for its artwork to read.
                    $iconCell = min(42 / $spec['wide'], 22 / $spec['high'], 22);
                    $step = FurnitureCatalogue::SUBDIVISIONS;
                    ?>
                    <button type="button" class="sp-tool" data-type="<?php
                        echo $type;
                    ?>" draggable="false">
                        <span class="sp-tool-art">
                            <span class="sp-art" data-type="<?php echo $type; ?>"
                                style="--cell:<?php echo round($iconCell, 2); ?>px;
                                    --uw:<?php echo $spec['wide'] * $step; ?>;
                                    --uh:<?php echo $spec['high'] * $step; ?>;
                                    --rot:0"></span>
                        </span>
                        <span class="sp-tool-label"><?php
                            echo __($spec['label']);
                        ?></span>
                    </button>
                <?php } ?>
                <p class="sp-hint"><?php
                    echo __(
                        'Click a piece to add it. Drag to move: furniture snaps '
                        . 'to a tenth of a square, so hold Shift to snap to whole '
                        . 'squares instead. Click an item to turn it by hand. '
                        . 'Drag the corner handle to stretch benching, boards and '
                        . 'cupboards. A chair you drag turns to face whatever it '
                        . 'lands against, and a computer turns to face its chair '
                        . 'or away from the wall behind it. Ctrl-click to pick '
                        . 'out several pieces and move them together, or press '
                        . 'Ctrl+A for all of them. Select a door and press '
                        . 'Mirror, or right-click it, to move its hinge to the '
                        . 'other side.'
                    );
                ?></p>
            </aside>
        <?php } ?>

        <main class="sp-stage" id="spStage">
            <div class="sp-room" id="spRoom"></div>
        </main>
    </div>
</div>
<?php
// fullscreen.twig.html renders the styles block but not the scripts block, so
// page->scripts would never reach the browser here. Emit the tags directly:
// the shell first, then each mode, then start.
$base = $session->get('absoluteURL').'/modules/'.rawurlencode('Seating Plan').'/js/';
$version = rawurlencode($session->get('version', ''));

// Confetti for the name picker, and only for the name picker: no other mode
// throws any, and no other mode should pay for the download.
//
// This is the module's one external asset. It is pinned to an exact version
// and checked against its own hash, so the file that runs is the file that
// was reviewed - a changed file simply does not execute. A school with no
// route out to the internet, or one that blocks the CDN, gets no confetti
// and nothing else changes: js/mode.picker.js checks the library is there
// before using it.
if ($mode === 'picker') {
    ?>
    <script type="text/javascript"
        src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"
        integrity="sha384-sPwMflxqfAN+Q5mvlkLmHiX3PORGbZSXHiSGPTXT9VHCD/AB+b+r+vJWsqprv+7k"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <?php
}

foreach ([
    'room.js', 'furnitureArt.js', 'furnitureBackdrop.js', 'studentTile.js',
    'seatPlacement.js', 'badgePanel.js',
    'mode.furniture.js', 'mode.seating.js', 'mode.register.js', 'mode.picker.js',
    'mode.rewards.js', 'mode.roomexit.js',
] as $script) {
    ?>
    <script type="text/javascript" src="<?php
        echo htmlspecialchars($base.$script.'?v='.$version, ENT_QUOTES, 'UTF-8');
    ?>"></script>
    <?php
}
?>
<script type="text/javascript">window.SeatingPlanRoom.start();</script>
