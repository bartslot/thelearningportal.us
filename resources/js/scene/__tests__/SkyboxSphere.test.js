import { describe, it, expect, vi } from 'vitest'
import * as THREE from 'three'
import { SkyboxSphere } from '../SkyboxSphere.js'

describe('SkyboxSphere', () => {
  it('adds two inverted spheres to the scene on construction', () => {
    const scene = new THREE.Scene()
    new SkyboxSphere(scene)
    const skyboxes = scene.children.filter(c => c.userData?.role === 'skybox')
    expect(skyboxes).toHaveLength(2)
    skyboxes.forEach(s => expect(s.scale.x).toBeLessThan(0))
  })

  it('loadTexture resolves with a THREE.Texture for a known URL', async () => {
    const scene = new THREE.Scene()
    const sky = new SkyboxSphere(scene)
    sky._textureLoader = { load: (url, ok) => { const t = new THREE.Texture(); ok(t); return t } }

    const tex = await sky.loadTexture('about:blank')
    expect(tex).toBeInstanceOf(THREE.Texture)
  })

  it('crossfadeTo resolves after the configured duration', async () => {
    const scene = new THREE.Scene()
    const sky = new SkyboxSphere(scene)
    sky._textureLoader = { load: (url, ok) => { const t = new THREE.Texture(); ok(t); return t } }

    await expect(sky.crossfadeTo('about:blank', 30)).resolves.toBeUndefined()
  })
})
