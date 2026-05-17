export class SceneTimelinePlayer {
  constructor({ scenes, skybox, overlay, timer, avatar }) {
    this.scenes  = scenes
    this.skybox  = skybox
    this.overlay = overlay
    this.timer   = timer
    this.avatar  = avatar

    this._listeners = new Map()
    this._isPlaying = false
    this._gameEndResolve = null

    this.timer.on('gameend', () => {
      if (this._gameEndResolve) {
        const r = this._gameEndResolve
        this._gameEndResolve = null
        r()
      }
    })
  }

  on(event, fn) {
    if (!this._listeners.has(event)) this._listeners.set(event, [])
    this._listeners.get(event).push(fn)
  }

  _emit(event, ...args) {
    (this._listeners.get(event) || []).forEach(fn => fn(...args))
  }

  totalSeconds() {
    return this.scenes.reduce((acc, s) => acc + Math.max(0, s.duration_seconds || 0), 0)
  }

  locate(timeSeconds) {
    let cursor = 0
    for (let i = 0; i < this.scenes.length; i++) {
      const d = Math.max(0, this.scenes[i].duration_seconds || 0)
      if (timeSeconds < cursor + d) {
        return { index: i, offset: timeSeconds - cursor }
      }
      cursor += d
    }
    return { index: this.scenes.length - 1, offset: 0 }
  }

  async playFrom(index = 0) {
    this._isPlaying = true
    this._index = index
    while (this._isPlaying && this._index < this.scenes.length) {
      await this._playOne(this.scenes[this._index])
      this._index += 1
    }
    if (this._index >= this.scenes.length) {
      this._emit('timelineend')
    }
  }

  pause() {
    this._isPlaying = false
    this.timer.pause()
  }

  async seek(timeSeconds) {
    const { index } = this.locate(timeSeconds)
    this.pause()
    this._index = index
    await this.playFrom(index)
  }

  async _playOne(scene) {
    this._emit('scenechange', scene)

    await this.skybox.crossfadeTo(scene.image_path)
    this.overlay.update({ year: scene.year, location: scene.location })

    if (scene.animation_clip_id) {
      this.avatar.setClip(scene.animation_clip_id)
    }

    if (scene.kind === 'game') {
      this.timer.show({ durationSeconds: scene.duration_seconds || 0 })
      this.timer.start()
      await this._waitForGameEnd()
      this.timer.hide()
    } else {
      await this.avatar.speak({
        audioUrl:  scene.audio_path,
        alignment: scene.audio_alignment,
        text:      scene.script_segment,
      })
    }
  }

  _waitForGameEnd() {
    return new Promise(resolve => { this._gameEndResolve = resolve })
  }
}
