"""Build the hero timewarp sprite sheet from the Figma card grid.

The hero's particle field (resources/js/hero/timewarp.js) draws 24 lesson thumbnails. Fetching
them as 24 files costs 24 requests; this packs them into one square-cell atlas instead, at a size
and quality tuned for cards that are always in motion.

Source: Figma "History Portal Game", node 1220-2076 — a 4x6 grid of square history images, the
same pictures as the hero wheel (public/assets/videocards.webp). Export that node at 1360x2040 to
cards-full.png beside this script, then:

    python3 tools/build-timewarp-atlas.py
    cwebp -q 66 -m 6 atlas.png -o public/assets/timewarp-cards.webp   # 92 KB
    avifenc -q 46 -s 4 atlas.png public/assets/timewarp-cards.avif    # 78 KB

Corners are NOT rounded here: timewarp.js masks each cell once at runtime, which keeps the atlas
opaque — an alpha channel would roughly double the file.
"""

from PIL import Image

SRC = "cards-full.png"
COLS, ROWS = 4, 6
CELL = 160  # atlas cell size in px

src = Image.open(SRC).convert("RGB")
sw, sh = src.size
cw, ch = sw // COLS, sh // ROWS

atlas = Image.new("RGB", (COLS * CELL, ROWS * CELL))

for row in range(ROWS):
    for col in range(COLS):
        box = (col * cw, row * ch, (col + 1) * cw, (row + 1) * ch)
        tile = src.crop(box)
        # Cells are already square in the source, but centre-crop defensively.
        side = min(tile.size)
        left = (tile.width - side) // 2
        top = (tile.height - side) // 2
        tile = tile.crop((left, top, left + side, top + side))
        tile = tile.resize((CELL, CELL), Image.LANCZOS)
        atlas.paste(tile, (col * CELL, row * CELL))

atlas.save("atlas.png")
print("atlas", atlas.size)
