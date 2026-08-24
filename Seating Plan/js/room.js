/**
 * Seating Plan: the room shell.
 *
 * Draws a room to fit the window and lets things be dragged around it. What
 * those things are, and what a click on one means, belongs to whichever mode
 * is active: furniture in the designer, students in the seating plan, and so
 * on. Everything here is shared by all of them.
 *
 * Positions and sizes are whole tenths of a cell throughout, never pixels, so
 * a room renders identically at any size.
 */
window.SeatingPlanRoom = (function () {
    'use strict';

    var modes = {};
    var mode = null;
    var config = {};

    var rootEl = null;
    var roomEl = null;
    var stageEl = null;
    var statusEl = null;

    var STEP = 10;
    var cell = 40;
    var selection = [];
    var dirty = false;
    var editRevision = 0;
    var saveInFlight = false;
    var saveAgain = false;
    var drag = null;

    // The last click that landed on an item, for spotting a double click.
    var lastRelease = null;
    var DOUBLE_CLICK_MS = 400;

    /* ---------------------------------------------------------------- utils */

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function text(key) {
        return (config.strings || {})[key] || '';
    }

    function setStatus(message, tone) {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = message || '';
        statusEl.className = 'sp-status' + (tone ? ' sp-status-' + tone : '');
    }

    function markDirty(message) {
        dirty = true;
        editRevision++;
        setStatus(message || text('unsaved'), 'warn');
        refreshSaveButton();
    }

    function items() {
        return mode ? mode.getItems() : [];
    }

    function specOf(item) {
        return mode.specOf(item);
    }

    /* -------------------------------------------------------------- geometry */

    /**
     * The footprint an item occupies, in tenths, once rotation is applied.
     */
    function footprint(item) {
        var turned = item.rotation === 1 || item.rotation === 3;
        return {
            w: turned ? item.sizeY : item.sizeX,
            h: turned ? item.sizeX : item.sizeY
        };
    }

    function rectOf(item) {
        var print = footprint(item);
        return { x: item.posX, y: item.posY, w: print.w, h: print.h };
    }

    function overlapArea(a, b) {
        var x = Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x));
        var y = Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y));
        return x * y;
    }

    function limitX() {
        return config.gridCols * STEP;
    }

    function limitY() {
        return config.gridRows * STEP;
    }

    /* --------------------------------------------------------------- sizing */

    /**
     * Picks the largest cell size that fits the whole room on screen, then
     * writes it once as a custom property. Every item sizes itself from it.
     */
    function fit() {
        var pad = 32;
        var availW = stageEl.clientWidth - pad;
        var availH = stageEl.clientHeight - pad;

        cell = Math.floor(Math.min(availW / config.gridCols, availH / config.gridRows));
        cell = Math.max(12, cell);

        roomEl.style.setProperty('--cell', cell + 'px');
        roomEl.style.setProperty('--cols', config.gridCols);
        roomEl.style.setProperty('--rows', config.gridRows);
    }

    /* ------------------------------------------------------------ rendering */

    /**
     * Redraws every item this shell manages.
     *
     * Only the item boxes are cleared, not the whole room: a mode can inject
     * something else of its own into the room element - the seating mode's
     * read-only furniture backdrop - and it must survive a redraw rather than
     * being wiped with everything else.
     */
    function render() {
        Array.prototype.slice.call(roomEl.children).forEach(function (child) {
            if (child.classList.contains('sp-item')) {
                child.remove();
            }
        });

        items().forEach(function (item, index) {
            roomEl.appendChild(buildItem(item, index));
        });
    }

    function buildItem(item, index) {
        var box = document.createElement('div');
        var print = footprint(item);
        var definition = specOf(item);

        box.className = 'sp-item' + (isSelected(index) ? ' is-selected' : '');
        box.dataset.index = String(index);

        // Base furniture, then devices, then seats. A chair is where a student
        // goes, so it stays the topmost thing in the room.
        box.dataset.layer = definition.layer || 'base';
        box.style.setProperty('--c', item.posX);
        box.style.setProperty('--r', item.posY);
        box.style.setProperty('--w', print.w);
        box.style.setProperty('--h', print.h);

        box.appendChild(mode.buildArt(item));

        if (config.canEdit && definition.resizable) {
            var handle = document.createElement('div');
            handle.className = 'sp-handle';
            handle.dataset.role = 'resize';
            box.appendChild(handle);
        }

        return box;
    }

    /**
     * Updates an item's geometry in place.
     *
     * This never replaces the element: during a drag the node holds the
     * pointer capture, and swapping it would drop the gesture halfway.
     */
    function positionItem(box, item) {
        var print = footprint(item);

        box.style.setProperty('--c', item.posX);
        box.style.setProperty('--r', item.posY);
        box.style.setProperty('--w', print.w);
        box.style.setProperty('--h', print.h);

        if (mode.positionArt) {
            mode.positionArt(box.querySelector('.sp-art'), item);
        }
    }

    function refreshItem(index) {
        var box = roomEl.querySelector('[data-index="' + index + '"]');
        if (!box) {
            return;
        }

        box.classList.toggle('is-selected', isSelected(index));
        positionItem(box, items()[index]);
    }

    /* ----------------------------------------------------------- selection */

    function isSelected(index) {
        return selection.indexOf(index) >= 0;
    }

    function refreshSelection(previous) {
        previous.concat(selection).forEach(refreshItem);
    }

    /**
     * Selects one piece, dropping anything else that was selected.
     */
    function select(index) {
        var previous = selection;
        selection = index >= 0 ? [index] : [];
        refreshSelection(previous);
    }

    /**
     * Adds or removes one piece from the selection, for Ctrl-click.
     */
    function toggleSelect(index) {
        var previous = selection.slice();
        var at = selection.indexOf(index);

        if (at >= 0) {
            selection.splice(at, 1);
        } else {
            selection.push(index);
        }

        refreshSelection(previous);
    }

    function selectAll() {
        var previous = selection.slice();
        selection = items().map(function (ignored, index) {
            return index;
        });
        refreshSelection(previous);
    }

    /* ------------------------------------------------------------- placing */

    /**
     * Finds the first free spot for a newly added piece, scanning left to
     * right and top to bottom so additions do not pile up on each other.
     */
    function firstFreeSpot(w, h) {
        // Scan on whole cells: a new piece should land squarely, and the user
        // can nudge it afterwards.
        for (var r = 0; r <= limitY() - h; r += STEP) {
            for (var c = 0; c <= limitX() - w; c += STEP) {
                if (!isOccupied(c, r, w, h)) {
                    return { posX: c, posY: r };
                }
            }
        }
        return { posX: 0, posY: 0 };
    }

    function isOccupied(col, row, w, h) {
        return items().some(function (other) {
            var print = footprint(other);
            return col < other.posX + print.w
                && col + w > other.posX
                && row < other.posY + print.h
                && row + h > other.posY;
        });
    }

    function deleteSelected() {
        if (selection.length === 0) {
            return;
        }

        var list = items();

        // Remove from the back so the earlier indices stay valid.
        selection.slice().sort(function (a, b) {
            return b - a;
        }).forEach(function (index) {
            list.splice(index, 1);
        });

        selection = [];
        markDirty();
        render();
    }

    /**
     * Turns one piece a quarter turn, refusing a turn that would push it out
     * of the room.
     *
     * @return bool Whether it turned.
     */
    function rotateItem(item) {
        var next = (item.rotation + 1) % 4;
        var turned = next === 1 || next === 3;
        var w = turned ? item.sizeY : item.sizeX;
        var h = turned ? item.sizeX : item.sizeY;

        if (item.posX + w > limitX() || item.posY + h > limitY()) {
            return false;
        }

        item.rotation = next;

        return true;
    }

    function rotateSelected() {
        if (selection.length === 0) {
            return;
        }

        // Each piece turns about its own centre, so a group keeps its shape.
        // A piece whose spec says it cannot be turned (a student tile,
        // where a click means something else entirely) is left out rather
        // than silently rotated - matching mirrorSelected()'s own
        // .mirrorable filter below.
        var turned = selection.filter(function (index) {
            return specOf(items()[index]).rotatable !== false
                && rotateItem(items()[index]);
        });

        if (turned.length === 0) {
            return;
        }

        markDirty();
        turned.forEach(refreshItem);
    }

    /**
     * Flips every mirrorable piece in the selection.
     */
    function mirrorSelected() {
        if (selection.length === 0) {
            setStatus(text('mirrorNothing'), 'warn');
            return;
        }

        var mirrored = selection.filter(function (index) {
            return specOf(items()[index]).mirrorable;
        });

        if (mirrored.length === 0) {
            setStatus(text('mirrorNotAllowed'), 'warn');
            return;
        }

        mirrored.forEach(function (index) {
            items()[index].flipped = !items()[index].flipped;
        });

        markDirty();
        mirrored.forEach(refreshItem);
    }

    /* ------------------------------------------------------------- bounds */

    function outOfBounds(item) {
        var print = footprint(item);

        return item.posX < 0
            || item.posY < 0
            || item.posX + print.w > limitX()
            || item.posY + print.h > limitY();
    }

    function pullInside(item) {
        var print = footprint(item);

        item.posX = clamp(item.posX, 0, Math.max(0, limitX() - print.w));
        item.posY = clamp(item.posY, 0, Math.max(0, limitY() - print.h));
    }

    /**
     * Marks every piece that does not fit, and returns the first of them.
     *
     * Highlighting beats naming a position the user cannot see: they need to
     * know which thing on screen to move.
     */
    function showOutOfBounds() {
        var first = -1;

        items().forEach(function (item, index) {
            var box = roomEl.querySelector('[data-index="' + index + '"]');
            var bad = outOfBounds(item);

            if (bad && first < 0) {
                first = index;
            }
            if (box) {
                box.classList.toggle('is-invalid', bad);
            }
        });

        return first;
    }

    /* ---------------------------------------------------------- room size */

    /**
     * The smallest room that still contains everything in it.
     */
    function contentExtent() {
        return items().reduce(function (extent, item) {
            var print = footprint(item);
            return {
                cols: Math.max(extent.cols, Math.ceil((item.posX + print.w) / STEP)),
                rows: Math.max(extent.rows, Math.ceil((item.posY + print.h) / STEP))
            };
        }, { cols: 1, rows: 1 });
    }

    /**
     * Applies a new room size, refusing to shrink over what is already in it.
     */
    function applySize(cols, rows, colsInput, rowsInput) {
        var extent = contentExtent();
        var wanted = { cols: clamp(cols, 10, 40), rows: clamp(rows, 10, 40) };
        var allowed = {
            cols: Math.max(wanted.cols, extent.cols),
            rows: Math.max(wanted.rows, extent.rows)
        };
        var blocked = allowed.cols !== wanted.cols || allowed.rows !== wanted.rows;

        colsInput.value = allowed.cols;
        rowsInput.value = allowed.rows;

        if (allowed.cols === config.gridCols && allowed.rows === config.gridRows) {
            if (blocked) {
                setStatus(text('tooSmall'), 'warn');
            }
            return;
        }

        config.gridCols = allowed.cols;
        config.gridRows = allowed.rows;

        fit();

        if (blocked) {
            setStatus(text('tooSmall'), 'warn');
        } else {
            markDirty();
        }
    }

    /* -------------------------------------------------------------- pointer */

    function onPointerDown(event) {
        // Only the primary button drags. Without this a right-click starts a
        // gesture that ends as a click, so mirroring also rotated the piece.
        if (!config.canEdit || event.button !== 0) {
            return;
        }

        var box = event.target.closest('.sp-item');

        if (!box) {
            // Clicking bare floor clears the selection, which is the only way
            // out of a multiple selection without picking something else.
            select(-1);
            return;
        }

        var index = parseInt(box.dataset.index, 10);
        var additive = event.ctrlKey || event.metaKey;
        var resizing = event.target.dataset.role === 'resize';

        // Pressing on a piece that is already part of a group keeps the group,
        // so the whole thing can be dragged. If the press turns out to be a
        // plain click, the group collapses to just this piece on release.
        var withinGroup = !additive && isSelected(index) && selection.length > 1;

        if (additive) {
            toggleSelect(index);
        } else if (!isSelected(index)) {
            select(index);
        }

        if (!isSelected(index)) {
            return;
        }

        // A resize only ever applies to the piece whose handle was grabbed.
        var moving = resizing ? [index] : selection.slice();
        var list = items();

        drag = {
            index: index,
            mode: resizing ? 'resize' : 'move',
            additive: additive,
            withinGroup: withinGroup,
            moving: moving,
            startX: event.clientX,
            startY: event.clientY,
            origins: moving.map(function (i) {
                return { posX: list[i].posX, posY: list[i].posY };
            }),
            originW: list[index].sizeX,
            originH: list[index].sizeY,
            moved: false
        };

        // A mode can snapshot whatever it needs about the pre-drag position
        // here: the shell mutates posX/posY in place as the drag moves, so
        // this is the only point anyone sees the item where it started.
        if (mode.onDragStart) {
            mode.onDragStart(drag.moving);
        }

        // Capture on the room, not the item: the room element is never
        // rebuilt, so the gesture survives any re-render.
        roomEl.setPointerCapture(event.pointerId);
        roomEl.classList.add('is-dragging');
        event.preventDefault();
    }

    function onPointerMove(event) {
        if (!drag) {
            return;
        }

        // A mode whose items never move (Register mode: a click always
        // means "cycle the mark", never "reposition me") skips position
        // tracking entirely. drag.moved is never set to true, so
        // onPointerUp's existing branch already treats any press-and-release
        // here as a click, regardless of how far the pointer actually
        // travelled - the fix for touchscreen jitter suppressing a tap.
        if (mode.movable === false) {
            return;
        }

        // Shift coarsens the snap back to whole cells, so lining a row of
        // desks up stays as easy as it was before the finer grid.
        var snap = event.shiftKey ? STEP : 1;
        var stepPx = cell / STEP;
        var dx = Math.round((event.clientX - drag.startX) / stepPx / snap) * snap;
        var dy = Math.round((event.clientY - drag.startY) / stepPx / snap) * snap;

        if (dx !== 0 || dy !== 0) {
            drag.moved = true;
        }

        var list = items();
        var item = list[drag.index];

        if (drag.mode === 'move') {
            // Clamp the whole group by its most constrained member, so a
            // multiple selection keeps its shape instead of collapsing against
            // a wall one piece at a time.
            var edgeX = limitX();
            var edgeY = limitY();
            var room = drag.moving.reduce(function (bounds, i, at) {
                var print = footprint(list[i]);
                var origin = drag.origins[at];

                return {
                    left: Math.min(bounds.left, origin.posX),
                    up: Math.min(bounds.up, origin.posY),
                    right: Math.min(bounds.right, edgeX - print.w - origin.posX),
                    down: Math.min(bounds.down, edgeY - print.h - origin.posY)
                };
            }, { left: Infinity, up: Infinity, right: Infinity, down: Infinity });

            var moveX = clamp(dx, -room.left, room.right);
            var moveY = clamp(dy, -room.up, room.down);

            drag.moving.forEach(function (i, at) {
                list[i].posX = drag.origins[at].posX + moveX;
                list[i].posY = drag.origins[at].posY + moveY;
            });

            drag.moving.forEach(refreshItem);

            return;
        }

        if (drag.mode === 'resize') {
            var turned = item.rotation === 1 || item.rotation === 3;
            // While turned, the on-screen axes are swapped relative to the
            // stored ones, so send the deltas to the matching dimension.
            var growW = turned ? dy : dx;
            var growH = turned ? dx : dy;

            // One tenth is the floor. Half a cell would force a wall, drawn
            // thinner than that, to fatten the moment it was resized.
            item.sizeX = Math.max(1, drag.originW + growW);
            item.sizeY = Math.max(1, drag.originH + growH);

            var next = footprint(item);
            if (item.posX + next.w > limitX()) {
                item.sizeX = drag.originW;
            }
            if (item.posY + next.h > limitY()) {
                item.sizeY = drag.originH;
            }
        }

        refreshItem(drag.index);
    }

    function onPointerUp() {
        roomEl.classList.remove('is-dragging');

        if (!drag) {
            return;
        }

        if (drag.moved) {
            var dropped = '';

            if (drag.mode === 'move' && mode.onDrop) {
                dropped = mode.onDrop(drag.moving);
            }

            // Something that was dragged is not half of a double click,
            // however quickly it happened.
            lastRelease = null;

            // A mode may hand back something to say about the drop - why a
            // tile went back where it came from, say. It has to travel
            // through markDirty(), which writes to the status bar itself
            // and would otherwise wipe a message set inside onDrop().
            markDirty(dropped || undefined);
        } else if (drag.mode === 'move' && !drag.additive) {
            if (isSecondClickOn(drag.index) && mode.onDoubleClick) {
                mode.onDoubleClick(drag.index);
                drag = null;

                return;
            }

            if (drag.withinGroup) {
                // A plain click inside a group means "just this one", not
                // "turn everything I had selected".
                select(drag.index);
            } else if (mode.onClick) {
                mode.onClick(drag.index);
            } else {
                // A press with no movement is a turn, matching the source app.
                rotateSelected();
            }
        }

        drag = null;
    }

    /**
     * Whether this release completes a double click on the same item.
     *
     * The gesture is recognised here rather than through the browser's own
     * dblclick event because a press captures the pointer on the room (see
     * onPointerDown), and a captured pointer makes the click and dblclick
     * that follow target the room rather than the tile that was pressed -
     * so a dblclick handler looking for the .sp-item under the cursor finds
     * nothing at all. By the time a release is being handled the item is
     * already known, and no hit-testing is needed.
     *
     * @param int index The item just released.
     *
     * @return bool
     */
    function isSecondClickOn(index) {
        var now = Date.now();
        var second = lastRelease !== null
            && lastRelease.index === index
            && (now - lastRelease.at) <= DOUBLE_CLICK_MS;

        // A completed double click starts the count again, so a third click
        // is the first half of the next one rather than another double.
        lastRelease = second ? null : { index: index, at: now };

        return second;
    }

    function onContextMenu(event) {
        var box = event.target.closest('.sp-item');
        if (!config.canEdit || !box) {
            return;
        }

        var index = parseInt(box.dataset.index, 10);

        // A mode that gives right-click its own meaning (Register: reverse
        // through the codes) owns the browser menu outright here - whether
        // this particular click actually does anything (an ambiguous tile,
        // say) is that mode's own call, not this shell's.
        if (mode.onRightClick) {
            event.preventDefault();
            mode.onRightClick(index);
            return;
        }

        if (!specOf(items()[index]).mirrorable) {
            return;
        }

        // Only swallow the browser menu for something we can actually mirror.
        event.preventDefault();
        select(index);
        mirrorSelected();
    }

    /* ---------------------------------------------------------------- save */

    function save() {
        if (!config.canEdit) {
            return;
        }

        if (!mode.getSavePayload) {
            return;
        }

        if (saveInFlight) {
            saveAgain = true;
            return;
        }

        var offender = showOutOfBounds();
        if (offender >= 0) {
            select(offender);
            setStatus(
                text('outOfBounds').replace(
                    '{piece}',
                    (specOf(items()[offender]).label || '').toLowerCase()
                ),
                'error'
            );
            return;
        }

        var requestRevision = editRevision;
        saveInFlight = true;
        saveAgain = false;
        setStatus(text('saving'));

        if (mode.onSaving) {
            mode.onSaving();
        }

        var body = new URLSearchParams();
        body.set('csrftoken', config.csrfToken);
        body.set('gridCols', config.gridCols);
        body.set('gridRows', config.gridRows);
        if (config.editVersion) {
            body.set('editVersion', config.editVersion);
        }

        var payload = mode.getSavePayload();
        Object.keys(payload).forEach(function (key) {
            body.set(key, payload[key]);
        });

        fetch(config.saveURL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (result) {
                result.httpStatus = response.status;
                return result;
            });
        }).then(function (result) {
            if (result && result.ok) {
                if (result.editVersion) {
                    config.editVersion = parseInt(result.editVersion, 10);
                }
                if (editRevision === requestRevision) {
                    dirty = false;
                } else {
                    dirty = true;
                    saveAgain = true;
                }
                if (result.forkedTo) {
                    adoptFork(result.forkedTo);
                    setStatus(result.message || text('saved'), 'ok');
                } else {
                    setStatus(
                        editRevision === requestRevision
                            ? text('saved')
                            : text('unsaved'),
                        editRevision === requestRevision ? 'ok' : 'warn'
                    );
                }
                refreshSaveButton();
                if (mode.onSaved) {
                    mode.onSaved(result);
                }
            } else {
                setStatus(
                    result && result.httpStatus === 409
                        ? text('saveConflict')
                        : ((result && result.message) || text('saveFailed')),
                    'error'
                );
                if (mode.onSaveFailed) {
                    mode.onSaveFailed(result);
                }
            }
        }).catch(function () {
            setStatus(text('saveFailed'), 'error');
            if (mode.onSaveFailed) {
                mode.onSaveFailed(null);
            }
        }).finally(function () {
            saveInFlight = false;
            refreshSaveButton();
            if (dirty && saveAgain) {
                saveAgain = false;
                save();
            }
        });
    }
    /**
     * Follows a save that landed on a new copy instead of the layout that
     * was opened.
     *
     * Two things have to move, or the next save forks a second time and the
     * teacher ends up with a pile of near-identical layouts: the id this
     * page sends with every save, and the address bar - so that a refresh,
     * a bookmark or the back button all return to the copy rather than to
     * the colleague's original.
     */
    function adoptFork(layoutID) {
        config.layoutID = layoutID;
        config.canEdit = true;

        var notice = document.getElementById('spBorrowed');
        if (notice) {
            notice.remove();
        }

        if (!window.history || !window.history.replaceState) {
            return;
        }

        var url = new URL(window.location.href);

        // Only a room opened by layout carries the id in its address; one
        // opened from a timetabled period is found by period and date, and
        // already resolves to the teacher's own layout now that they have
        // one, so its address needs no rewriting.
        if (url.searchParams.has('layout')) {
            url.searchParams.set('layout', layoutID);
            window.history.replaceState({}, '', url.toString());
        }
    }

    /**
     * Toggles the Save button's disabled state against whether there is
     * anything to save - only for a mode that opts in
     * (disableSaveWhenClean: true). Furniture and seating always allow a
     * harmless no-op re-save, matching their existing, unchanged behaviour.
     */
    function refreshSaveButton() {
        if (!mode || mode.disableSaveWhenClean !== true) {
            return;
        }

        var button = document.getElementById('spSave');
        if (button) {
            button.disabled = !dirty;
        }
    }

    /* --------------------------------------------------------------- wiring */

    function wireKeyboard() {
        document.addEventListener('keydown', function (event) {
            if (event.target.matches('input, textarea, select')) {
                return;
            }

            if (event.key === 'Delete' || event.key === 'Backspace') {
                event.preventDefault();
                // A mode that does not own its items - seating mode's
                // students exist whether or not they are on screen - opts
                // out, so a stray Delete cannot make one vanish and silently
                // drop a saved seat on the next save.
                if (mode.deletable !== false) {
                    deleteSelected();
                }
            } else if (event.key === 'r' || event.key === 'R') {
                rotateSelected();
            } else if (event.key === 'm' || event.key === 'M') {
                mirrorSelected();
            } else if ((event.ctrlKey || event.metaKey) && event.key === 'a') {
                event.preventDefault();
                selectAll();
            } else if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                event.preventDefault();
                save();
            } else if (event.key === 'Escape') {
                select(-1);
                showOutOfBounds();
            }
        });

        window.addEventListener('beforeunload', function (event) {
            if (!dirty) {
                return undefined;
            }
            event.preventDefault();
            event.returnValue = '';
            return '';
        });
    }

    function wireToolbar() {
        var colsInput = document.getElementById('spCols');
        var rowsInput = document.getElementById('spRows');

        [colsInput, rowsInput].forEach(function (input) {
            if (!input) {
                return;
            }
            input.addEventListener('change', function () {
                applySize(
                    parseInt(colsInput.value, 10) || config.gridCols,
                    parseInt(rowsInput.value, 10) || config.gridRows,
                    colsInput,
                    rowsInput
                );
            });
        });

        var buttons = {
            spSave: save,
            spRotate: rotateSelected,
            spMirror: mirrorSelected,
            spDelete: deleteSelected
        };

        Object.keys(buttons).forEach(function (id) {
            var button = document.getElementById(id);
            if (button) {
                button.addEventListener('click', buttons[id]);
            }
        });
    }

    /**
     * Prints the room on its own.
     *
     * What gets left out, and how the room is sized to the paper, is the
     * stylesheet's business - see the print rules in css/module.css and the
     * page orientation room.php works out from the room's own shape. All
     * that is needed here is to drop the selection first, since a selected
     * tile's accent outline means nothing on paper.
     */
    function printRoom() {
        select(-1);
        window.print();
    }

    /* ----------------------------------------------------------- interface */

    var room = {
        STEP: function () { return STEP; },
        config: function () { return config; },
        items: items,
        specOf: specOf,
        footprint: footprint,
        rectOf: rectOf,
        overlapArea: overlapArea,
        limitX: limitX,
        limitY: limitY,
        clamp: clamp,
        firstFreeSpot: firstFreeSpot,
        select: select,
        refreshItem: refreshItem,
        render: render,
        markDirty: markDirty,
        setStatus: setStatus,
        text: text,
        rotateSelected: rotateSelected,
        save: save
    };

    function register(definition) {
        modes[definition.id] = definition;
    }

    function start() {
        rootEl = document.getElementById('spDesigner');
        if (!rootEl) {
            return;
        }

        try {
            config = JSON.parse(rootEl.getAttribute('data-payload'));
        } catch (e) {
            return;
        }

        roomEl = document.getElementById('spRoom');
        stageEl = document.getElementById('spStage');
        statusEl = document.getElementById('spStatus');

        STEP = config.subdivisions || 10;
        mode = modes[config.mode] || modes[Object.keys(modes)[0]];

        if (!mode) {
            return;
        }

        mode.load(room, config);

        roomEl.addEventListener('contextmenu', onContextMenu);
        roomEl.addEventListener('pointerdown', onPointerDown);
        roomEl.addEventListener('pointermove', onPointerMove);
        roomEl.addEventListener('pointerup', onPointerUp);
        roomEl.addEventListener('pointercancel', onPointerUp);

        // Wired whether or not the room can be edited: a colleague looking at
        // a shared plan has as much use for a printout as its owner.
        var printButton = document.getElementById('spPrint');

        if (printButton) {
            printButton.addEventListener('click', printRoom);
        }

        if (config.canEdit) {
            wireToolbar();
            wireKeyboard();

            if (mode.mount) {
                mode.mount(room);
            }
        } else {
            setStatus(text('readOnly'));
        }

        window.addEventListener('resize', fit);

        fit();
        render();

        // A piece can end up outside the room if its size changed in a later
        // release. Pull those back in and let the user save the correction.
        var strays = items().filter(outOfBounds);

        if (strays.length > 0) {
            strays.forEach(pullInside);
            render();
            markDirty();
            setStatus(text('pulledInside').replace('{count}', strays.length), 'warn');
        }

        // Reflects whatever dirty state mode.load() itself set (register
        // mode may already be dirty on load, e.g. a mark only carried
        // forward from an earlier period) - not just changes made after.
        refreshSaveButton();
    }

    return { register: register, start: start };
}());
