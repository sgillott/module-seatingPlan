/**
 * Seating Plan: a small, static picture of a room layout.
 *
 * Used where somebody has to decide about a layout they cannot otherwise
 * see - taking a colleague's newer version, most of all. It draws with the
 * same furniture artwork the real room uses, so what is previewed here and
 * what appears in the designer cannot drift apart.
 *
 * Nothing is interactive: no dragging, no selection, no saving. Each
 * preview reads its own payload from a data attribute and sizes itself to
 * whatever space it is given.
 */
(function (window, document) {
    'use strict';

    var STEP = 10;

    /**
     * The largest cell size that fits the room in the space available,
     * bounded so a big room stays legible and a small one does not become
     * absurd.
     */
    function cellSize(element, cols, rows) {
        var width = element.clientWidth || 320;
        var maxHeight = parseInt(element.dataset.spPreviewHeight, 10) || 260;

        return Math.max(4, Math.floor(Math.min(width / cols, maxHeight / rows)));
    }

    /**
     * Draws one preview into its container.
     */
    function render(element) {
        var payload;

        try {
            payload = JSON.parse(element.dataset.payload || '{}');
        } catch (error) {
            return;
        }

        var cols = parseInt(payload.gridCols, 10) || 20;
        var rows = parseInt(payload.gridRows, 10) || 14;

        element.textContent = '';

        var room = document.createElement('div');
        room.className = 'sp-room sp-preview-room';
        room.style.setProperty('--cell', cellSize(element, cols, rows) + 'px');
        room.style.setProperty('--cols', cols);
        room.style.setProperty('--rows', rows);
        element.appendChild(room);

        var backdrop = window.SeatingPlanFurnitureBackdrop.build(
            payload,
            STEP,
            room
        );

        // The backdrop appends one box per item, in payload order, so the
        // change marks can be applied by position afterwards. Doing it this
        // way keeps the shared builder unaware of anything to do with
        // comparing two layouts.
        (payload.items || []).forEach(function (item, index) {
            var box = backdrop.children[index];

            if (box && item.state && item.state !== 'same') {
                box.classList.add('sp-item-' + item.state);
            }
        });
    }

    function renderAll() {
        var previews = document.querySelectorAll('[data-sp-preview]');

        Array.prototype.forEach.call(previews, render);
    }

    window.SeatingPlanLayoutPreview = { render: render, renderAll: renderAll };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderAll);
    } else {
        renderAll();
    }

    // Redraw on resize so a preview stays fitted when the window changes.
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(renderAll, 150);
    });
}(window, document));
