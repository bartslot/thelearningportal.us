const LOCATION_PIN_SVG = `
<svg width="21" height="26" viewBox="0 0 21 26" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M10.3329 0C4.63543 0 0 4.63543 0 10.3329C0 19.3812 9.58334 25.4792 9.9913 25.735L10.334 25.9493L10.6767 25.735C11.0848 25.4795 20.668 19.3812 20.668 10.3329C20.668 4.63543 16.0326 0 10.3351 0H10.3329ZM10.3329 15.5C7.47996 15.5 5.16584 13.1871 5.16584 10.3329C5.16584 7.47996 7.47872 5.16584 10.3329 5.16584C13.1859 5.16584 15.5 7.47872 15.5 10.3329C15.5 13.1859 13.1871 15.5 10.3329 15.5Z" fill="white"/>
</svg>`

export class SceneOverlay {
  constructor(hostEl) {
    this.host = hostEl
    this.mounted = false
  }

  mount() {
    if (this.mounted) return
    this.host.classList.add('scene-overlay')
    this.host.innerHTML = `
      <div class="scene-overlay__year absolute bottom-40 left-40 flex flex-col gap-10" style="transition:opacity 600ms;">
        <div class="scene-overlay__location" style="display:flex; align-items:center; gap:8px; transition:opacity 600ms;  text-shadow:0 2px 4px rgba(0, 0, 0, 0.5);">
          <span data-year class="w-full text-6xl" style="font-weight:800; color:white; text-shadow:0 2px 4px rgba(0, 0, 0, 0.5);"></span>
            ${LOCATION_PIN_SVG}
            <span data-location style="font-size:14px; font-weight:600; color:white; letter-spacing:0.1em; text-transform:uppercase;"></span>
          </div>
        
        
      </div>
    `
    this.yearEl     = this.host.querySelector('[data-year]')
    this.locationEl = this.host.querySelector('[data-location]')
    this.yearWrap   = this.host.querySelector('.scene-overlay__year')
    this.locWrap    = this.host.querySelector('.scene-overlay__location')
    this.mounted = true
  }

  update({ year, location }) {
    if (!this.mounted) this.mount()

    if (year) {
      this.yearEl.textContent = String(year)
      this.yearWrap.style.opacity = '1'
    } else {
      this.yearWrap.style.opacity = '0'
    }

    if (location) {
      this.locationEl.textContent = String(location).toUpperCase()
      this.locWrap.style.opacity = '1'
    } else {
      this.locWrap.style.opacity = '0'
    }
  }

  destroy() {
    this.host.innerHTML = ''
    this.mounted = false
  }
}
