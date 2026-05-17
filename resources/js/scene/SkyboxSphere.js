import * as THREE from 'three'

export class SkyboxSphere {
  constructor(scene, opts = {}) {
    this._scene = scene
    this._textureLoader = new THREE.TextureLoader()
    this._cache = new Map()
    this._activeIdx = 0

    const radius = opts.radius ?? 500
    const geometry = new THREE.SphereGeometry(radius, 60, 40)

    this._spheres = [0, 1].map(() => {
      const material = new THREE.MeshBasicMaterial({
        side: THREE.BackSide,
        transparent: true,
        opacity: 0,            // start invisible — only become opaque after a texture loads
        depthWrite: false,
      })
      const mesh = new THREE.Mesh(geometry, material)
      mesh.scale.x   = -1
      mesh.visible   = false   // also hidden until first crossfadeTo
      mesh.userData.role = 'skybox'
      scene.add(mesh)
      return mesh
    })
  }

  async loadTexture(url) {
    if (this._cache.has(url)) return this._cache.get(url)
    const tex = await new Promise((resolve, reject) => {
      this._textureLoader.load(url, t => {
        t.colorSpace = THREE.SRGBColorSpace
        t.mapping    = THREE.EquirectangularReflectionMapping
        resolve(t)
      }, undefined, reject)
    })
    this._cache.set(url, tex)
    return tex
  }

  async crossfadeTo(url, duration = 600) {
    const tex = await this.loadTexture(url)
    const fromIdx = this._activeIdx
    const toIdx   = 1 - fromIdx
    const toMesh   = this._spheres[toIdx]
    const fromMesh = this._spheres[fromIdx]

    toMesh.material.map = tex
    toMesh.material.needsUpdate = true
    toMesh.material.opacity = 0
    toMesh.visible = true

    const start = performance.now()
    return new Promise(resolve => {
      const tick = (now) => {
        const t = Math.min(1, (now - start) / duration)
        toMesh.material.opacity   = t
        fromMesh.material.opacity = 1 - t
        if (t < 1) {
          requestAnimationFrame(tick)
        } else {
          this._activeIdx = toIdx
          resolve()
        }
      }
      requestAnimationFrame(tick)
      // Failsafe for environments without RAF (vitest jsdom).
      setTimeout(() => {
        toMesh.material.opacity   = 1
        fromMesh.material.opacity = 0
        fromMesh.visible          = !!fromMesh.material.map
        this._activeIdx = toIdx
        resolve()
      }, duration + 10)
    })
  }
}
