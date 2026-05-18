#!/usr/bin/env python3
"""
Morph transfer wrapper using arkit-blendshape-tool.
Transfers 72 ARKit + Oculus viseme morph targets from the bundled reference
avatar to a target RPM GLB.

Usage:
    # Single avatar (called by Laravel TransferAvatarMorphs job):
    python3 transfer_morphs_arkit.py --avatar-id 5

    # Batch all avatars in public/avatars/{id}/character.glb:
    python3 transfer_morphs_arkit.py --all

    # Custom paths:
    python3 transfer_morphs_arkit.py --target /path/to/avatar.glb --output /path/to/out.glb
"""

import argparse
import os
import subprocess
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'arkit-blendshape-tool'))
import numpy as np
from glb_utils import load_glb, get_existing_morph_target_names, get_morph_target_data

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
TOOL_DIR    = os.path.join(SCRIPT_DIR, 'arkit-blendshape-tool')
APP_ROOT    = os.path.dirname(SCRIPT_DIR)
AVATARS_DIR = os.path.join(APP_ROOT, 'public', 'avatars')
REFERENCE   = os.path.join(TOOL_DIR, 'reference', 'brunette.glb')
PYTHON      = os.path.join(SCRIPT_DIR, 'venv', 'bin', 'python3')
MAIN        = os.path.join(TOOL_DIR, 'main.py')

FACE_MESH_NAMES = {'Wolf3D_Head', 'Wolf3D_Skin', 'Wolf3D_Avatar', 'Head', 'Face'}
GOOD_MORPH_JAW_THRESHOLD = 0.03  # metres — below this = zero-delta Blender junk


def has_good_morphs(glb_path: str) -> bool:
    """Return True if the GLB already has real face morphs (jawOpen > threshold)."""
    try:
        gltf = load_glb(glb_path)
        for i, mesh in enumerate(gltf.meshes):
            if mesh.name not in FACE_MESH_NAMES:
                continue
            names = get_existing_morph_target_names(gltf, i)
            if 'jawOpen' not in names:
                continue
            data = get_morph_target_data(gltf, i, 0)
            d = data.get('jawOpen')
            if d is None:
                continue
            max_delta = float(np.linalg.norm(d, axis=1).max())
            if max_delta > GOOD_MORPH_JAW_THRESHOLD:
                print(f"  [skip] {mesh.name} jawOpen={max_delta:.4f}m — morphs already good")
                return True
    except Exception as e:
        print(f"  [warn] could not inspect GLB: {e}")
    return False


def run_transfer(target_path: str, output_path: str) -> int:
    """Run main.py transfer. Returns exit code."""
    cmd = [
        PYTHON, MAIN, 'transfer',
        '--reference', REFERENCE,
        '--target',    target_path,
        '--output',    output_path,
    ]
    result = subprocess.run(cmd, cwd=TOOL_DIR)
    return result.returncode


def transfer_avatar(avatar_id: str, force: bool = False) -> None:
    glb = os.path.join(AVATARS_DIR, avatar_id, 'character.glb')
    if not os.path.exists(glb):
        print(f"ERROR: {glb} not found", file=sys.stderr)
        sys.exit(1)
    if not force and has_good_morphs(glb):
        print(f"Avatar {avatar_id}: skipped (already has good morphs)")
        sys.exit(0)
    code = run_transfer(glb, glb)
    sys.exit(code)


def transfer_all(force: bool = False) -> None:
    ids = sorted(
        (d for d in os.listdir(AVATARS_DIR) if d.isdigit()),
        key=int,
    )
    print(f"Found {len(ids)} avatars: {ids}")
    failed = []
    skipped = []
    for aid in ids:
        glb = os.path.join(AVATARS_DIR, aid, 'character.glb')
        if not os.path.exists(glb):
            print(f"\n[{aid}] SKIP: no character.glb")
            continue
        print(f"\n{'='*60}")
        print(f"Avatar {aid}")
        print('='*60)
        if not force and has_good_morphs(glb):
            skipped.append(aid)
            continue
        code = run_transfer(glb, glb)
        if code != 0:
            print(f"  FAILED (exit {code})", file=sys.stderr)
            failed.append(aid)

    print(f"\n{'='*60}")
    if skipped:
        print(f"Skipped (already good): {skipped}")
    if failed:
        print(f"Failed avatars: {failed}")
        sys.exit(1)
    else:
        print("All avatars done.")


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Transfer ARKit+Oculus morphs to RPM avatar GLBs')
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--avatar-id', metavar='ID',
                       help='Single avatar ID (rewrites public/avatars/{ID}/character.glb in place)')
    group.add_argument('--all', action='store_true',
                       help='Batch all avatars in public/avatars/')
    group.add_argument('--target', metavar='GLB',
                       help='Custom target GLB path (requires --output)')
    parser.add_argument('--output', metavar='GLB',
                        help='Output path when using --target')
    parser.add_argument('--force', action='store_true',
                        help='Skip morph quality check and always re-transfer')
    args = parser.parse_args()

    if args.avatar_id:
        transfer_avatar(args.avatar_id, force=args.force)
    elif args.all:
        transfer_all(force=args.force)
    elif args.target:
        if not args.output:
            parser.error('--target requires --output')
        sys.exit(run_transfer(args.target, args.output))
