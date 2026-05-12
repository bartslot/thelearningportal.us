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

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
TOOL_DIR    = os.path.join(SCRIPT_DIR, 'arkit-blendshape-tool')
APP_ROOT    = os.path.dirname(SCRIPT_DIR)
AVATARS_DIR = os.path.join(APP_ROOT, 'public', 'avatars')
REFERENCE   = os.path.join(TOOL_DIR, 'reference', 'brunette.glb')
PYTHON      = os.path.join(SCRIPT_DIR, 'venv', 'bin', 'python3')
MAIN        = os.path.join(TOOL_DIR, 'main.py')


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


def transfer_avatar(avatar_id: str) -> None:
    glb = os.path.join(AVATARS_DIR, avatar_id, 'character.glb')
    if not os.path.exists(glb):
        print(f"ERROR: {glb} not found", file=sys.stderr)
        sys.exit(1)
    code = run_transfer(glb, glb)
    sys.exit(code)


def transfer_all() -> None:
    ids = sorted(
        (d for d in os.listdir(AVATARS_DIR) if d.isdigit()),
        key=int,
    )
    print(f"Found {len(ids)} avatars: {ids}")
    failed = []
    for aid in ids:
        glb = os.path.join(AVATARS_DIR, aid, 'character.glb')
        if not os.path.exists(glb):
            print(f"\n[{aid}] SKIP: no character.glb")
            continue
        print(f"\n{'='*60}")
        print(f"Avatar {aid}")
        print('='*60)
        code = run_transfer(glb, glb)
        if code != 0:
            print(f"  FAILED (exit {code})", file=sys.stderr)
            failed.append(aid)

    print(f"\n{'='*60}")
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
    args = parser.parse_args()

    if args.avatar_id:
        transfer_avatar(args.avatar_id)
    elif args.all:
        transfer_all()
    elif args.target:
        if not args.output:
            parser.error('--target requires --output')
        sys.exit(run_transfer(args.target, args.output))
