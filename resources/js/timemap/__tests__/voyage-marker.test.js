import { describe, it, expect } from 'vitest'
import * as THREE from 'three'
import { viewerDirection } from '../voyage-ships.js'

/**
 * Aiming a picture marker at the camera.
 *
 * The map renderer never gets a camera — MapLibre hands it a matrix straight to clip space — so
 * the direction the viewer is in has to be recovered from that matrix alone. Getting it wrong is
 * invisible until the map is tilted, where the marker shears into an ellipse, so it is worth
 * pinning down here rather than in a browser that cannot run WebGL.
 */

/** The view-projection matrix of a real camera at `eye` looking at the origin. */
const viewProjection = (eye, up = new THREE.Vector3(0, 1, 0)) => {
  const camera = new THREE.PerspectiveCamera(50, 1.6, 0.1, 1000)
  camera.position.copy(eye)
  camera.up.copy(up)
  camera.lookAt(0, 0, 0)
  camera.updateMatrixWorld(true)

  return new THREE.Matrix4().multiplyMatrices(camera.projectionMatrix, camera.matrixWorldInverse)
}

describe('viewerDirection', () => {
  const cases = [
    ['straight overhead', new THREE.Vector3(0, 40, 0), new THREE.Vector3(0, 0, 1)],
    ['tilted, looking north', new THREE.Vector3(0, 30, 30)],
    ['tilted and rotated', new THREE.Vector3(-25, 18, 12)],
    ['low and to the side', new THREE.Vector3(60, 4, -8)],
  ]

  for (const [name, eye, up] of cases) {
    it(`points at the camera when it is ${name}`, () => {
      const found = viewerDirection(viewProjection(eye, up))
      const expected = eye.clone().normalize()
      // A disc facing within a degree of the camera is indistinguishable from one facing it.
      expect(found.angleTo(expected)).toBeLessThan(0.02)
    })
  }

  it('returns a unit vector, so it can be used as a facing directly', () => {
    expect(viewerDirection(viewProjection(new THREE.Vector3(3, 9, -4))).length()).toBeCloseTo(1, 6)
  })

  it('turns a plane to face the camera rather than lying flat under it', () => {
    // The failure this guards: a marker left in the map's ground plane reads as a coin on the
    // water at the tour's 48° tilt instead of a portrait facing the class.
    const eye = new THREE.Vector3(0, 30, 30)
    const quaternion = new THREE.Quaternion().setFromUnitVectors(
      new THREE.Vector3(0, 0, 1),
      viewerDirection(viewProjection(eye))
    )
    const normal = new THREE.Vector3(0, 0, 1).applyQuaternion(quaternion)

    expect(normal.angleTo(eye.clone().normalize())).toBeLessThan(0.02)
  })
})
