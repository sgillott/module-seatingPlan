/**
 * Seating Plan: seating mode.
 *
 * The room's furniture is drawn once as a read-only backdrop; the shell's
 * generic drag-and-drop moves student tiles over it. A tile dropped on an
 * empty chair sits there; on an occupied chair it swaps places with whoever
 * was in it; dropped between two chairs it reverts, since neither is a clear
 * choice; dropped anywhere else on open floor it just stays there for the
 * session - only a real chair assignment is ever saved, so this doubles as
 * the way to unseat someone.
 *
 * None of that applies to a room with no chairs in it, which is every room
 * until somebody draws the furniture. With nothing to be in or out of, the
 * floor is the plan: a tile stays wherever it is put and every position is
 * saved. That is what makes the seating plan usable on the first day, before
 * anyone has drawn the room.
 */
(function () {
    'use strict';

    var room = null;
    var config = null;
    var items = [];
    var chairs = [];
    var STEP = 10;

    // Captured in onDragStart, since the shell mutates posX/posY in place as
    // a drag moves: by the time onDrop fires, the item's own position is
    // already the new one.
    var origins = {};

    var STUDENT_SPEC = {
        layer: 'seat',
        resizable: false,
        mirrorable: false,
        // A click here means "select", never "turn" - R after clicking a
        // tile must not silently dirty a saved seating plan over nothing.
        rotatable: false,
        label: 'Student'
    };

    function spec() {
        return STUDENT_SPEC;
    }

    function chairKey(x, y) {
        return x + ',' + y;
    }

    function isChair(x, y) {
        return chairs.indexOf(chairKey(x, y)) >= 0;
    }

    /**
     * Whether this room seats by chair or by open floor.
     */
    function freePlacement() {
        return chairs.length === 0;
    }

    /**
     * The chair a dropped tile is aiming at: the one it covers most of.
     *
     * Chairs in a real room stand right next to each other, so a tile a
     * tenth or two off centre laps over its neighbour as well. Counting any
     * overlap at all as a candidate made that read as "between two chairs"
     * and sprang the student back to the floor, which meant only a drop
     * landing exactly on the chair ever seated anybody.
     *
     * Most of the tile decides it instead. Only a dead heat - the tile
     * covering two chairs by precisely the same amount, which is a drop
     * genuinely in the gap between them - has no answer.
     *
     * @return object|null null on open floor, otherwise the chair's "x,y"
     *                     key and whether a second chair tied with it.
     */
    function targetChair(item) {
        var rect = room.rectOf(item);
        var best = null;
        var tied = false;

        chairs.forEach(function (key) {
            var parts = key.split(',');
            var chairRect = {
                x: parseInt(parts[0], 10),
                y: parseInt(parts[1], 10),
                w: STEP,
                h: STEP
            };
            var area = room.overlapArea(rect, chairRect);

            if (area <= 0) {
                return;
            }

            if (best === null || area > best.area) {
                best = { key: key, area: area };
                tied = false;
            } else if (area === best.area) {
                tied = true;
            }
        });

        return best === null ? null : { key: best.key, tied: tied };
    }

    /**
     * The other student currently sitting exactly on a chair, if any.
     */
    function occupantAt(x, y, exceptIndex) {
        for (var i = 0; i < items.length; i++) {
            if (i !== exceptIndex && items[i].posX === x && items[i].posY === y) {
                return i;
            }
        }
        return -1;
    }

    /* -------------------------------------------------------- the backdrop */

    /**
     * Draws the layout's furniture once, underneath the students, and never
     * touched again: it is not part of what render() manages.
     */
    function buildBackdrop() {
        window.SeatingPlanFurnitureBackdrop.build(
            config,
            STEP,
            document.getElementById('spRoom'),
            function (item, definition) {
                // The catalogue decides what a student can sit on, here as
                // well as in seating_saveAjax.php and the re-snap after a
                // layout update, so the three cannot drift apart on the
                // answer.
                if (definition.seat) {
                    chairs.push(chairKey(item.posX, item.posY));
                }
            }
        );
    }

    /* --------------------------------------------------------------- load */

    function load(shell, payload) {
        room = shell;
        config = payload;
        STEP = shell.STEP();
        chairs = [];

        buildBackdrop();

        var placement = window.SeatingPlanSeatPlacement.resolve({
            roster: config.roster,
            seats: config.seats,
            chairs: chairs,
            gridCols: config.gridCols,
            gridRows: config.gridRows,
            step: STEP
        });

        items = [];

        (config.roster || []).forEach(function (student) {
            var spot = placement.positions[student.gibbonPersonID];

            items.push({
                type: 'student',
                gibbonPersonID: student.gibbonPersonID,
                name: student.name,
                photo: student.photo,
                posX: spot.posX,
                posY: spot.posY,
                rotation: 0,
                flipped: false,
                sizeX: STEP,
                sizeY: STEP
            });
        });

        if (freePlacement()) {
            // A standing note about how this room works, not a warning:
            // nobody is missing a seat in a room that has none.
            room.setStatus(room.text('freeArrange'));
        } else if (placement.placed > 0) {
            room.setStatus(
                room.text('unseated').replace('{count}', placement.placed),
                'warn'
            );
        } else if (items.length > 0) {
            room.setStatus(room.text('allSeated'), 'ok');
        }

        loadBadgePanel();
    }

    /* ---------------------------------------------------------- badges */

    /**
     * Wires the corner badge config panel, when its markup exists - it is
     * only rendered server-side for an editable room, matching every other
     * seating-mode-only control.
     *
     * Badge slots are the viewing teacher's own setting, not part of the
     * room's own seats/furniture - they save themselves the instant the
     * panel changes, independently of the Save button.
     */
    function loadBadgePanel() {
        var panel = document.getElementById('spBadgePanel');
        var slotsEl = document.getElementById('spBadgeSlots');
        var paletteEl = document.getElementById('spBadgePalette');
        var toggle = document.getElementById('spBadgesToggle');

        if (!panel || !slotsEl || !paletteEl) {
            return;
        }

        window.SeatingPlanBadgePanel.init(
            { panel: panel, slots: slotsEl, palette: paletteEl, toggle: toggle },
            config.badgeCatalogue || [],
            config.badgeSlots || ['', '', '', ''],
            room.text('badgeSlotEmpty'),
            function (slots) {
                room.render();
                saveBadgeSlots(slots);
            }
        );
    }

    /**
     * Persists the viewer's own badge slots. Fire-and-forget: a failure
     * here just means the change did not stick for next time, not that
     * anything currently on screen is wrong, so it is not routed through
     * the room's own save-state indicator.
     */
    function saveBadgeSlots(slots) {
        var body = new URLSearchParams();
        body.set('csrftoken', config.csrfToken);
        body.set('badgeSlots', JSON.stringify(slots));

        fetch(config.badgeSaveURL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
    }

    /**
     * The four corner slots currently in effect: from the live panel when
     * one exists (an editable room), otherwise the viewer's own saved
     * setting - a colleague viewing a shared plan read-only still sees
     * their own badges, not the owner's.
     */
    function currentBadgeSlots() {
        if (window.SeatingPlanBadgePanel && document.getElementById('spBadgePanel')) {
            return window.SeatingPlanBadgePanel.getSlots();
        }
        return config.badgeSlots || ['', '', '', ''];
    }

    var BADGE_POSITIONS = ['tl', 'tr', 'bl', 'br'];

    /**
     * Appends up to four corner badges to a student tile, for whichever
     * source keys are in the current slot config and actually have a value
     * for this student - a slot with nothing to show for this particular
     * student is simply skipped, not shown empty.
     */
    function appendBadges(tile, item) {
        var values = (config.badges || {})[item.gibbonPersonID] || {};
        var slots = currentBadgeSlots();

        slots.forEach(function (key, index) {
            var badge = key ? values[key] : null;
            if (!badge) {
                return;
            }

            var el = document.createElement('div');
            el.className = 'sp-badge sp-badge-' + BADGE_POSITIONS[index]
                + ' sp-badge-' + badge.tone;
            if (badge.colour) {
                el.style.background = badge.colour;
            }
            el.textContent = badge.value;
            el.title = badge.label + ': ' + badge.value;
            tile.appendChild(el);
        });
    }

    /* ---------------------------------------------------------- dragging */

    function onDragStart(indices) {
        origins = {};
        indices.forEach(function (index) {
            origins[index] = { posX: items[index].posX, posY: items[index].posY };
        });
    }

    function onDrop(indices) {
        if (freePlacement()) {
            // Nothing to snap to and nothing to swap with: wherever the tile
            // was let go is the answer, and the shell has already marked the
            // room dirty so it will be saved.
            return;
        }

        var message = '';

        indices.forEach(function (index) {
            var item = items[index];
            var origin = origins[index] || { posX: item.posX, posY: item.posY };
            var target = targetChair(item);

            if (target === null) {
                // Open floor: left exactly where it was dropped, for this
                // session only.
                return;
            }

            if (target.tied) {
                item.posX = origin.posX;
                item.posY = origin.posY;
                message = room.text('seatAmbiguous').replace('{name}', item.name);
                room.refreshItem(index);
                return;
            }

            var parts = target.key.split(',');
            var chairX = parseInt(parts[0], 10);
            var chairY = parseInt(parts[1], 10);
            var occupant = occupantAt(chairX, chairY, index);

            if (occupant >= 0) {
                items[occupant].posX = origin.posX;
                items[occupant].posY = origin.posY;
                room.refreshItem(occupant);
            }

            item.posX = chairX;
            item.posY = chairY;
            room.refreshItem(index);
        });

        // Handed back rather than set here: the shell marks the room dirty
        // straight after this returns, and that would overwrite anything
        // written to the status bar now.
        return message;
    }

    function onClick(index) {
        // A student is not turned or resized by clicking: just select them.
        room.select(index);
    }

    function onDoubleClick(index) {
        var item = items[index];

        if (!item || item.gibbonPersonID === undefined) {
            return;
        }

        window.location.href = 'index.php?q=%2Fmodules%2FStudents%2Fstudent_view_details.php'
            + '&gibbonPersonID=' + encodeURIComponent(item.gibbonPersonID);
    }

    /* ----------------------------------------------------------- the mode */

    window.SeatingPlanRoom.register({
        id: 'seating',

        // A student is not deleted, only seated or not: the Delete/Backspace
        // shortcut is a no-op here rather than dropping them from view (and,
        // if they were seated, dropping their seat on the next save).
        deletable: false,

        load: load,

        getItems: function () {
            return items;
        },

        specOf: spec,

        buildArt: function (item) {
            var tile = window.SeatingPlanStudentTile.build(item);
            appendBadges(tile, item);
            return tile;
        },

        onDragStart: onDragStart,
        onDrop: onDrop,
        onClick: onClick,
        onDoubleClick: onDoubleClick,

        getSavePayload: function () {
            // In a room with chairs, only a tile exactly on one is a real,
            // savable seat: everything on open floor is a session-only
            // convenience and is simply left out. In a room with no chairs
            // the floor is all there is, so every position is saved.
            var seats = items
                .filter(function (item) {
                    return freePlacement() || isChair(item.posX, item.posY);
                })
                .map(function (item) {
                    return {
                        gibbonPersonID: item.gibbonPersonID,
                        posX: item.posX,
                        posY: item.posY
                    };
                });

            return {
                seatingPlanRoomLayoutID: config.layoutID,
                gibbonSpaceID: config.gibbonSpaceID,
                classList: config.classList,
                seats: JSON.stringify(seats)
            };
        }
    });
}());
