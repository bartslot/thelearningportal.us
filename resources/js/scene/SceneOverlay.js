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
      <div class="scene-overlay__year" style="position:absolute; top:24px; left:80px; display:flex; align-items:center; gap:12px; transition:opacity 600ms;">
        <div class="relative flex-col items-center justify-center" style="width:190px; height:120px;"> 
          <span data-year class="absolute top-1/2 mt-4 -left-12 w-full" style="font-size:72px; font-weight:800; color:white; letter-spacing:-0.02em;"></span>
          <svg class="absolute top-0 right-0 w-52 h-52" viewBox="0 0 242 247" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M70.6114 230.489C101.07 239.178 135.374 236.099 165.474 219.266C222.809 187.201 243.181 117.391 210.974 63.3393C178.767 9.28789 106.176 -8.537 48.8404 23.527C29.4733 34.3584 14.3299 49.5019 4 66.885C14.3759 46.2657 30.9967 28.3201 53.048 15.9875C111.891 -16.9205 187.122 2.60256 221.081 59.5934C255.039 116.584 234.866 189.462 176.023 222.37C142.65 241.033 104.008 242.826 70.6114 230.489Z" fill="white"/>
          </svg>
        </div>
        
      </div>
      <div class="scene-overlay__location" style="position:absolute; bottom:96px; left:32px; display:flex; align-items:center; gap:8px; transition:opacity 600ms;">
        ${LOCATION_PIN_SVG}
        <span data-location style="font-size:14px; font-weight:600; color:white; letter-spacing:0.1em; text-transform:uppercase;"></span>
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
