"""
transfer_morphs_skin.py — transfer morphs to Wolf3D_Skin avatars

Run:
  /Applications/Blender.app/Contents/MacOS/Blender --background --python transfer_morphs_skin.py
"""

import bpy
import os
import shutil

DONOR_GLB   = "/Users/bartslot/Downloads/Avatars/ready-player-me-avatar/source/617b091cfb622cf1cd9cc537.glb"
AVATARS_DIR = "/Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us/public/avatars"

AVATAR_IDS = [
    # (avatar_id, node_name_in_blender, mesh_name_in_glb)
    # Blender uses node name as object name, not mesh name
    ("5",  "Wolf3D_Head", "Wolf3D_Skin"),
    ("9",  "Wolf3D_Head", "Wolf3D_Skin"),
    ("12", "Wolf3D_Head", "Wolf3D_Skin"),
    ("13", "Wolf3D_Head", "Wolf3D_Skin"),
    ("15", "Wolf3D_Head", "Wolf3D_Skin"),
    ("17", "Wolf3D_Head", "Wolf3D_Skin"),
    ("18", "Wolf3D_Head", "Wolf3D_Skin"),
    ("19", "Wolf3D_Head", "Wolf3D_Skin"),
    ("20", "Wolf3D_Head", "Wolf3D_Skin"),
    ("21", "Wolf3D_Head", "Wolf3D_Skin"),
]

DONOR_HEAD_NAME = "Wolf3D_Head"


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


def find_obj(name_options):
    for name in name_options:
        for obj in bpy.data.objects:
            if obj.type != 'MESH':
                continue
            if obj.name.startswith("__donor"):
                continue
            base = obj.name.split(".")[0]
            if base == name:
                return obj
    return None


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
        raise RuntimeError(f"Could not find donor head '{DONOR_HEAD_NAME}'")
    print(f"[DONOR] {len(donor_head.data.vertices)} verts, "
          f"{len(donor_head.data.shape_keys.key_blocks) - 1} shape keys")
    return donor_head


def transfer_shape_keys(donor_obj, target_obj):
    shape_keys = donor_obj.data.shape_keys
    if not shape_keys:
        raise RuntimeError("Donor has no shape keys")

    key_blocks = shape_keys.key_blocks
    morph_names = [kb.name for kb in key_blocks[1:]]
    print(f"[TRANSFER] {len(morph_names)} shape keys → '{target_obj.name}'")

    if not target_obj.data.shape_keys:
        target_obj.shape_key_add(name="Basis", from_mix=False)

    # Make target active
    bpy.ops.object.select_all(action='DESELECT')
    bpy.context.view_layer.objects.active = target_obj
    target_obj.select_set(True)

    mod = target_obj.modifiers.new(name="SurfaceDeformTransfer", type='SURFACE_DEFORM')
    mod.target = donor_obj
    mod.falloff = 4.0

    bpy.ops.object.surfacedeform_bind(modifier=mod.name)
    if not mod.is_bound:
        raise RuntimeError(
            f"Surface Deform FAILED to bind '{target_obj.name}' ({len(target_obj.data.vertices)}v) "
            f"→ '{donor_obj.name}' ({len(donor_obj.data.vertices)}v)"
        )

    print(f"[BIND] Surface Deform bound successfully")

    for name in morph_names:
        for kb in key_blocks:
            kb.value = 1.0 if kb.name == name else 0.0
        new_sk = target_obj.shape_key_add(name=name, from_mix=True)
        new_sk.value = 0.0
        print(f"  baked: {name}")

    for kb in key_blocks:
        kb.value = 0.0

    target_obj.modifiers.remove(mod)
    print(f"[TRANSFER] Done — {len(morph_names)} keys baked")


def process_avatar(avatar_id, head_node_name, head_mesh_name=None):
    glb_path    = os.path.join(AVATARS_DIR, avatar_id, "character.glb")
    backup_path = os.path.join(AVATARS_DIR, avatar_id, "character.original.glb")

    if not os.path.exists(glb_path):
        print(f"[SKIP] Avatar {avatar_id}: file not found")
        return

    # Restore from backup so we always start clean
    if os.path.exists(backup_path):
        shutil.copy2(backup_path, glb_path)
        print(f"[RESTORE] Restored from backup for avatar {avatar_id}")
    else:
        shutil.copy2(glb_path, backup_path)
        print(f"[BACKUP] {glb_path} → {backup_path}")

    print(f"\n{'='*60}")
    print(f"[AVATAR {avatar_id}] Processing {glb_path}")

    clear_scene()

    donor_head = load_donor()

    import_glb(glb_path)

    # List all mesh objects for debugging
    meshes = [o for o in bpy.data.objects if o.type == 'MESH' and not o.name.startswith("__donor")]
    print(f"[DEBUG] Meshes in scene after import: {[o.name for o in meshes]}")

    # Blender object name = node name in GLTF (not mesh name)
    search_names = [head_node_name]
    if head_mesh_name and head_mesh_name != head_node_name:
        search_names.append(head_mesh_name)
    target_head = find_obj(search_names)
    if not target_head:
        print(f"[SKIP] Avatar {avatar_id}: could not find '{head_node_name}' (or '{head_mesh_name}'). "
              f"Available: {[o.name for o in meshes]}")
        return

    print(f"[TARGET] '{target_head.name}' — {len(target_head.data.vertices)} verts")

    try:
        transfer_shape_keys(donor_head, target_head)
    except RuntimeError as e:
        print(f"[ERROR] Avatar {avatar_id}: {e}")
        return

    # Zero all non-donor shape keys before export
    for obj in bpy.data.objects:
        if obj.name.startswith("__donor"):
            obj.hide_set(True)
            continue
        if obj.type == 'MESH' and obj.data.shape_keys:
            for kb in obj.data.shape_keys.key_blocks:
                kb.value = 0.0

    # Verify morph count before export
    sk_count = len(target_head.data.shape_keys.key_blocks) - 1 if target_head.data.shape_keys else 0
    print(f"[VERIFY] Target head now has {sk_count} shape keys before export")

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

    print(f"[DONE] Avatar {avatar_id}: exported to {glb_path}")


def main():
    print("\n" + "=" * 60)
    print("RPM Morph Transfer — Wolf3D_Skin batch")
    print(f"Donor: {DONOR_GLB}")
    print(f"Targets: {len(AVATAR_IDS)} avatars")
    print("=" * 60)

    for avatar_id, head_node_name, head_mesh_name in AVATAR_IDS:
        try:
            process_avatar(avatar_id, head_node_name, head_mesh_name)
        except Exception as e:
            print(f"[FATAL] Avatar {avatar_id}: {e}")
            import traceback
            traceback.print_exc()

    print("\n" + "=" * 60)
    print("Batch complete.")


main()
