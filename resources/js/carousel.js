import Flickity from 'flickity';
import 'flickity/css/flickity.css';

/**
 * The app's one carousel.
 *
 * Every horizontal row of cards — the landing shelves, the teacher's lesson shelves — is this
 * component, so a row behaves the same wherever a person meets it: drag with the pointer, swipe on
 * touch, arrows on hover. Rows used to be hand-rolled per page (overflow-x-auto with scroll-snap
 * and bespoke arrow buttons on one, Flickity on another), which meant two different feels for the
 * same gesture.
 *
 * Markup comes from the <x-carousel> Blade component; this only wakes it up. Any element carrying
 * .js-disney-carousel is picked up, including markup rendered after load.
 */

/** Shared for every row: change it here and every carousel in the app changes with it. */
export const CAROUSEL_OPTIONS = {
    cellAlign: 'left',
    contain: false,
    pageDots: false,
    prevNextButtons: true,
    wrapAround: false,
    freeScroll: false,
    selectedAttraction: 0.12,
    friction: 0.78,
    groupCells: false,
};

/** Mount every carousel inside `root` that is not mounted already. */
export function initCarousels (root = document) {
    root.querySelectorAll('.js-disney-carousel').forEach((element) => {
        // Flickity.data() is the only reliable "already mounted" test: the class it adds survives
        // a DOM snapshot/restore, the instance does not.
        if (Flickity.data(element)) {
            return;
        }

        new Flickity(element, CAROUSEL_OPTIONS);
    });
}

/**
 * Mount now and after any navigation that swaps the page's markup. Cheap to call repeatedly —
 * rows that already have an instance are skipped.
 */
export function watchCarousels () {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initCarousels(), { once: true });
    } else {
        initCarousels();
    }

    document.addEventListener('livewire:navigated', () => initCarousels());
}
