const LOCATION_PIN_SVG = `
<svg data-location-pin viewBox="0 0 21 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="width:clamp(0.55rem, min(3.5cqw, 3cqh), 1.3125rem); height:clamp(0.7rem, min(4.5cqw, 3.9cqh), 1.625rem); flex:none; display:none;">
  <path d="M10.3329 0C4.63543 0 0 4.63543 0 10.3329C0 19.3812 9.58334 25.4792 9.9913 25.735L10.334 25.9493L10.6767 25.735C11.0848 25.4795 20.668 19.3812 20.668 10.3329C20.668 4.63543 16.0326 0 10.3351 0H10.3329ZM10.3329 15.5C7.47996 15.5 5.16584 13.1871 5.16584 10.3329C5.16584 7.47996 7.47872 5.16584 10.3329 5.16584C13.1859 5.16584 15.5 7.47872 15.5 10.3329C15.5 13.1859 13.1871 15.5 10.3329 15.5Z" fill="white"/>
</svg>`

const escapeHtml = (s) =>
  String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))

/**
 * Typeset a year label so it reads like a history caption: ordinal suffixes go up
 * as superscript ("16th century" → 16ᵗʰ), and era markers (BCE/BC/CE/AD/AH) shrink.
 * Numeric years ("1453") pass through untouched. Input is escaped first — only the
 * known <sup>/<span> tags below are ever injected.
 */
export function formatYearHtml(raw) {
  let s = escapeHtml(raw)
  // Ordinal suffix: uppercase (16TH), a touch smaller and slightly raised — not a tiny superscript.
  s = s.replace(
    /(\d)(st|nd|rd|th)\b/gi,
    (_, d, suf) => `${d}<sup style="font-size:0.62em;font-weight:700;vertical-align:0.32em;line-height:0;letter-spacing:0.02em;">${suf.toUpperCase()}</sup>`
  )
  s = s.replace(
    /\b(BCE|BC|CE|AD|AH)\b/g,
    '<span style="font-size:0.5em;font-weight:600;letter-spacing:0.06em;margin-left:0.12em;vertical-align:0.18em;opacity:0.9;">$1</span>'
  )
  return s
}

export class SceneOverlay {
  /**
   * @param {{ editable?: boolean, onChange?: (patch: {sceneId:?number} & object) => void }} [opts]
   *   editable — Configure mode: the identity is editable inline and can be hidden with an ×.
   *   onChange — called with { sceneId, year?, location?, title?, hidden? } on any edit.
   */
  constructor(hostEl, { editable = false, onChange = null } = {}) {
    this.host = hostEl
    this.editable = editable
    this.onChange = onChange
    this.mounted = false
    this._raw = { year: '', location: '', title: '' }
    this._sceneId = null
  }

  mount() {
    if (this.mounted) return
    this.host.classList.add('scene-overlay')
    this.host.style.containerType = 'size'
    // NOTE: the cqh coefficients are ~56% of a naive read because the host used to carry
    // 128px vertical padding (py-32) that shrank the container's content box. The padding
    // is gone (it forced a 256px min-height that pushed the caption off short stages), so
    // cqh now measures the real stage height — the coefficients are scaled to match, and the
    // clamp mins are lowered so the caption shrinks (never overflows) on a tiny stage.
    this.host.innerHTML = `
      <div class="scene-overlay__year absolute flex flex-col" style="left:clamp(0.4rem, min(3cqw, 2.8cqh), 2.5rem); bottom:clamp(0.4rem, min(3cqw, 2.8cqh), 2.5rem); max-width:min(78cqw, 42rem); gap:clamp(0.25rem, min(1.4cqw, 1.2cqh), 0.75rem); transition:opacity 600ms;">
        <div class="scene-overlay__identity" style="display:flex; min-width:0; max-width:100%; align-items:center; gap:clamp(0.3rem, min(1.5cqw, 1.3cqh), 0.75rem);">
          <img data-flag class="scene-overlay__flag" alt="" style="height:clamp(1.2rem, min(8cqw, 6.7cqh), 4rem); width:auto; flex:none; border-radius:5px; box-shadow:0 3px 12px rgba(0,0,0,0.55); display:none;" />
          <span data-title class="scene-overlay__title" style="min-width:0; max-width:100%; overflow-wrap:anywhere; font-family:var(--font-history, inherit); font-size:clamp(0.7rem, min(5cqw, 4.5cqh), 2.5rem); font-weight:800; color:white; line-height:1.05; text-shadow:0 2px 10px rgba(0,0,0,0.65); display:none;"></span>
        </div>
        <div class="scene-overlay__meta" style="display:flex; min-width:0; max-width:100%; align-items:center; gap:clamp(0.3rem, min(1.5cqw, 1.3cqh), 0.75rem); text-shadow:0 2px 4px rgba(0, 0, 0, 0.5);">
          <span data-year style="min-width:0; white-space:nowrap; font-size:clamp(0.9rem, min(8.5cqw, 7.8cqh), 3.75rem); font-weight:800; color:white; line-height:1;"></span>
          <div class="scene-overlay__location" style="display:flex; min-width:0; max-width:100%; align-items:center; gap:clamp(0.2rem, min(1cqw, 0.9cqh), 0.5rem); transition:opacity 600ms;">
            ${LOCATION_PIN_SVG}
            <span data-location style="min-width:0; max-width:100%; overflow-wrap:anywhere; font-size:clamp(0.5rem, min(2.6cqw, 2.3cqh), 0.875rem); font-weight:600; color:white; letter-spacing:0.1em; line-height:1.2; text-transform:uppercase;"></span>
          </div>
        </div>
      </div>
    `
    this.flagEl     = this.host.querySelector('[data-flag]')
    this.titleEl    = this.host.querySelector('[data-title]')
    this.yearEl     = this.host.querySelector('[data-year]')
    this.locationEl = this.host.querySelector('[data-location]')
    this.locationPinEl = this.host.querySelector('[data-location-pin]')
    this.yearWrap   = this.host.querySelector('.scene-overlay__year')
    this.locWrap    = this.host.querySelector('.scene-overlay__location')
    if (this.editable) this._wireEditing()
    this.mounted = true
  }

  // ── Editable (Configure): inline edit + an × to hide the whole identity. ──
  _wireEditing() {
    this.yearWrap.style.pointerEvents = 'auto'
    // Debounced save while typing — robust even if a blur is missed (contenteditable +
    // the 3s status poll can swallow blur events).
    this._editTimer = null
    const save = (field, value) => {
      clearTimeout(this._editTimer)
      this._editTimer = setTimeout(() => this._emit({ [field]: value }), 500)
    }

    // Year — edits in plain text; re-typesets on blur.
    this.yearEl.contentEditable = 'true'
    this.yearEl.spellcheck = false
    this.yearEl.style.cssText += ' cursor:text; outline:none; border-radius:6px; text-transform:none;'
    this.yearEl.addEventListener('focus', () => {
      this.yearEl.style.boxShadow = '0 0 0 1.5px rgba(245,158,11,0.7)'
      this.yearEl.textContent = this._raw.year
    })
    this.yearEl.addEventListener('input', () => { this._raw.year = this.yearEl.innerText.trim(); save('year', this._raw.year) })
    this.yearEl.addEventListener('blur', () => {
      this.yearEl.style.boxShadow = 'none'
      this.yearEl.innerHTML = formatYearHtml(this._raw.year)
    })

    // Location — edits in plain text; display uppercases.
    this.locationEl.contentEditable = 'true'
    this.locationEl.spellcheck = false
    this.locationEl.style.cssText += ' cursor:text; outline:none; border-radius:6px; text-transform:none;'
    this.locationEl.addEventListener('focus', () => {
      this.locationEl.style.boxShadow = '0 0 0 1.5px rgba(245,158,11,0.7)'
      this.locationEl.textContent = this._raw.location
    })
    this.locationEl.addEventListener('input', () => { this._raw.location = this.locationEl.innerText.trim(); save('location', this._raw.location) })
    this.locationEl.addEventListener('blur', () => {
      this.locationEl.style.boxShadow = 'none'
      this.locationEl.textContent = this._raw.location.toUpperCase()
    })

    // Title — per-scene override.
    this.titleEl.contentEditable = 'true'
    this.titleEl.spellcheck = false
    this.titleEl.style.cssText += ' cursor:text; outline:none; border-radius:6px;'
    this.titleEl.addEventListener('focus', () => { this.titleEl.style.boxShadow = '0 0 0 1.5px rgba(245,158,11,0.7)' })
    this.titleEl.addEventListener('input', () => { this._raw.title = this.titleEl.innerText.trim(); save('title', this._raw.title) })
    this.titleEl.addEventListener('blur', () => { this.titleEl.style.boxShadow = 'none' })

    for (const el of [this.titleEl, this.yearEl, this.locationEl]) {
      el.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); el.blur() } })
    }
    // Show/hide is driven by the "Caption" toggle in the scene inspector — not an on-canvas
    // control — so there's no × or restore chip here.
  }

  _emit(patch) { this.onChange?.({ sceneId: this._sceneId, ...patch }) }

  /** Hide/show the whole identity block (persisted per scene as config.hide_identity). */
  setHidden(hidden) {
    if (!this.mounted) this.mount()
    this.yearWrap.style.display = hidden ? 'none' : ''
  }

  // Territory identity (constant for the lesson) — or a per-scene title override.
  setTerritory({ title, flagUrl } = {}) {
    if (!this.mounted) this.mount()
    this._raw.title = title || ''

    if (title) {
      this.titleEl.textContent = title
      this.titleEl.style.display = 'inline-block'
    } else {
      this.titleEl.style.display = this.editable ? 'inline-block' : 'none'
    }

    if (flagUrl) {
      this.flagEl.src = flagUrl
      this.flagEl.style.display = ''
      this.flagEl.onerror = () => { this.flagEl.style.display = 'none' }
    } else {
      this.flagEl.style.display = 'none'
    }
  }

  update({ year, location, sceneId, hidden } = {}) {
    if (!this.mounted) this.mount()
    if (sceneId !== undefined) this._sceneId = sceneId
    this._raw.year = year || ''
    this._raw.location = String(location ?? '').trim()

    if (year) {
      this.yearEl.innerHTML = formatYearHtml(year)
      this.yearWrap.style.opacity = '1'
    } else {
      this.yearWrap.style.opacity = this.editable ? '1' : '0'
      if (this.editable) this.yearEl.textContent = ''
    }

    if (this._raw.location) {
      this.locationEl.textContent = this._raw.location.toUpperCase()
      this.locWrap.style.opacity = '1'
      this.locWrap.style.display = 'flex'
      this.locationPinEl.style.display = ''
    } else {
      this.locationEl.textContent = ''
      this.locWrap.style.opacity = this.editable ? '1' : '0'
      this.locWrap.style.display = this.editable ? 'flex' : 'none'
      this.locationPinEl.style.display = 'none'
    }

    this.setHidden(!!hidden)
  }

  destroy() {
    this.host.innerHTML = ''
    this.mounted = false
  }
}
