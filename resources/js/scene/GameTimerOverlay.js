export class GameTimerOverlay {
  constructor(hostEl) {
    this.host = hostEl
    this._listeners = new Map()
    this._intervalId = null
  }

  on(event, fn) {
    if (!this._listeners.has(event)) this._listeners.set(event, [])
    this._listeners.get(event).push(fn)
  }

  _emit(event, ...args) {
    (this._listeners.get(event) || []).forEach(fn => fn(...args))
  }

  show({ durationSeconds }) {
    this._remaining = Math.max(0, Math.floor(durationSeconds))
    this.host.innerHTML = `
      <div style="position:absolute; inset:0; background:rgba(0,0,0,0.55); display:flex; flex-direction:column; align-items:center; justify-content:center; color:white;">
        <div style="font-size:14px; letter-spacing:0.2em; text-transform:uppercase; opacity:0.85;">TIME TO COMPLETE THE CHALLENGE</div>
        <div data-timer style="font-size:96px; font-weight:700; margin-top:24px;">${this._format(this._remaining)}</div>
      </div>
    `
    this._timerEl = this.host.querySelector('[data-timer]')
  }

  start() {
    this._stop()
    this._intervalId = setInterval(() => {
      this._remaining = Math.max(0, this._remaining - 1)
      if (this._timerEl) this._timerEl.textContent = this._format(this._remaining)
      if (this._remaining === 0) {
        this._stop()
        this._emit('gameend')
      }
    }, 1000)
  }

  pause() { this._stop() }
  seek(seconds) {
    this._remaining = Math.max(0, Math.floor(seconds))
    if (this._timerEl) this._timerEl.textContent = this._format(this._remaining)
  }
  hide() { this._stop(); this.host.innerHTML = '' }

  _stop() { if (this._intervalId) { clearInterval(this._intervalId); this._intervalId = null } }
  _format(s) {
    const m = Math.floor(s / 60)
    const r = s % 60
    return `${m}:${String(r).padStart(2, '0')}`
  }
}
