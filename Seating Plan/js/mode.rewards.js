/**
 * Seating Plan: Rewards mode.
 *
 * Arm one of two buttons - Reward or Sanction - then click a seated student
 * to write one behaviour record for them, straight away: there is no batch
 * Save here, each click saves itself, the same "personal action, writes
 * immediately" shape this module's own badge-slot saving already
 * established. A brief flash on the tile confirms the write; nothing about
 * the tile is otherwise changed - this is a log, not a persistent mark.
 *
 * Unlike Picker mode, this reuses room.js's own click pipeline rather than
 * bypassing it: a click here always means "this one specific student", the
 * same shape Register mode's tiles already use, just writing through its
 * own endpoint instead of a batch.
 */
(function () {
    'use strict';

    var room = null;
    var config = null;
    var items = [];
    var armedType = ''; // '' | 'Positive' | 'Negative'

    var STUDENT_SPEC = {
        layer: 'seat',
        resizable: false,
        mirrorable: false,
        rotatable: false,
        label: 'Student'
    };

    function spec() {
        return STUDENT_SPEC;
    }

    /**
     * Reward/sanction corner badges, reusing the exact same .sp-badge/
     * .sp-badge-bl/.sp-badge-br/.sp-badge-good/.sp-badge-bad classes the
     * badge feature already established in Seating mode - same position
     * (sitting above the name band via --sp-name-h, never over it), same
     * shape, just a plain count instead of a school-configured source. A
     * zero count renders nothing at all, on either side independently.
     */
    function renderCountBadges(tile, item) {
        var existing = tile.querySelectorAll('.sp-badge-bl, .sp-badge-br');
        for (var i = 0; i < existing.length; i++) {
            existing[i].remove();
        }

        function addBadge(position, tone, count) {
            if (!count) {
                return;
            }
            var badge = document.createElement('div');
            badge.className = 'sp-badge ' + position + ' ' + tone;
            badge.textContent = String(count);
            tile.appendChild(badge);
        }

        addBadge('sp-badge-bl', 'sp-badge-good', item.rewardCount);
        addBadge('sp-badge-br', 'sp-badge-bad', item.sanctionCount);
    }

    /**
     * The room's furniture, drawn once as a read-only backdrop - the exact
     * pattern mode.seating.js and mode.picker.js both already use. Wrapped
     * in its own .sp-backdrop container, never itself .sp-item-classed, so
     * room.js's own render() (which only clears .sp-item elements it finds
     * as *direct* children of #spRoom) never touches it when it rebuilds
     * the student tiles this mode's own getItems() supplies.
     */
    function buildBackdrop(STEP) {
        window.SeatingPlanFurnitureBackdrop.build(
            config,
            STEP,
            document.getElementById('spRoom')
        );
    }

    /* --------------------------------------------------------------- load */

    function load(shell, payload) {
        room = shell;
        config = payload;

        var STEP = shell.STEP();

        buildBackdrop(STEP);
        showArmedStatus();

        var rewardCounts = config.rewardCounts || {};

        items = (config.roster || [])
            .map(function (student) {
                var seat = (config.seats || []).filter(function (s) {
                    return s.gibbonPersonID === student.gibbonPersonID;
                })[0];

                if (!seat) {
                    return null;
                }

                var counts = rewardCounts[student.gibbonPersonID] || {};

                return {
                    type: 'student',
                    gibbonPersonID: student.gibbonPersonID,
                    name: student.name,
                    photo: student.photo,
                    posX: parseInt(seat.posX, 10),
                    posY: parseInt(seat.posY, 10),
                    rotation: 0,
                    flipped: false,
                    sizeX: STEP,
                    sizeY: STEP,
                    rewardCount: counts.Positive || 0,
                    sanctionCount: counts.Negative || 0
                };
            })
            .filter(function (item) { return item !== null; });

        wireArmButtons();
    }

    /**
     * Puts the armed state back in the status area.
     *
     * Which button is armed decides what every click in the room does, so
     * it is worth saying in words and keeping said. The toolbar shows it in
     * colour, but a teacher looking at the room rather than the toolbar has
     * nothing to go on - and any transient message would otherwise sit
     * there afterwards implying something else.
     */
    function showArmedStatus() {
        if (!armedType) {
            room.setStatus(room.text('armFirst'), 'warn');

            return;
        }

        room.setStatus(
            armedType === 'Positive'
                ? room.text('rewardArmed')
                : room.text('sanctionArmed')
        );
    }

    function wireArmButtons() {
        var rewardBtn = document.getElementById('spArmReward');
        var sanctionBtn = document.getElementById('spArmSanction');

        function arm(type, button, other) {
            if (armedType === type) {
                armedType = '';
                button.classList.remove('is-armed');
                showArmedStatus();
                return;
            }

            armedType = type;
            button.classList.add('is-armed');
            if (other) {
                other.classList.remove('is-armed');
            }
            showArmedStatus();
        }

        if (rewardBtn) {
            rewardBtn.addEventListener('click', function () {
                arm('Positive', rewardBtn, sanctionBtn);
            });
        }
        if (sanctionBtn) {
            sanctionBtn.addEventListener('click', function () {
                arm('Negative', sanctionBtn, rewardBtn);
            });
        }
    }

    /* ------------------------------------------------------------- saving */

    function saveReward(item, flashClass, action) {
        // Captured now rather than read from the live armedType later - the
        // teacher can switch which button is armed while this request is
        // still in flight.
        var typeSent = armedType;

        var body = new URLSearchParams();
        body.set('csrftoken', config.csrfToken);
        body.set('classList', config.classList);
        body.set('gibbonSpaceID', config.gibbonSpaceID);
        body.set('date', config.date);
        body.set('anchorTTDayRowClassID', config.anchorTTDayRowClassID);
        body.set('gibbonPersonID', item.gibbonPersonID);
        body.set('type', typeSent);
        body.set('action', action || 'add');

        var index = items.indexOf(item);
        var box = document.querySelector('[data-index="' + index + '"]');

        if (box) {
            box.classList.add(flashClass);
            window.setTimeout(function () {
                box.classList.remove(flashClass);
            }, 500);
        }

        fetch(config.rewardSaveURL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            // A refused remove still carries the real totals, so the badges
            // are repainted either way and only the message differs.
            var counts = (result && result.counts) || {};

            if (result && result.counts) {
                item.rewardCount = counts.Positive || 0;
                item.sanctionCount = counts.Negative || 0;

                // buildArt() only ever runs once per tile, so the badges are
                // repainted directly here rather than waiting for a redraw
                // that will not happen.
                if (box) {
                    var tile = box.querySelector('.sp-student');
                    if (tile) {
                        renderCountBadges(tile, item);
                    }
                }
            }

            if (!result || !result.ok) {
                room.setStatus(
                    (result && result.message) || room.text('saveFailed'),
                    'error'
                );

                // Put the armed state back after a moment, so an error does
                // not sit there looking like the current state of things.
                window.setTimeout(showArmedStatus, 2500);

                return;
            }

            showArmedStatus();
        }).catch(function () {
            room.setStatus(room.text('saveFailed'), 'error');
            window.setTimeout(showArmedStatus, 2500);
        });
    }

    function onClick(index) {
        if (!armedType) {
            showArmedStatus();
            return;
        }

        saveReward(
            items[index],
            armedType === 'Positive' ? 'sp-reward-flash' : 'sp-sanction-flash',
            'add'
        );
    }

    /**
     * Right-click takes one back off, the mirror of what a left-click adds.
     *
     * A mis-tap is noticed straight away by whoever made it, so undoing one
     * belongs here rather than in an administrator's hands the next day.
     * The shell has already swallowed the browser menu by the time this
     * runs (see room.js's onContextMenu).
     */
    function onRightClick(index) {
        if (!armedType) {
            showArmedStatus();
            return;
        }

        saveReward(
            items[index],
            armedType === 'Positive' ? 'sp-reward-flash' : 'sp-sanction-flash',
            'remove'
        );
    }

    /* ----------------------------------------------------------- the mode */

    window.SeatingPlanRoom.register({
        id: 'rewards',

        // A click here means "give this student a reward/sanction", never
        // "move them" - the same movable:false shape Register mode's own
        // tiles use, so a tap is always a tap even with pointer jitter.
        movable: false,
        deletable: false,

        load: load,

        getItems: function () {
            return items;
        },

        specOf: spec,

        buildArt: function (item) {
            var tile = window.SeatingPlanStudentTile.build(item);
            renderCountBadges(tile, item);
            return tile;
        },

        onClick: onClick,
        onRightClick: onRightClick
    });
}());
