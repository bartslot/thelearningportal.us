# Avatar Studio — Talking Head Design Spec
_Date: 2026-04-07_

## Goal

Build a resource-efficient, API-free talking avatar system for thelearningportal.us. A single portrait image (e.g. Julius Caesar) animates in real-time on the client using audio amplitude data and face landmark detection. No GPU, no AI API, no video encoding. Runs on Vercel Hobby (free tier) + Laravel on SiteGround.

---

## Architecture

```
Admin uploads portrait (PNG/JPG) in Avatar Studio
    ↓
Laravel stores original → dispatches ProcessAvatarPortrait job
    ↓
Vercel Function: detect-landmarks
    → face-api.js (TinyFaceDetector, ~190KB WASM weights, bundled)
    → detects 68 facial landmarks
    → crops mouth region → generates 4 mouth sprite frames (closed/half/open/wide)
    → crops eye regions → generates 2 eye overlay sprites (open/closed)
    → returns JSON: { landmarks, mouth_frames[], eye_frames[] }
    ↓
Laravel stores sprite frames + landmarks JSON per avatar
    ↓
── At lesson playback ──
Client requests animation manifest for lesson audio
    ↓
Vercel Function: audio-manifest
    → reads MP3 amplitude via Web Audio API / audiodecode
    → returns { duration, samples: [{t, amp}] } (~20 samples/sec)
    ↓
Flutter/browser canvas: portrait + sprite overlays + manifest → live animation
```

---

## Animation Layers

Four independent layers composited on an HTML5 canvas (or Flutter CustomPainter):

| Layer | Mechanism | Data source |
|---|---|---|
| Mouth open/close | Swap sprite frame (0–3) based on amplitude bucket | Amplitude manifest |
| Eye blink | Swap eye_open/eye_closed overlay, random interval 2–5s, squint on loud audio | Timer + amplitude threshold |
| Head micro-movement | `transform: translate(±2px) rotate(±1deg)` via Perlin noise loop | Client-side JS loop |
| Breathing | CSS `@keyframes` scale 100%→100.5%→100% on 4s cycle | Pure CSS |

---

## Data Models

### Avatar model additions
| Column | Type | Notes |
|---|---|---|
| `portrait_original_path` | string nullable | Raw upload path |
| `landmarks_json` | json nullable | Mouth/eye bounds + 68-point coords |
| `sprite_status` | enum | `pending`, `processing`, `ready`, `failed` |

### Storage layout
```
storage/app/public/avatars/{avatar_id}/
    portrait.jpg                  ← resized to 512px, normalized JPEG
    portrait_original.jpg         ← raw upload
    sprites/
        mouth_0_closed.png
        mouth_1_half.png
        mouth_2_open.png
        mouth_3_wide.png
        eye_open.png
        eye_closed.png
    landmarks.json
```

### landmarks.json shape
```json
{
  "mouth": { "x": 180, "y": 310, "w": 140, "h": 60 },
  "left_eye": { "x": 150, "y": 200, "w": 45, "h": 22 },
  "right_eye": { "x": 245, "y": 200, "w": 45, "h": 22 },
  "face_bounds": { "x": 100, "y": 120, "w": 280, "h": 340 }
}
```

### Audio manifest shape
```json
{
  "duration": 12.4,
  "samples": [
    { "t": 0.00, "amp": 0.00 },
    { "t": 0.05, "amp": 0.12 },
    { "t": 0.10, "amp": 0.47 }
  ]
}
```

---

## Vercel Functions

Located in `vercel-functions/api/` (separate from Laravel, deployed independently to Vercel).

### `POST /api/detect-landmarks`
- Input: `{ portrait_base64: string }`
- Runs `face-api.js` TinyFaceDetector + 68-point landmark model (weights bundled)
- Generates sprite frames by cropping + compositing on node-canvas
- Output: `{ landmarks, mouth_frames: [base64 x4], eye_frames: { open, closed } }`
- Target: <3s cold start, <500ms warm

### `POST /api/audio-manifest`
- Input: `{ audio_url: string }`
- Fetches MP3, decodes via `audiodecode` npm package
- Computes RMS amplitude per 50ms window
- Output: `{ duration, samples: [{t, amp}] }`
- Target: <2s for typical 60s lesson audio

---

## Avatar Studio UI (existing blade view, extended)

New "Portrait" tab added to existing tab bar:
- Upload portrait image (drag & drop or file picker)
- Processing status indicator (pending → processing → ready)
- Live preview: animated avatar plays with a sample audio clip
- Re-upload button to replace portrait and re-process sprites

Existing tabs (Voice Studio, Settings, Samples, Greeting) unchanged.

---

## Client Animation (Phase 1 → Phase 2)

### Phase 1 — Amplitude only
- Canvas draws portrait
- Mouth sprite selected by mapping `amp` to bucket: 0–0.1=closed, 0.1–0.3=half, 0.3–0.6=open, >0.6=wide
- Eyes blink on random timer (2–5s interval)
- Head Perlin noise + breathing CSS on wrapper div

### Phase 2 — Sprite overlays (upgrade)
- Same as Phase 1 but mouth/eye regions use pre-cropped sprites composited over portrait
- Sprite positions driven by `landmarks.json` coordinates
- Smoother, more accurate mouth shape per frame

---

## Constraints & Non-Goals

- No GPU required anywhere
- No AI API (OpenAI, fal.ai, etc.) — face-api.js runs entirely in Vercel Node.js runtime
- No video encoding — output is always JSON + PNG sprites, animation is client-side
- No real-time streaming — manifest is fetched once before playback starts
- v1 does NOT include eyebrow animation (future upgrade)
- SiteGround hosts Laravel only; Vercel hosts the two serverless functions only

---

## Build Phases

1. **Phase 1** — Vercel `audio-manifest` function + client amplitude animation (mouth + eyes + head + breathing)
2. **Phase 2** — Vercel `detect-landmarks` function + sprite generation + Avatar Studio upload UI + sprite-based animation upgrade
