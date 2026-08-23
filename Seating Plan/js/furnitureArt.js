/**
 * Seating Plan: furniture artwork, shared by furniture mode and the
 * read-only backdrop seating mode draws underneath the students.
 *
 * Pulled out of mode.furniture.js so both places draw a chair, a desk or a
 * door the same way rather than keeping two copies of the rotate/mirror/
 * label logic in step with each other.
 */
window.SeatingPlanFurnitureArt = (function () {
    'use strict';

    /**
     * Writes an item's size, turn and mirror onto its art element.
     */
    function applyArt(art, item) {
        art.style.setProperty('--uw', item.sizeX);
        art.style.setProperty('--uh', item.sizeY);
        art.style.setProperty('--rot', item.rotation);
        art.style.setProperty('--flip', item.flipped ? -1 : 1);
    }

    /**
     * Builds the art element for one piece of furniture.
     *
     * @param {Object} item The furniture item (type, rotation, flipped, size).
     * @param {Object} spec Its catalogue entry, for the planLabel.
     */
    function buildArt(item, spec) {
        var art = document.createElement('div');
        art.className = 'sp-art';
        art.dataset.type = item.type;
        applyArt(art, item);

        if (spec && spec.planLabel) {
            var label = document.createElement('span');
            label.className = 'sp-art-label';
            label.textContent = spec.planLabel;
            art.appendChild(label);
        }

        return art;
    }

    return { buildArt: buildArt, applyArt: applyArt };
}());
