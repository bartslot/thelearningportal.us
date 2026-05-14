/**
 * Argument Map — scroll-driven tree visualisation
 *
 * ag-charts-community (v13) was evaluated but does not ship a tree/org-chart
 * diagram type. Its hierarchy series are limited to Treemap and Sunburst.
 * This implementation uses GSAP (already a project dependency) for the
 * scroll-triggered animations and hand-drawn SVG bezier connectors for the
 * node-to-node threading effect.
 */

import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

/* ── helpers ──────────────────────────────────────────────────────────────── */

/** Position of `el` relative to `ancestor` in document-space coordinates. */
function relTo(el, ancestor) {
    const er = el.getBoundingClientRect();
    const ar = ancestor.getBoundingClientRect();
    const sy = window.scrollY;
    const sx = window.scrollX;
    return {
        cx:     er.left + sx - (ar.left + sx) + er.width  / 2,
        top:    er.top  + sy - (ar.top  + sy),
        bottom: er.top  + sy - (ar.top  + sy) + er.height,
    };
}

function svgPath(x1, y1, x2, y2) {
    const my = y1 + (y2 - y1) * 0.52;
    return `M ${x1},${y1} C ${x1},${my} ${x2},${my} ${x2},${y2}`;
}

/* ── connector drawing ────────────────────────────────────────────────────── */

function drawConnectors(section, animate) {
    const svg  = section.querySelector('#map-svg');
    const root = section.querySelector('#map-root-card');
    if (!svg || !root) return;

    // Hide connectors on mobile — too noisy in single-column layout
    if (window.innerWidth < 768) {
        svg.innerHTML = '';
        return;
    }

    svg.innerHTML = '';

    const w = section.offsetWidth;
    const h = section.offsetHeight;
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);

    const from = relTo(root, section);

    section.querySelectorAll('.map-branch-card').forEach((card, i) => {
        const to = relTo(card, section);

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d',              svgPath(from.cx, from.bottom, to.cx, to.top));
        path.setAttribute('fill',           'none');
        path.setAttribute('stroke',         'rgba(245,158,11,0.30)');
        path.setAttribute('stroke-width',   '1.5');
        path.setAttribute('stroke-linecap', 'round');
        svg.appendChild(path);

        if (animate) {
            const len = path.getTotalLength();
            gsap.fromTo(path,
                { strokeDasharray: len, strokeDashoffset: len },
                { strokeDashoffset: 0, duration: 0.75, delay: i * 0.055, ease: 'power2.inOut' }
            );
        }
    });
}

/* ── card expand / collapse ───────────────────────────────────────────────── */

function initCards(section) {
    section.querySelectorAll('.map-branch-toggle').forEach(btn => {
        const card = btn.closest('.map-branch-card');
        const body = card.querySelector('.map-branch-body');
        const icon = btn.querySelector('.map-toggle-icon');

        // Start collapsed
        gsap.set(body, { height: 0, opacity: 0, overflow: 'hidden' });

        btn.addEventListener('click', () => {
            const open = card.dataset.open === 'true';

            if (open) {
                gsap.to(body, { height: 0, opacity: 0, duration: 0.3, ease: 'power2.inOut' });
                gsap.to(icon, { rotation: 0, duration: 0.3, ease: 'power2.out' });
                card.dataset.open = 'false';
            } else {
                // Measure natural height
                gsap.set(body, { height: 'auto', opacity: 1 });
                const naturalH = body.offsetHeight;
                gsap.fromTo(body,
                    { height: 0, opacity: 0 },
                    { height: naturalH, opacity: 1, duration: 0.38, ease: 'power2.out' }
                );
                gsap.to(icon, { rotation: 180, duration: 0.3, ease: 'power2.out' });
                card.dataset.open = 'true';
            }

            // Redraw connectors after height settles
            setTimeout(() => drawConnectors(section, false), 420);
        });
    });
}

/* ── scroll-triggered entry animations ────────────────────────────────────── */

function initScrollAnimations(section) {
    // Hide before trigger fires
    gsap.set('#map-root-card',   { autoAlpha: 0, y: 22 });
    gsap.set('.map-branch-card', { autoAlpha: 0, y: 32 });

    ScrollTrigger.create({
        trigger: section,
        start:   'top 74%',
        once:    true,
        onEnter() {
            // 1 — root node
            gsap.to('#map-root-card', {
                autoAlpha: 1, y: 0,
                duration:  0.72,
                ease:      'power3.out',
            });

            // 2 — branch cards, staggered from centre outward
            gsap.to('.map-branch-card', {
                autoAlpha: 1, y: 0,
                duration:  0.56,
                stagger:   { amount: 0.68, from: 'center' },
                ease:      'power3.out',
                delay:     0.32,
                onComplete() {
                    drawConnectors(section, true);
                },
            });
        },
    });
}

/* ── main init ────────────────────────────────────────────────────────────── */

function initArgumentMap() {
    const section = document.getElementById('js-argument-map');
    if (!section) return;

    initCards(section);
    initScrollAnimations(section);

    // Recalculate on resize (debounced)
    let resizeId;
    window.addEventListener('resize', () => {
        clearTimeout(resizeId);
        resizeId = setTimeout(() => {
            ScrollTrigger.refresh();
            drawConnectors(section, false);
        }, 180);
    });
}

document.addEventListener('DOMContentLoaded', initArgumentMap);
