"""
transfer_morphs_single.py — transfer viseme morphs from donor GLB to one avatar

Usage:
  /Applications/Blender.app/Contents/MacOS/Blender --background --python \
    transfer_morphs_single.py -- <avatar_id> <avatar_dir>

Args (after --):
  avatar_id   — integer, used only for logging
  avatar_dir  — absolute path to the avatar folder (contains character.glb)
"""

import bpy
import os
import sys
import shutil

DONOR_GLB = "/Users/bartslot/Downloads/Avatars/ready-player-me-avatar/source/617b091cfb622cf1cd9cc537.glb"
DONOR_HEAD_NAME = "Wolf3D_Head"

# Blender passes script args after "--"
argv = sys.argv
try:
    sep = argv.index("--")
    args = argv[sep + 1:]
except ValueError:
    args = []

if len(args) < 2:
    print("[ERROR] Usage: blender --background --python transfer_morphs_single.py -- <avatar_id> <avatar_dir>")
    sys.exit(1)

AVATAR_ID  = args[0]
AVATAR_DIR = args[1]


def clear_scene():
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete()
    for block in bpy.data.meshes:
        if block.users == 0:
            bpy.data.meshes.remove(block)
    for block in bpy.data.materials:
        if block.users == 0:
            bpy.data.materials.remove(block)
    for block in bpy.data.images:
        if block.users == 0:
            bpy.data.images.remove(block)
    for block in bpy.data.armatures:
        if block.users == 0:
            bpy.data.armatures.remove(block)


def import_glb(path):
    bpy.ops.import_scene.gltf(filepath=path)


def find_head(skip_prefix="__donor"):
    """
    Find the head mesh in the scene. Checks for nodes named Wolf3D_Head or Wolf3D_Skin,
    skipping donor objects. Uses vertex count 2000-2300 as a heuristic if name unclear.
    """
    candidates = []
    for obj in bpy.data.objects:
        if obj.type != 'MESH':
            continue
        if obj.name.startswith(skip_prefix):
            continue
        base = obj.name.split(".")[0]
        if base in ("Wolf3D_Head", "Wolf3D_Skin"):
            return obj
        # fallback: face-ish vertex count
        vcount = len(obj.data.vertices)
        if 2000 <= vcount <= 2500:
            candidates.append(obj)

    return candidates[0] if len(candidates) == 1 else None


def load_donor():
    import_glb(DONOR_GLB)
    donor_head = None
    for obj in list(bpy.data.objects):
        if obj.name == DONOR_HEAD_NAME or obj.name.startswith(DONOR_HEAD_NAME + "."):
            donor_head = obj
        safe = f"__donor_{obj.name}__"
        obj.name = safe
        if obj.data:
            obj.data.name = safe
    if not donor_head:
        raise RuntimeError(f"Donor head '{DONOR_HEAD_NAME}' not found in {DONOR_GLB}")
    print(f"[DONOR] {len(donor_head.data.vertices)}v, "
          f"{len(donor_head.data.shape_keys.key_blocks) - 1} shape keys")
    return donor_head


def transfer_shape_keys(donor_obj, target_obj):
    shape_keys = donor_obj.data.shape_keys
    if not shape_keys:
        raise RuntimeError("Donor has no shape keys")

    key_blocks  = shape_keys.key_blocks
    morph_names = [kb.name for kb in key_blocks[1:]]
    print(f"[TRANSFER] {len(morph_names)} shape keys → '{target_obj.name}'")

    if not target_obj.data.shape_keys:
        target_obj.shape_key_add(name="Basis", from_mix=False)

    bpy.ops.object.select_all(action='DESELECT')
    bpy.context.view_layer.objects.active = target_obj
    target_obj.select_set(True)

    mod = target_obj.modifiers.new(name="SurfaceDeformTransfer", type='SURFACE_DEFORM')
    mod.target = donor_obj
    mod.falloff = 4.0

    bpy.ops.object.surfacedeform_bind(modifier=mod.name)
    if not mod.is_bound:
        raise RuntimeError(
            f"Surface Deform failed to bind '{target_obj.name}' "
            f"({len(target_obj.data.vertices)}v) → '{donor_obj.name}' "
            f"({len(donor_obj.data.vertices)}v)"
        )

    print("[BIND] Bound successfully")

    for name in morph_names:
        for kb in key_blocks:
            kb.value = 1.0 if kb.name == name else 0.0
        new_sk = target_obj.shape_key_add(name=name, from_mix=True)
        new_sk.value = 0.0
        print(f"  baked: {name}")

    for kb in key_blocks:
        kb.value = 0.0

    target_obj.modifiers.remove(mod)
    print(f"[TRANSFER] Done")


def main():
    glb_path    = os.path.join(AVATAR_DIR, "character.glb")
    backup_path = os.path.join(AVATAR_DIR, "character.original.glb")

    if not os.path.exists(glb_path):
        print(f"[ERROR] GLB not found: {glb_path}")
        sys.exit(1)

    if not os.path.exists(backup_path):
        shutil.copy2(glb_path, backup_path)
        print(f"[BACKUP] {glb_path} → {backup_path}")

    print(f"\n{'='*60}")
    print(f"[AVATAR {AVATAR_ID}] Processing {glb_path}")

    clear_scene()
    donor_head = load_donor()
    import_glb(glb_path)

    meshes = [o for o in bpy.data.objects if o.type == 'MESH' and not o.name.startswith("__donor")]
    print(f"[DEBUG] Meshes: {[o.name for o in meshes]}")

    target_head = find_head()
    if not target_head:
        print(f"[ERROR] Could not find head mesh. Available: {[o.name for o in meshes]}")
        sys.exit(1)

    print(f"[TARGET] '{target_head.name}' — {len(target_head.data.vertices)}v")

    transfer_shape_keys(donor_head, target_head)

    # Zero all shape keys on non-donor meshes before export
    for obj in bpy.data.objects:
        if obj.name.startswith("__donor"):
            obj.hide_set(True)
            continue
        if obj.type == 'MESH' and obj.data.shape_keys:
            for kb in obj.data.shape_keys.key_blocks:
                kb.value = 0.0

    sk_count = len(target_head.data.shape_keys.key_blocks) - 1 if target_head.data.shape_keys else 0
    print(f"[VERIFY] {sk_count} shape keys on head before export")

    bpy.ops.export_scene.gltf(
        filepath=glb_path,
        export_format='GLB',
        use_visible=True,
        export_apply=False,
        export_morph=True,
        export_morph_normal=False,
        export_morph_tangent=False,
        export_yup=True,
    )

    print(f"[DONE] Avatar {AVATAR_ID}: exported to {glb_path}")
    sys.exit(0)


main()
