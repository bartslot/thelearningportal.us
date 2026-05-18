# The Learning Portal — Brand Guidelines

> For use with Claude, ChatGPT, Gemini, or any LLM. Paste this document as system context when requesting UI, design, copy, or creative work.

---

## Identity

**Product name:** The Learning Portal  
**Domain:** thelearningportal.us  
**Tagline:** "Where Storytelling Meets Learning. AI-Powered. Teacher-Centric. Results-Driven."  
**Category:** AI-powered K-12 EdTech — cinematic, premium, multimedia  
**Mood reference:** Netflix × Disney+ × museum-quality documentary  
**Key emotion:** wonder, trust, cinematic excitement — NOT childish, NOT corporate

---

## Color System

### Primary Palette

| Token | Hex | Tailwind | Usage |
|-------|-----|----------|-------|
| `color-bg-base` | `#020617` | `slate-950` | Page / app background |
| `color-bg-surface` | `#0f172a` | `slate-900` | Cards, panels, sidebars |
| `color-bg-elevated` | `#1e293b` | `slate-800` | Hover states, modals, tooltips |
| `color-bg-muted` | `#334155` | `slate-700` | Dividers, disabled |
| `color-accent-primary` | `#f59e0b` | `amber-500` | CTAs, highlights, active states |
| `color-accent-warm` | `#fbbf24` | `amber-400` | Icon fills, badge labels |
| `color-accent-glow` | `#fde68a` | `amber-200` | Glow halos, shimmer highlights |
| `color-accent-deep` | `#b45309` | `amber-600` | Pressed/active button states |
| `color-sky-bright` | `#38bdf8` | `sky-400` | Portal effects, magic accents |
| `color-sky-soft` | `#bae6fd` | `sky-100` | Text on dark, subtle overlays |
| `color-text-primary` | `#f8fafc` | `slate-50` | Headlines, primary text |
| `color-text-secondary` | `#94a3b8` | `slate-400` | Body copy, meta |
| `color-text-muted` | `#64748b` | `slate-500` | Placeholders, disabled text |

### Gradient Recipes

```css
/* Hero / landing background — deep space */
background: linear-gradient(160deg, #020617 0%, #0f172a 45%, #1e3a5f 80%, #020617 100%);

/* Amber CTA button */
background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);

/* Amber glow shimmer (hover) */
background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 60%, #b45309 100%);

/* Card surface with depth */
background: linear-gradient(145deg, #1e293b 0%, #0f172a 100%);

/* Cinematic vignette overlay (full-bleed images) */
background: radial-gradient(ellipse at center, transparent 40%, rgba(2,6,23,0.85) 100%);

/* Portal / magic sky tint */
background: radial-gradient(circle, rgba(125,211,252,0.14) 0%, rgba(56,189,248,0.06) 40%, transparent 70%);

/* Section divider glow line */
background: linear-gradient(90deg, transparent, #f59e0b, transparent);
height: 1px;

/* Amber text gradient (display headings) */
background: linear-gradient(135deg, #fde68a 0%, #fbbf24 40%, #f59e0b 100%);
-webkit-background-clip: text;
-webkit-text-fill-color: transparent;
```

### CSS Custom Properties (add to `:root`)

```css
:root {
  --lp-bg-base:         #020617;
  --lp-bg-surface:      #0f172a;
  --lp-bg-elevated:     #1e293b;
  --lp-accent:          #f59e0b;
  --lp-accent-warm:     #fbbf24;
  --lp-accent-glow:     #fde68a;
  --lp-accent-deep:     #b45309;
  --lp-sky:             #38bdf8;
  --lp-text:            #f8fafc;
  --lp-text-secondary:  #94a3b8;

  /* Shadows */
  --lp-shadow-card:   0 4px 24px rgba(0,0,0,0.55), 0 1px 4px rgba(0,0,0,0.35);
  --lp-shadow-amber:  0 0 32px rgba(245,158,11,0.35), 0 0 8px rgba(245,158,11,0.2);
  --lp-shadow-sky:    0 0 40px rgba(56,189,248,0.25);
  --lp-glow-amber:    0 0 60px rgba(245,158,11,0.4);

  /* Film grain — apply as pseudo-element */
  --lp-grain-opacity: 0.04;

  /* Border */
  --lp-border-subtle:  1px solid rgba(255,255,255,0.08);
  --lp-border-amber:   1px solid rgba(245,158,11,0.35);
}
```

---

## Typography

### Typefaces

| Role | Font | Weight | Notes |
|------|------|--------|-------|
| Display / hero headlines | **History** | SemiBold 600, Bold 700 | Serif. Use for lesson titles, avatar names, section heroes. |
| UI / body | **Inter** | 400, 500, 600 | Sans-serif. All UI chrome, buttons, labels, body copy. |
| Monospace / code | `ui-monospace` | 400 | Error messages, API keys |

### Scale

```
Display:   History Bold,   3.5rem / 1.1 lh   — hero headlines
H1:        History SemiBold, 2.5rem / 1.15 lh
H2:        History SemiBold, 1.875rem / 1.2 lh
H3:        Inter 600,      1.25rem / 1.3 lh
Body:      Inter 400,      1rem / 1.6 lh
Small:     Inter 400,      0.875rem / 1.5 lh
Label:     Inter 500,      0.75rem / 1 lh, letter-spacing: 0.08em, UPPERCASE
```

### Rules
- Display + H1/H2 always **History** — never Inter for hero text
- Amber gradient on display text for premium moments (see gradient recipes above)
- Body copy color: `slate-400` on dark backgrounds — NOT pure white (too harsh)
- Letter-spacing labels: `tracking-widest` + uppercase = Netflix-style category labels

---

## Film Grain & Texture

Grain adds cinematic depth. Apply as a CSS pseudo-element overlay — never bake it into actual images.

```css
/* Reusable grain layer */
.lp-grain::after {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  border-radius: inherit;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='1'/%3E%3C/svg%3E");
  opacity: var(--lp-grain-opacity, 0.04);
  mix-blend-mode: overlay;
  z-index: 1;
}

/* Heavier grain for hero full-bleed images */
.lp-grain-heavy::after {
  --lp-grain-opacity: 0.07;
}
```

Apply to: hero sections, card thumbnails, avatar video posters, modal backdrops.  
**Do NOT apply to:** buttons, form inputs, text blocks.

---

## Component Patterns

### Cards (lesson / avatar)

```css
.lp-card {
  background: linear-gradient(145deg, #1e293b 0%, #0f172a 100%);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 12px;
  box-shadow: var(--lp-shadow-card);
  overflow: hidden;
  position: relative; /* for grain */
}

.lp-card:hover {
  border-color: rgba(245,158,11,0.30);
  box-shadow: var(--lp-shadow-card), var(--lp-shadow-amber);
  transform: translateY(-2px);
  transition: all 220ms ease;
}
```

Thumbnail images inside cards get: `object-fit: cover` + vignette overlay + `.lp-grain`.

### Buttons

```css
/* Primary CTA */
.lp-btn-primary {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  color: #020617;
  font-weight: 600;
  border-radius: 8px;
  padding: 0.625rem 1.5rem;
  box-shadow: 0 2px 12px rgba(245,158,11,0.35);
  border: none;
}
.lp-btn-primary:hover {
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
  box-shadow: var(--lp-glow-amber);
}

/* Ghost / secondary */
.lp-btn-ghost {
  background: transparent;
  border: 1px solid rgba(245,158,11,0.45);
  color: #fbbf24;
  border-radius: 8px;
  padding: 0.625rem 1.5rem;
}
.lp-btn-ghost:hover {
  background: rgba(245,158,11,0.08);
  border-color: #f59e0b;
}
```

### Progress / Badge

```css
/* Netflix-style category label */
.lp-label {
  font-size: 0.7rem;
  font-weight: 500;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #fbbf24;
  background: rgba(245,158,11,0.12);
  border: 1px solid rgba(245,158,11,0.25);
  border-radius: 4px;
  padding: 2px 8px;
}

/* Progress bar */
.lp-progress {
  background: rgba(255,255,255,0.08);
  border-radius: 999px;
  height: 4px;
}
.lp-progress-fill {
  background: linear-gradient(90deg, #f59e0b, #fbbf24);
  border-radius: 999px;
  box-shadow: 0 0 8px rgba(245,158,11,0.5);
}
```

### Video Poster / Thumbnail

- Always dark background: `slate-900` or `slate-950`
- Amber play button overlay at center
- Vignette gradient on image edges
- Film grain overlay (`.lp-grain`)
- Duration badge: bottom-right, `slate-950/90` bg, `slate-50` text — exactly like Netflix

---

## Iconography

- **Style:** Lucide Icons (outlined, 1.5px stroke) — matches Inter's clean geometry
- **Size:** 20px (UI), 24px (nav), 32px (feature), 48px (hero accent)
- **Color:** `amber-400` for active/accent icons; `slate-400` for neutral/muted
- **No filled icons** unless it's a status indicator (check, warning, error)

---

## Motion & Animation

| Pattern | Spec |
|---------|------|
| Card hover lift | `translateY(-2px)`, 220ms ease |
| Button hover glow | box-shadow expand, 180ms ease |
| Page fade-in | opacity 0→1, translateY 8px→0, 350ms ease-out |
| Skeleton shimmer | `bg-gradient shimmer`, 1.4s infinite |
| Portal swirl | Three.js / canvas, 60fps, sky-blue particles |
| Amber shimmer on headings | CSS keyframe, 3s infinite alternate |

```css
@keyframes lp-shimmer {
  0%   { background-position: -200% center; }
  100% { background-position: 200% center; }
}

.lp-text-shimmer {
  background: linear-gradient(90deg, #fde68a 0%, #f59e0b 40%, #fde68a 80%);
  background-size: 200% auto;
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  animation: lp-shimmer 3s linear infinite;
}
```

---

## Imagery

- **Photography style:** cinematic stills, dramatic lighting, dark backgrounds, slight golden/warm color grade
- **Portraits (avatars):** illustrated or photo-realistic historical figures — serious, noble, lit from 45° above
- **No stock-photo smiles** — everything should feel curated, editorial
- **Aspect ratios:** 16:9 for lesson thumbnails, 3:4 for avatar portraits, 1:1 for profile avatars
- **Always add:** vignette overlay + film grain layer on every image
- **Color grade:** push shadows to `slate-950`, lift midtones toward warm amber/gold

---

## Voice & Tone

| Context | Tone |
|---------|------|
| Hero / marketing | Epic, cinematic — "History lives again" |
| Teacher dashboard | Confident, efficient — "Your lesson is ready" |
| Student app | Encouraging, adventurous — "Julius Caesar wants to tell you something" |
| Error states | Calm, helpful — "Something went wrong. Try again." |
| Empty states | Inviting — "No lessons yet — create your first in 2 minutes" |

**Avoid:** "amazing!", exclamation spam, corporate buzzwords, dumbed-down language.  
**Use:** active verbs, short sentences, present tense.

---

## App Shell (Flutter + Web)

### Background layers (bottom → top)

1. `slate-950` base fill
2. Radial ambient — navy-blue center glow: `rgba(30,58,138,0.25)` at viewport center
3. (Optional) faint star-field canvas or SVG noise texture at 3% opacity
4. Content layer
5. Top vignette for nav bar blur: `linear-gradient(to bottom, rgba(2,6,23,0.92), transparent)`

### Nav bar

- `bg-slate-950/90` + `backdrop-filter: blur(16px)`
- Bottom border: `1px solid rgba(255,255,255,0.06)`
- Logo: History SemiBold, amber-400 — no icon needed at small sizes
- Active nav item: amber-400 text + 2px amber-500 bottom indicator

### Mobile (Flutter)

```dart
// Color constants — Flutter
const Color lpBgBase      = Color(0xFF020617);  // slate-950
const Color lpBgSurface   = Color(0xFF0F172A);  // slate-900
const Color lpBgElevated  = Color(0xFF1E293B);  // slate-800
const Color lpAccent      = Color(0xFFF59E0B);  // amber-500
const Color lpAccentWarm  = Color(0xFFFBBF24);  // amber-400
const Color lpSky         = Color(0xFF38BDF8);  // sky-400
const Color lpText        = Color(0xFFF8FAFC);  // slate-50
const Color lpTextMuted   = Color(0xFF94A3B8);  // slate-400

// Gradient — hero section
const LinearGradient lpHeroGradient = LinearGradient(
  begin: Alignment.topLeft,
  end: Alignment.bottomRight,
  colors: [Color(0xFF020617), Color(0xFF0F172A), Color(0xFF1E3A5F)],
  stops: [0.0, 0.45, 1.0],
);

// Accent button
const LinearGradient lpAmberGradient = LinearGradient(
  colors: [Color(0xFFF59E0B), Color(0xFFD97706)],
);
```

---

## Tailwind Config Snippet

```js
// tailwind.config.js or @theme in app.css
colors: {
  lp: {
    bg:        { base: '#020617', surface: '#0f172a', elevated: '#1e293b', muted: '#334155' },
    accent:    { DEFAULT: '#f59e0b', warm: '#fbbf24', glow: '#fde68a', deep: '#b45309' },
    sky:       { DEFAULT: '#38bdf8', soft: '#bae6fd' },
    text:      { DEFAULT: '#f8fafc', secondary: '#94a3b8', muted: '#64748b' },
  }
}
```

---

## LLM Prompt Snippet (copy-paste)

Use this block when prompting Claude, ChatGPT, Gemini, or any AI for UI/design work:

```
You are designing for The Learning Portal (thelearningportal.us), an AI-powered K-12 EdTech 
platform. Visual reference: Netflix × Disney+ × cinematic documentary.

PALETTE:
- Background: #020617 (deepest), #0f172a (surfaces), #1e293b (elevated)
- Accent: amber — #f59e0b (primary), #fbbf24 (warm), #fde68a (glow), #b45309 (deep)
- Magic accent: sky-blue #38bdf8 for portal/AI effects only
- Text: #f8fafc (primary), #94a3b8 (secondary)

GRADIENTS:
- Hero bg: 160deg, #020617 → #0f172a → #1e3a5f → #020617
- Amber CTA: 135deg, #f59e0b → #d97706
- Card surface: 145deg, #1e293b → #0f172a
- Text display: amber gradient shimmer on History font headings

TEXTURE: subtle film grain (CSS noise, 4% opacity, mix-blend-mode: overlay) on all hero 
images and cards.

TYPOGRAPHY:
- Display/headings: "History" serif (SemiBold/Bold) — amber gradient for hero moments
- UI/body: Inter sans-serif

FEEL: dark, cinematic, premium — wonder and trust. NOT childish. NOT corporate.
```

---

## What NOT to Do

- No light/white backgrounds (this is always dark-mode)
- No red — anywhere
- No Comic Sans, Roboto, or system default fonts for display text
- No flat, fully-saturated color blocks — always gradient or add depth
- No pure `#ffffff` text — use `slate-50` (`#f8fafc`) maximum brightness
- No childish rounded pastel cards — everything has depth and shadow
- No stock-photo teacher/student imagery with fake smiles
