# Whiteboard

A tldraw board that Bart and Claude can both draw on. `board.json` is the only thing the two sides
share — the browser reads and writes it over HTTP, Claude reads and writes the same file from the
repo. Neither side needs to know the other exists.

```bash
cd tools/whiteboard && npm install && npm run build   # once
npm start                                             # http://localhost:4599
```

`npm run build` bundles tldraw into `vendor/`. It is vendored rather than pulled from a CDN so the
board opens offline and does not change under you the day the CDN resolves a different version.

## How the two sides stay in sync

The file's mtime is the version. The page polls once a second and **merges** what it finds
record by record; it never loads the whole document over the top, because that drops whatever the
other side added in between — which is how a batch of cards written straight into `board.json`
vanished the first time this was tried. A save pulls before it writes, so the 400ms save cannot beat
the 1s poll while someone is actively drawing.

Two edits to the *same shape* in the same second still resolve last-write-wins. Different shapes,
including deletes, merge cleanly.

## Driving it from code

`window.editor` is the tldraw editor, so the board can be driven from the browser console. From the
repo, read or write `board.json` directly: it is `{ store: { <id>: record }, schema: {...} }`. Clone
an existing record as a template rather than hand-writing one — that way every field matches the
tldraw version in `vendor/` instead of a shape a schema bump can quietly invalidate.
