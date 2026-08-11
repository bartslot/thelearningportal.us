# Measuring WebGL in the browser pane

Read this before you measure anything about the globe in Claude Code's browser pane.

Four sessions have hit this independently. It was written down after the first one and rediscovered
three more times, which is a distribution problem rather than a knowledge problem — hence this file,
in `docs/`, where a session lands rather than in a message someone has to be told about.

## The pane runs pages hidden

Measured on a real harness page:

```
requestAnimationFrame callbacks in 300 ms   0
setTimeout(300) actually fired after        1123 ms
document.hidden                             true
window.innerWidth                           0
```

No animation frames at all, timers clamped to roughly one a second, and a viewport with no width.

**Nothing errors.** MapLibre never parses its style without a frame tick, so it never renders AND
never finishes loading. `map.isStyleLoaded()` stays false forever with every tile fetched and every
texture decoded. The signature is: every asset 200 OK, textures decoding in milliseconds, zero
console output, and a page that simply waits. Every instinct says "slow asset" and every instinct is
wrong.

## The fix

A `MessageChannel` carrying the frame callbacks. MessageChannel is not throttled. About fifteen
lines, guarded on `document.hidden` so it is inert in a real browser. Working version lives in
`resources/js/timemap/tiles/__harness__/index.html`.

### It must be an inline classic script, BEFORE the module tag

This is the part that costs a cycle, and it is the reason the instruction is not simply "install a
shim". ES module imports are **hoisted** and run ahead of any module body — so a shim written at the
top of your harness JS installs *after* MapLibre has already captured the original
`requestAnimationFrame`, and then does nothing at all.

That failure is indistinguishable from not having written the shim. Same symptoms, same silence.

Keep the reason attached to the instruction. "Put the shim before the import" without the hoisting
explanation is exactly the kind of thing someone tidies into a module six months from now.

### Resize the pane as well

`innerWidth` is 0 until the pane is resized. The canvas then has no area and `readPixels` reads
nothing — which looks like a black render rather than a missing viewport.

### Expect it to look intermittent

The browser hands out an odd frame just after navigation, so **roughly half of reloads appear to
work**. That sends people hunting a race condition that does not exist. If a fix seems to work every
other time, this is why.

### Drive frames explicitly once they flow

`map._render(performance.now())` rather than waiting for one to arrive.

## Reading pixels

`preserveDrawingBuffer` is forced off here, so a post-frame `drawImage` returns pure **black** and
will convince you nothing rendered. Read pixels with `gl.readPixels` **inside** a custom layer's
`render`, which is the only point the buffer is guaranteed valid.

## What this pane cannot tell you

- **Real frame rates.** SwiftShader figures are meaningless. Report them as unavailable rather than
  quoting them; a wrong number is worse than an absent one.
- **Device capability.** `MAX_TEXTURE_SIZE` reads 16384 here (ANGLE Metal, M3 Pro) and says nothing
  whatever about a school Chromebook, where 4096 or 8192 is common — and where a texture over the
  limit **fails to bind rather than degrading**. Black sky, no error, invisible in review because
  the reviewer does not own the hardware it breaks on.

A device profile measured in this pane is not a device profile. Test selection logic against
synthetic profiles; real numbers need a GPU-backed Playwright run.

## The rule this produced

A harness that hung silently for want of a frame looked exactly like a broken feature. The fix was
not to chase the assets but to make the rig report: name which thing is outstanding, count the
seconds, and give up loudly.

Note that its watchdog deliberately does **not** hang off `load` — `load` only fires after a frame
renders, so in a hidden pane it never fires either, and a watchdog that cannot fire in the condition
it exists to detect is no watchdog.

> The one thing that must always report is the thing that reports nothing is reporting.

This belongs next to the testing rules rather than filed under tooling. A measurement rig that can
hang without saying so is the same failure as a test that cannot see a constant error: the
instrument agreeing with itself.
