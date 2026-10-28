/**
 * Seating Plan: Room Exits mode.
 *
 * No arming, unlike Rewards mode - a click on a seated student always means
 * "the opposite of whatever they are right now". A student currently out
 * carries a small "OUT" badge showing how long, ticking live; clicking them
 * again marks them back in and the badge disappears. The server decides the
 * toggle direction from the real open-exit rows (see roomExit_saveAjax.php)
 * - this file never guesses ahead of a response.
 *
 * Reuses room.js's own click pipeline exactly as Rewards mode does, not
 * Picker mode's bypass - a click here always lands on one specific student.
 */
(function () {
    'use strict';

    var room = null;
    var config = null;
    var items = [];
    var tickTimer = null;
    var pendingByStudent = {};

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
     * The room's furniture, drawn once as a read-only backdrop - the exact
     * pattern mode.seating.js, mode.picker.js and mode.rewards.js all
     * already use. Wrapped in its own .sp-backdrop container, never itself
     * .sp-item-classed, so room.js's own render() (which only clears
     * .sp-item elements it finds as *direct* children of #spRoom) never
     * touches it when it rebuilds the student tiles this mode's own
     * getItems() supplies.
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
        var openExits = config.openExits || {};

        buildBackdrop(STEP);

        // Students come and go whether or not the room has been drawn or the
        // class arranged: anyone the seating plan does not place is laid out
        // in rows, so nobody is off screen and therefore impossible to sign
        // out.
        var placement = window.SeatingPlanSeatPlacement.resolve({
            roster: config.roster,
            seats: config.seats,
            chairs: null,
            gridCols: config.gridCols,
            gridRows: config.gridRows,
            step: STEP
        });

        items = (config.roster || []).map(function (student) {
            var spot = placement.positions[student.gibbonPersonID];
            var open = openExits[student.gibbonPersonID];

            return {
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
                timeOut: open ? open.timeOut : null,
                timeLimit: open ? open.timeLimit : null
            };
        });

        // room.js builds the actual tile elements *after* load() returns
        // (inside its own start()), so the initial paint of anyone already
        // out has to wait for that - a setTimeout(0) runs on the next tick,
        // once the current, synchronous start() call has finished.
        window.setTimeout(updateAllBadges, 0);

        if (tickTimer === null) {
            tickTimer = window.setInterval(updateAllBadges, 1000);
        }
    }

    /* ------------------------------------------------------------ badges */

    function boxFor(index) {
        return document.querySelector('[data-index="' + index + '"]');
    }

    // Safari/iOS need the space swapped for "T" to parse a MySQL datetime
    // string reliably.
    function parseTime(value) {
        return new Date(value.replace(' ', 'T')).getTime();
    }

    function elapsedText(timeOut, timeLimit) {
        // The clock stops when the lesson ends: the server closes the exit
        // at that moment, so counting on would show a time never recorded.
        var now = timeLimit ? Math.min(Date.now(), parseTime(timeLimit)) : Date.now();
        var seconds = Math.max(0, Math.floor((now - parseTime(timeOut)) / 1000));
        var mins = Math.floor(seconds / 60);
        var secs = seconds % 60;

        return mins + ':' + (secs < 10 ? '0' : '') + secs;
    }

    function updateBadge(index) {
        var item = items[index];
        var box = boxFor(index);

        if (!box) {
            return;
        }

        var badge = box.querySelector('.sp-roomexit-badge');

        if (!item.timeOut) {
            if (badge) {
                badge.remove();
            }
            box.classList.remove('sp-roomexit-out');
            box.classList.toggle('sp-roomexit-pending', !!item.pending);
            return;
        }

        box.classList.add('sp-roomexit-out');
        box.classList.toggle('sp-roomexit-pending', !!item.pending);

        if (!badge) {
            badge = document.createElement('div');
            badge.className = 'sp-roomexit-badge';
            box.appendChild(badge);
        }

        badge.textContent = elapsedText(item.timeOut, item.timeLimit);
    }

    function updateAllBadges() {
        items.forEach(function (item, index) {
            if (item.timeOut) {
                updateBadge(index);
            }
        });
    }

    /* ------------------------------------------------------------- saving */

    // Once the lesson is over a click cannot mean "out now", so it asks when.
    // A student still shown out is closed by the ordinary toggle instead.
    function onClick(index) {
        var item = items[index];

        if (item.timeOut || Date.now() < parseTime(config.lessonEnd)) {
            send(index, null);
            return;
        }

        if (!config.recordable) {
            room.setStatus(room.text('exitTooOld'), 'error');
            return;
        }

        openPastForm(index);
    }

    function timeOf(datetime) {
        return datetime.substr(11, 5);
    }

    function field(labelText, value) {
        var label = document.createElement('label');
        var input = document.createElement('input');

        label.className = 'sp-exit-field';
        label.textContent = labelText;
        input.type = 'time';
        input.value = value;
        input.min = timeOf(config.lessonStart);
        input.max = timeOf(config.lessonEnd);
        label.appendChild(input);

        return { label: label, input: input };
    }

    function openPastForm(index) {
        var item = items[index];
        var dialog = document.createElement('dialog');
        var form = document.createElement('form');
        var title = document.createElement('h3');
        var help = document.createElement('p');
        var buttons = document.createElement('div');
        var save = document.createElement('button');
        var cancel = document.createElement('button');
        var out = field(room.text('exitPastOut'), timeOf(config.lessonStart));
        var back = field(room.text('exitPastIn'), '');

        dialog.className = 'sp-exit-dialog';
        form.method = 'dialog';
        title.textContent = room.text('exitPastTitle').replace('{name}', item.name);
        title.setAttribute('translate', 'no');
        help.textContent = config.exitDefaultMinutes > 0
            ? room.text('exitPastHelpMinutes').replace('{minutes}', config.exitDefaultMinutes)
            : room.text('exitPastHelp');
        out.input.required = true;
        buttons.className = 'sp-exit-buttons';
        save.className = 'sp-btn sp-btn-primary';
        save.value = 'save';
        save.textContent = room.text('exitPastSave');
        cancel.className = 'sp-btn';
        cancel.type = 'button';
        cancel.textContent = room.text('exitPastCancel');
        cancel.addEventListener('click', function () {
            dialog.close();
        });

        buttons.appendChild(cancel);
        buttons.appendChild(save);
        form.appendChild(title);
        form.appendChild(out.label);
        form.appendChild(back.label);
        form.appendChild(help);
        form.appendChild(buttons);
        dialog.appendChild(form);

        form.addEventListener('submit', function () {
            send(index, { timeOut: out.input.value, timeIn: back.input.value });
        });
        dialog.addEventListener('close', function () {
            dialog.remove();
        });

        document.getElementById('spDesigner').appendChild(dialog);
        dialog.showModal();
        out.input.focus();
    }

    function send(index, times) {
        var item = items[index];
        var personID = String(item.gibbonPersonID);

        if (pendingByStudent[personID]) {
            return;
        }

        var requestID = Date.now() + Math.random();
        pendingByStudent[personID] = requestID;
        item.pending = true;
        var tile = boxFor(index);
        if (tile) {
            tile.style.pointerEvents = 'none';
        }
        updateBadge(index);

        var body = new URLSearchParams();
        body.set('csrftoken', config.csrfToken);
        body.set('classList', config.classList);
        body.set('gibbonSpaceID', config.gibbonSpaceID);
        body.set('date', config.date);
        body.set('anchorTTDayRowClassID', config.anchorTTDayRowClassID);
        body.set('gibbonPersonID', item.gibbonPersonID);
        if (times) {
            body.set('timeOut', times.timeOut);
            body.set('timeIn', times.timeIn);
        }

        fetch(config.roomExitSaveURL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            if (pendingByStudent[personID] !== requestID) {
                return;
            }

            if (!result || !result.ok) {
                room.setStatus((result && result.message) || room.text('saveFailed'), 'error');
                return;
            }

            // The server is authoritative: never predict the next state in
            // the browser, especially when an old response arrives late.
            item.timeOut = result.state === 'out' ? result.timeOut : null;
            item.timeLimit = result.state === 'out' ? result.timeLimit : null;
            updateBadge(index);

            if (times) {
                room.setStatus(room.text('exitPastSaved').replace('{name}', item.name), 'ok');
            }
        }).catch(function () {
            if (pendingByStudent[personID] === requestID) {
                room.setStatus(room.text('saveFailed'), 'error');
            }
        }).finally(function () {
            if (pendingByStudent[personID] === requestID) {
                delete pendingByStudent[personID];
                item.pending = false;
                var currentTile = boxFor(index);
                if (currentTile) {
                    currentTile.style.pointerEvents = '';
                }
                updateBadge(index);
            }
        });
    }
    /* ----------------------------------------------------------- the mode */

    window.SeatingPlanRoom.register({
        id: 'roomexit',

        movable: false,
        deletable: false,

        load: load,

        getItems: function () {
            return items;
        },

        specOf: spec,

        buildArt: function (item) {
            return window.SeatingPlanStudentTile.build(item);
        },

        onClick: onClick
    });
}());
