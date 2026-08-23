/**
 * Seating Plan: the corner badge configuration panel.
 *
 * A small slide-in panel, independent of the room canvas: four slot boxes
 * (top-left, top-right, bottom-left, bottom-right, in that fixed order) and
 * a palette of every badge source the school has. Plain HTML5 drag-and-drop
 * is used here rather than the room's own pointer-based item system, since
 * this list lives entirely outside the grid.
 */
window.SeatingPlanBadgePanel = (function () {
    'use strict';

    var panelEl = null;
    var slotsEl = null;
    var paletteEl = null;
    var toggleEl = null;
    var catalogue = [];
    var slots = ['', '', '', ''];
    var emptyText = 'Empty';
    var onChange = null;

    function labelFor(key) {
        for (var i = 0; i < catalogue.length; i++) {
            if (catalogue[i].key === key) {
                return catalogue[i].label;
            }
        }
        return key;
    }

    function renderSlots() {
        var boxes = slotsEl.querySelectorAll('.sp-badge-slot');

        boxes.forEach(function (box, index) {
            var key = slots[index] || '';
            box.textContent = key ? labelFor(key) : emptyText;
            box.classList.toggle('is-empty', key === '');
            box.dataset.key = key;
            box.draggable = key !== '';
        });
    }

    function renderPalette() {
        paletteEl.innerHTML = '';

        catalogue.forEach(function (source) {
            var chip = document.createElement('div');
            chip.className = 'sp-badge-chip';
            chip.textContent = source.label;
            chip.draggable = true;
            chip.dataset.key = source.key;
            paletteEl.appendChild(chip);
        });
    }

    function setSlot(index, key) {
        slots[index] = key;
        renderSlots();
        if (onChange) {
            onChange(slots.slice());
        }
    }

    function swapSlots(a, b) {
        var temp = slots[a];
        slots[a] = slots[b];
        slots[b] = temp;
        renderSlots();
        if (onChange) {
            onChange(slots.slice());
        }
    }

    function dragKeyFrom(event) {
        try {
            return JSON.parse(event.dataTransfer.getData('text/plain'));
        } catch (e) {
            return null;
        }
    }

    function wireDrag() {
        paletteEl.addEventListener('dragstart', function (event) {
            var chip = event.target.closest('.sp-badge-chip');
            if (!chip) {
                return;
            }
            event.dataTransfer.setData(
                'text/plain',
                JSON.stringify({ from: 'palette', key: chip.dataset.key })
            );
        });

        slotsEl.addEventListener('dragstart', function (event) {
            var box = event.target.closest('.sp-badge-slot');
            if (!box || !box.dataset.key) {
                return;
            }
            event.dataTransfer.setData(
                'text/plain',
                JSON.stringify({
                    from: 'slot',
                    index: parseInt(box.dataset.slot, 10),
                    key: box.dataset.key
                })
            );
        });

        slotsEl.addEventListener('dragover', function (event) {
            if (event.target.closest('.sp-badge-slot')) {
                event.preventDefault();
            }
        });

        slotsEl.addEventListener('drop', function (event) {
            var box = event.target.closest('.sp-badge-slot');
            if (!box) {
                return;
            }
            event.preventDefault();

            var dragged = dragKeyFrom(event);
            if (!dragged) {
                return;
            }

            var targetIndex = parseInt(box.dataset.slot, 10);

            if (dragged.from === 'palette') {
                setSlot(targetIndex, dragged.key);
            } else if (dragged.from === 'slot' && dragged.index !== targetIndex) {
                swapSlots(dragged.index, targetIndex);
            }
        });

        // Dropping a filled slot anywhere that is not itself a slot clears
        // it - dragging a badge back out onto the palette, or open floor,
        // both read the same way: "I don't want this here any more".
        panelEl.addEventListener('dragover', function (event) {
            event.preventDefault();
        });

        panelEl.addEventListener('drop', function (event) {
            if (event.target.closest('.sp-badge-slot')) {
                return;
            }
            event.preventDefault();

            var dragged = dragKeyFrom(event);
            if (dragged && dragged.from === 'slot') {
                setSlot(dragged.index, '');
            }
        });
    }

    /**
     * @param {Object} refs Element references: panel, slots, palette, toggle.
     * @param {Array}  initialCatalogue [{key, label}, ...].
     * @param {Array}  initialSlots     4 source keys, '' for empty.
     * @param {string} initialEmptyText Placeholder text for an empty slot.
     * @param {Function} changeCallback Called with the new 4-slot array
     *                                  whenever a drag changes it.
     */
    function init(refs, initialCatalogue, initialSlots, initialEmptyText, changeCallback) {
        panelEl = refs.panel;
        slotsEl = refs.slots;
        paletteEl = refs.palette;
        toggleEl = refs.toggle;
        catalogue = initialCatalogue || [];
        slots = (initialSlots || ['', '', '', '']).slice(0, 4);
        while (slots.length < 4) {
            slots.push('');
        }
        emptyText = initialEmptyText || 'Empty';
        onChange = changeCallback || null;

        renderPalette();
        renderSlots();
        wireDrag();

        if (toggleEl) {
            toggleEl.addEventListener('click', function () {
                panelEl.hidden = !panelEl.hidden;
            });
        }
    }

    function getSlots() {
        return slots.slice();
    }

    return { init: init, getSlots: getSlots };
}());
