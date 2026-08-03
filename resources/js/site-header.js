// The header's one piece of state: is the hero still behind it?
//
// Over the hero the bar is invisible and its action is a ghost pill, because the hero owns the
// only solid button on the screen. Past the hero it takes a tinted backdrop and an amber action.
// Everything else — entrance, hover, the underline — is CSS (see .site-header in app.css), so a
// JS failure can never leave the navigation unusable.

const SENTINEL_HEIGHT = '24px';

export const setupSiteHeader = () => {
    const header = document.querySelector('[data-site-header]');

    if (!header || typeof IntersectionObserver !== 'function') {
        return;
    }

    // A sentinel beats a scroll listener: the browser reports the crossing itself, so there is
    // no per-frame work and nothing to throttle.
    const sentinel = document.createElement('div');
    sentinel.setAttribute('aria-hidden', 'true');
    sentinel.style.cssText = `position:absolute;top:0;left:0;width:1px;height:${SENTINEL_HEIGHT};pointer-events:none;`;
    document.body.prepend(sentinel);

    const observer = new IntersectionObserver(
        ([entry]) => header.classList.toggle('is-scrolled', !entry.isIntersecting),
        { threshold: 0 },
    );

    observer.observe(sentinel);
};
