/**
 * Seating Plan: the student tile - a photo and a name band.
 *
 * Shared by Seating mode (where a tile is dragged onto a chair) and Register
 * mode (where a click on the same tile cycles an attendance mark), the same
 * relationship furnitureArt.js has to the furniture modes.
 */
window.SeatingPlanStudentTile = (function () {
    'use strict';

    /**
     * Builds one student tile: a photo filling the frame, a name band along
     * the bottom.
     *
     * @param {Object} item Has at least .photo and .name.
     */
    function build(item) {
        var tile = document.createElement('div');
        tile.className = 'sp-student';

        var photo = document.createElement('img');
        photo.className = 'sp-student-photo';
        photo.src = item.photo;
        photo.alt = '';
        tile.appendChild(photo);

        var label = document.createElement('span');
        label.className = 'sp-student-name notranslate';
        // A name band is not text to translate, and a room full of names in
        // scripts Chrome doesn't recognise as English is exactly what
        // triggers its "Translate this page?" prompt.
        label.translate = false;
        label.setAttribute('translate', 'no');
        label.textContent = item.name;
        tile.appendChild(label);

        return tile;
    }

    return { build: build };
}());
