#!/usr/bin/env python3
"""
Strip morph targets from specific meshes in a GLB.
Usage: python3 strip_mesh_morphs.py <glb_path> <mesh_name> [<mesh_name> ...]
Rewrites the file in place.
"""
import sys, os

sys.path.insert(0, os.path.join(os.path.dirname(__file__), 'arkit-blendshape-tool'))

from glb_utils import load_glb, save_glb

def strip_morphs(glb_path: str, mesh_names: list[str]) -> None:
    gltf = load_glb(glb_path)
    stripped = []
    for mesh in gltf.meshes:
        if mesh.name in mesh_names:
            for prim in mesh.primitives:
                prim.targets = None
            if mesh.extras:
                mesh.extras.pop('targetNames', None)
            stripped.append(mesh.name)
    if stripped:
        save_glb(gltf, glb_path)
        print(f"Stripped morphs from: {stripped} in {glb_path}")
    else:
        print(f"No matching meshes found (looked for {mesh_names})")

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("Usage: strip_mesh_morphs.py <glb_path> <mesh_name> [<mesh_name> ...]")
        sys.exit(1)
    strip_morphs(sys.argv[1], sys.argv[2:])
