// Shared easing system — one source of truth. Pick by INTENT (EASE.*), not by raw curve.
//
//  • EASING.*  — Robert Penner t→t functions (t in [0,1]) for manual requestAnimationFrame loops.
//  • BEZIER.*  — matching cubic-bezier() strings for WAAPI (element.animate) and CSS transitions.
//  • EASE.*    — semantic aliases: choose the intent, and the curve is decided here.
//
// Convention (see the feedback-easing-conventions memory):
//   enter/reveal (open, fade-in, appear)  → decelerate in  (easeOutCubic)
//   exit (close, fade-out)                → accelerate away (easeInCubic)
//   move/reposition/camera                → easeInOutCubic
//   draw-on (pen/coastline sweep)         → accelerate: slow build, then speeds up (easeInCubic)
//   pop (badge/pin/thumbnail appear)      → back-ease overshoot

export const EASING = {
  linear: (t) => t,
  easeInQuad: (t) => t * t,
  easeOutQuad: (t) => t * (2 - t),
  easeInOutQuad: (t) => (t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t),
  easeInCubic: (t) => t * t * t,
  easeOutCubic: (t) => (--t) * t * t + 1,
  easeInOutCubic: (t) => (t < 0.5 ? 4 * t * t * t : (t - 1) * (2 * t - 2) * (2 * t - 2) + 1),
  easeInQuart: (t) => t * t * t * t,
  easeOutQuart: (t) => 1 - (--t) * t * t * t,
  easeInOutQuart: (t) => (t < 0.5 ? 8 * t * t * t * t : 1 - 8 * (--t) * t * t * t),
  easeInQuint: (t) => t * t * t * t * t,
  easeOutQuint: (t) => 1 + (--t) * t * t * t * t,
  easeInOutQuint: (t) => (t < 0.5 ? 16 * t * t * t * t * t : 1 + 16 * (--t) * t * t * t * t),
};

// cubic-bezier() approximations of the same curves (for .animate() / CSS transition-timing-function).
export const BEZIER = {
  easeInQuad: 'cubic-bezier(0.55, 0.085, 0.68, 0.53)',
  easeOutQuad: 'cubic-bezier(0.25, 0.46, 0.45, 0.94)',
  easeInOutQuad: 'cubic-bezier(0.455, 0.03, 0.515, 0.955)',
  easeInCubic: 'cubic-bezier(0.55, 0.055, 0.675, 0.19)',
  easeOutCubic: 'cubic-bezier(0.215, 0.61, 0.355, 1)',
  easeInOutCubic: 'cubic-bezier(0.645, 0.045, 0.355, 1)',
  easeInQuart: 'cubic-bezier(0.895, 0.03, 0.685, 0.22)',
  easeOutQuart: 'cubic-bezier(0.165, 0.84, 0.44, 1)',
  easeInOutQuart: 'cubic-bezier(0.77, 0, 0.175, 1)',
  easeInQuint: 'cubic-bezier(0.755, 0.05, 0.855, 0.06)',
  easeOutQuint: 'cubic-bezier(0.23, 1, 0.32, 1)',
  easeInOutQuint: 'cubic-bezier(0.86, 0, 0.07, 1)',
  pop: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
};

// Semantic aliases — the ONLY thing UI code should reach for.
export const EASE = {
  enter: BEZIER.easeOutCubic,    // reveal / open / fade-in — decelerate to rest
  exit: BEZIER.easeInCubic,      // close / fade-out — accelerate away
  move: BEZIER.easeInOutCubic,   // reposition / layout / camera glide
  drawOn: BEZIER.easeInCubic,    // pen / coastline sweep — slow build, then speeds up
  pop: BEZIER.pop,               // badge / pin / thumbnail appear with a little overshoot
};
