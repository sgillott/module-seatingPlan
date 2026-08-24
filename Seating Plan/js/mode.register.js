/**
 * Seating Plan: register mode.
 *
 * The exact same room the seating plan saved - the same backdrop, the same
 * seats, the same free-floor placement for anyone not yet seated - but
 * nothing here drags. A left click on a student cycles forward through the
 * active attendance codes for the taking teacher's own role, wrapping back
 * to the first one; a right click cycles backward through the same list,
 * wrapping to the last one - for undoing an overshoot without cycling all
 * the way round. There is no way to cycle back to "no mark" once a real
 * mark has been made, matching how a paper register is corrected by writing
 * a new mark, never erasing the old one.
 *
 * Cycling only ever touches local state - nothing is written until the
 * Save button (shared with furniture/seating mode's own batch save) is
 * pressed. A tile's own border shows red until this exact period has a
 * genuine saved record for it, green once it does. A student whose tile
 * merely *displays* a mark carried forward from an earlier period (via
 * core's own crossfill/wildcard rules) still starts red and is still
 * included in the very next Save: showing a value is not the same as this
 * period's own register actually being recorded, and the batch payload is
 * built from exactly that distinction (see savedMark below), not from
 * "did the teacher touch this tile this session".
 */
(function () {
    'use strict';

    var room = null;
    var config = null;
    var items = [];
    var chairs = [];
    var STEP = 10;

    var STUDENT_SPEC = {
        layer: 'seat',
        resizable: false,
        mirrorable: false,
        // A click here means "cycle the mark", never "turn me" - R after
        // clicking a tile must not silently dirty the room over nothing.
        rotatable: false,
        label: 'Student'
    };

    function spec() {
        return STUDENT_SPEC;
    }

    function chairKey(x, y) {
        return x + ',' + y;
    }

    /* -------------------------------------------------------- the backdrop */

    /**
     * Draws the layout's furniture once, underneath the students, and never
     * touched again: it is not part of what render() manages. Identical to
     * Seating mode's own backdrop, since both modes show the same room.
     */
    function buildBackdrop() {
        window.SeatingPlanFurnitureBackdrop.build(
            config,
            STEP,
            document.getElementById('spRoom'),
            function (item, definition) {
                // The catalogue is what decides a student can sit on this,
                // matching seating mode and the save endpoint rather than
                // naming one type here.
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

        // The register is taken whether or not the room has been drawn or
        // arranged: anyone the seating plan does not place is laid out in
        // rows, so a bare room still shows the whole class to mark.
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
            var mark = (config.marks || {})[student.gibbonPersonID] || null;

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
                sizeY: STEP,
                ambiguous: !!student.ambiguous,
                mark: mark ? mark.gibbonAttendanceCodeID : '',
                markShort: mark ? mark.nameShort : '',
                markName: mark ? mark.name : '',
                bucket: mark ? mark.bucket : 'pending',
                // The baseline a change is measured against is "does a real
                // row already exist for THIS exact period", not "what is
                // currently displayed". A mark only pulled in via the
                // wildcard/crossfill fallback (mark.exact === false) has
                // nothing recorded for today yet, so it starts with no
                // saved baseline - dirty, red, and included in the very
                // next Save, exactly like a mark the teacher just chose.
                savedMark: (mark && mark.exact) ? mark.gibbonAttendanceCodeID : '',
                saving: false,
                justChanged: false,
                // The mark being swiped away from, captured by onClick()
                // right before it overwrites mark/bucket/markShort/markName -
                // buildArt() renders this alongside the new one for one
                // right-to-left swipe, then clears it.
                previousBucket: null,
                previousMarkShort: '',
                previousMarkName: '',
                // Which way the swipe should play: 'forward' (right to
                // left, a left click) or 'backward' (left to right, a
                // right click). Meaningless until justChanged is true.
                swipeDirection: 'forward'
            });
        });

        refreshStatus();
    }

    /* ------------------------------------------------------------- dirty */

    /**
     * Non-ambiguous tiles that would be part of the next Save: a real code
     * is chosen, and it is not yet recorded for this exact period. This is
     * the single source of truth for both the Save payload and the live
     * status count, so the two can never drift apart.
     */
    function changedItems() {
        return items.filter(function (item) {
            return !item.ambiguous && item.mark !== '' && item.mark !== item.savedMark;
        });
    }

    /**
     * Reflects the room's current state in the status bar: no codes
     * available at all, some marks waiting to be saved (including ones
     * merely carried forward and never actually recorded for today),
     * students with no mark of any kind yet, or nothing left to do.
     */
    function refreshStatus() {
        if ((config.codes || []).length === 0) {
            room.setStatus(room.text('noCodesAllowed'), 'warn');
            return;
        }

        var unsaved = changedItems().length;

        if (unsaved > 0) {
            room.markDirty(room.text('unsavedMarks').replace('{count}', unsaved));
            return;
        }

        var pending = items.filter(function (item) {
            return !item.ambiguous && item.mark === '';
        }).length;

        if (pending > 0) {
            room.setStatus(room.text('notMarked').replace('{count}', pending), 'warn');
        } else if (items.length > 0) {
            room.setStatus(room.text('allMarked'), 'ok');
        }
    }

    /* -------------------------------------------------------------- cycle */

    /**
     * The code a click should move to: the next one after the tile's
     * current code in the active-and-allowed list, wrapping to the first:
     * codes[0] -> codes[1] -> ... -> codes[n-1] -> codes[0]. A tile with no
     * mark yet, or whose mark's code is no longer active or allowed for
     * this teacher's role, also lands on codes[0] - there is nothing to
     * "advance from" in either case.
     */
    function nextCode(currentCodeID) {
        var codes = config.codes || [];

        if (codes.length === 0) {
            return null;
        }

        for (var i = 0; i < codes.length; i++) {
            if (codes[i].gibbonAttendanceCodeID === currentCodeID) {
                return codes[(i + 1) % codes.length];
            }
        }

        return codes[0];
    }

    /**
     * The mirror image of nextCode(), for a right click: the code before
     * the tile's current one, wrapping to the last: codes[n-1] ->
     * codes[n-2] -> ... -> codes[0] -> codes[n-1]. A tile with no mark yet,
     * or whose mark's code is no longer active or allowed, lands on the
     * *last* code rather than the first - the natural starting point when
     * moving backward instead of forward.
     */
    function previousCode(currentCodeID) {
        var codes = config.codes || [];

        if (codes.length === 0) {
            return null;
        }

        for (var i = 0; i < codes.length; i++) {
            if (codes[i].gibbonAttendanceCodeID === currentCodeID) {
                return codes[(i - 1 + codes.length) % codes.length];
            }
        }

        return codes[codes.length - 1];
    }

    /**
     * One colour wash: the bucket-coloured background, the code's short
     * form large, its full name smaller beneath. Used twice for a tile
     * mid-swipe (the outgoing mark and the incoming one) and once for a
     * settled tile.
     */
    function buildWash(bucket, markShort, markName) {
        var wash = document.createElement('div');
        wash.className = 'sp-mark-wash';
        wash.dataset.bucket = bucket;

        var letter = document.createElement('span');
        letter.className = 'sp-mark-letter notranslate';
        letter.translate = false;
        letter.textContent = markShort;
        wash.appendChild(letter);

        var label = document.createElement('span');
        label.className = 'sp-mark-label notranslate';
        label.translate = false;
        label.textContent = markName;
        wash.appendChild(label);

        return wash;
    }

    /**
     * Applies whichever code the click resolved to, shared by the forward
     * (left click) and backward (right click) gestures - everything past
     * "which code did we land on" is identical between the two, including
     * the change-tracking, only the swipe's own direction differs.
     */
    function applyCode(item, chosen, direction) {
        // A pending tile has no wash to swipe away from - only a genuine
        // mark-to-mark change gets the two-sided transition.
        item.previousBucket = item.mark !== '' ? item.bucket : null;
        item.previousMarkShort = item.markShort;
        item.previousMarkName = item.markName;

        item.mark = chosen.gibbonAttendanceCodeID;
        item.bucket = chosen.bucket;
        item.markShort = chosen.nameShort;
        item.markName = chosen.name;
        item.justChanged = true;
        item.swipeDirection = direction;

        room.render();
        refreshStatus();
    }

    function onClick(index) {
        var item = items[index];

        if (item.ambiguous || (config.codes || []).length === 0) {
            return;
        }

        var chosen = nextCode(item.mark);
        if (!chosen) {
            return;
        }

        applyCode(item, chosen, 'forward');
    }

    function onRightClick(index) {
        var item = items[index];

        if (item.ambiguous || (config.codes || []).length === 0) {
            return;
        }

        var chosen = previousCode(item.mark);
        if (!chosen) {
            return;
        }

        applyCode(item, chosen, 'backward');
    }

    /* ----------------------------------------------------------- the mode */

    window.SeatingPlanRoom.register({
        id: 'register',

        // A mark is corrected by cycling to a new one, never erased: no
        // Delete here. Nothing drags in register mode either - a click is
        // always a click, never a chance to nudge someone off their chair.
        deletable: false,
        movable: false,

        // The Save button is inert (not just unclickable) until there is
        // something to save, unlike furniture/seating which always allow a
        // harmless no-op re-save.
        disableSaveWhenClean: true,

        load: load,

        getItems: function () {
            return items;
        },

        specOf: spec,

        buildArt: function (item) {
            var tile = window.SeatingPlanStudentTile.build(item);
            tile.dataset.mark = item.bucket;

            if (!item.ambiguous) {
                tile.dataset.saveState = (item.mark !== '' && item.mark === item.savedMark)
                    ? 'saved' : 'dirty';
            }

            // A wash only appears once a real mark exists - a pending tile
            // is a plain tile, same as before a code was ever chosen. A
            // tile that just changed gets a swipe: the old mark (if there
            // was one) slides off one side while the new one slides in
            // from the other, both on screen at once - not just the new
            // one wiping into an empty tile. A left click swipes right to
            // left (forward through the codes); a right click swipes left
            // to right (backward).
            if (item.mark) {
                var swiping = item.justChanged;
                var reverse = item.swipeDirection === 'backward';

                if (swiping && item.previousBucket) {
                    var washOut = buildWash(
                        item.previousBucket, item.previousMarkShort, item.previousMarkName
                    );
                    washOut.classList.add(
                        reverse ? 'sp-mark-wash-out-reverse' : 'sp-mark-wash-out'
                    );
                    tile.appendChild(washOut);
                }

                var wash = buildWash(item.bucket, item.markShort, item.markName);
                if (swiping) {
                    wash.classList.add(reverse ? 'sp-mark-wash-in-reverse' : 'sp-mark-wash-in');
                }
                tile.appendChild(wash);

                if (swiping) {
                    item.justChanged = false;
                    item.previousBucket = null;
                }
            }

            if (item.saving) {
                tile.classList.add('is-saving');
            }

            if (item.ambiguous) {
                tile.classList.add('sp-student-ambiguous');
                tile.title = room.text('ambiguousClass');
            }

            return tile;
        },

        onClick: onClick,
        onRightClick: onRightClick,

        getSavePayload: function () {
            var marks = changedItems().map(function (item) {
                return {
                    gibbonPersonID: item.gibbonPersonID,
                    gibbonAttendanceCodeID: item.mark
                };
            });

            return {
                classList: config.classList,
                gibbonSpaceID: config.gibbonSpaceID,
                date: config.date,
                anchorTTDayRowClassID: config.anchorTTDayRowClassID,
                marks: JSON.stringify(marks)
            };
        },

        onSaving: function () {
            changedItems().forEach(function (item) {
                item.saving = true;
            });
            room.render();
        },

        onSaved: function (result) {
            var marksByID = (result && result.marks) || {};

            items.forEach(function (item) {
                item.saving = false;

                if (Object.prototype.hasOwnProperty.call(marksByID, item.gibbonPersonID)) {
                    item.bucket = marksByID[item.gibbonPersonID];
                    item.savedMark = item.mark;
                }
            });

            room.render();
            refreshStatus();
        },

        onSaveFailed: function (result) {
            items.forEach(function (item) {
                item.saving = false;
            });

            if (result && result.gibbonPersonID) {
                for (var i = 0; i < items.length; i++) {
                    if (items[i].gibbonPersonID === result.gibbonPersonID) {
                        room.select(i);
                        break;
                    }
                }
            }

            room.render();
        }
    });
}());
