"""
transfer_morphs.py — Blender batch morph transfer for RPM avatars

Run from terminal:
  /Applications/Blender.app/Contents/MacOS/Blender --background --python transfer_morphs.py

What it does:
  1. Imports donor GLB (Wolf3D_Head, 2162v, 72 morphs)
  2. For each target avatar GLB:
     a. Imports the avatar
     b. Finds the head mesh (Wolf3D_Head or Wolf3D_Skin, 2123v)
     c. Binds a Surface Deform modifier from target head → donor head
     d. Bakes each of the 72 shape keys onto the target head
     e. Exports the avatar GLB back (overwriting in-place, original backed up once)
     f. Clears scene for next avatar
"""

import bpy
import os
import shutil

DONOR_GLB  = "/Users/bartslot/Downloads/Avatars/ready-player-me-avatar/source/617b091cfb622cf1cd9cc537.glb"
AVATARS_DIR = "/Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us/public/avatars"

# Avatars with Wolf3D_Head or Wolf3D_Skin at 2123v needing morph transfer
AVATAR_IDS = [
    ("5",  "Wolf3D_Skin"),
    ("9",  "Wolf3D_Skin"),
    ("12", "Wolf3D_Skin"),
    ("13", "Wolf3D_Skin"),
    ("15", "Wolf3D_Skin"),
    ("17", "Wolf3D_Skin"),
    ("18", "Wolf3D_Skin"),
    ("19", "Wolf3D_Skin"),
    ("20", "Wolf3D_Skin"),
    ("21", "Wolf3D_Skin"),
]

# Donor head mesh name
DONOR_HEAD_NAME = "Wolf3D_Head"

def clear_scene():
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete()
    # Remove orphaned data
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
    """Find object by name, trying multiple options. Skips donor objects."""
    for name in name_options:
        for obj in bpy.data.objects:
            if obj.type != 'MESH':
                continue
            if obj.name.startswith("__donor"):
                continue
            base = obj.name.split(".")[0]  # strip Blender's .001 suffix
            if base == name:
                return obj
    return None


def load_donor():
    """Import donor and return the head mesh object."""
    import_glb(DONOR_GLB)
    # Rename ALL objects from the donor import so none can collide with target names
    donor_head = None
    for obj in bpy.data.objects:
        safe = f"__donor_{obj.name}__"
        if obj.name == DONOR_HEAD_NAME or obj.name.startswith(DONOR_HEAD_NAME + "."):
            donor_head = obj
        obj.name = safe
        if obj.data:
            obj.data.name = safe
    if not donor_head:
        raise RuntimeError(f"Could not find donor head mesh '{DONOR_HEAD_NAME}' in {DONOR_GLB}")
    print(f"[DONOR] Found head with {len(donor_head.data.vertices)} verts, "
          f"{len(donor_head.data.shape_keys.key_blocks) - 1} shape keys")
    return donor_head


def transfer_shape_keys(donor_obj, target_obj):
    """
    Transfer all shape keys from donor to target using Surface Deform.
    Donor and target must be in the same scene.
    """
    shape_keys = donor_obj.data.shape_keys
    if not shape_keys:
        raise RuntimeError("Donor has no shape keys")

    key_blocks = shape_keys.key_blocks
    # Skip index 0 (Basis)
    morph_names = [kb.name for kb in key_blocks[1:]]
    print(f"[TRANSFER] Transferring {len(morph_names)} shape keys to '{target_obj.name}'")

    # Ensure target has a Basis shape key
    if not target_obj.data.shape_keys:
        target_obj.shape_key_add(name="Basis", from_mix=False)

    # Add Surface Deform modifier to target
    bpy.context.view_layer.objects.active = target_obj
    target_obj.select_set(True)

    mod = target_obj.modifiers.new(name="SurfaceDeformTransfer", type='SURFACE_DEFORM')
    mod.target = donor_obj
    mod.falloff = 4.0

    # Bind
    bpy.ops.object.surfacedeform_bind(modifier=mod.name)
    if not mod.is_bound:
        raise RuntimeError(f"Surface Deform failed to bind '{target_obj.name}' → '{donor_obj.name}'. "
                           "Meshes may be too different.")

    for name in morph_names:
        # Set donor shape key value to 1, all others 0
        for kb in key_blocks:
            kb.value = 1.0 if kb.name == name else 0.0

        # Add new shape key to target by applying current deformation
        new_sk = target_obj.shape_key_add(name=name, from_mix=True)
        new_sk.value = 0.0  # reset after bake
        print(f"  baked: {name}")

    # Reset donor shape keys
    for kb in key_blocks:
        kb.value = 0.0

    # Remove modifier
    target_obj.modifiers.remove(mod)


def process_avatar(avatar_id, head_mesh_name):
    glb_path = os.path.join(AVATARS_DIR, avatar_id, "character.glb")
    backup_path = os.path.join(AVATARS_DIR, avatar_id, "character.original.glb")

    if not os.path.exists(glb_path):
        print(f"[SKIP] Avatar {avatar_id}: file not found")
        return

    # Back up original once
    if not os.path.exists(backup_path):
        shutil.copy2(glb_path, backup_path)
        print(f"[BACKUP] {glb_path} → {backup_path}")

    print(f"\n{'='*60}")
    print(f"[AVATAR {avatar_id}] Processing {glb_path}")

    clear_scene()

    # Import donor first, then target
    donor_head = load_donor()

    import_glb(glb_path)

    target_head = find_obj([head_mesh_name])
    if not target_head:
        print(f"[SKIP] Avatar {avatar_id}: could not find head mesh '{head_mesh_name}'")
        return

    print(f"[TARGET] Found '{target_head.name}' with {len(target_head.data.vertices)} verts")

    try:
        transfer_shape_keys(donor_head, target_head)
    except RuntimeError as e:
        print(f"[ERROR] Avatar {avatar_id}: {e}")
        return

    # Zero out all shape key values on every non-donor mesh to prevent flicker on load
    for obj in bpy.data.objects:
        if obj.name.startswith("__donor"):
            obj.hide_set(True)
            continue
        if obj.type == 'MESH' and obj.data.shape_keys:
            for kb in obj.data.shape_keys.key_blocks:
                kb.value = 0.0

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
    print("\n" + "="*60)
    print("RPM Morph Transfer — starting batch")
    print(f"Donor: {DONOR_GLB}")
    print(f"Targets: {len(AVATAR_IDS)} avatars")
    print("="*60)

    for avatar_id, head_mesh_name in AVATAR_IDS:
        try:
            process_avatar(avatar_id, head_mesh_name)
        except Exception as e:
            print(f"[FATAL] Avatar {avatar_id}: {e}")
            import traceback
            traceback.print_exc()

    print("\n" + "="*60)
    print("Batch complete.")


main()
