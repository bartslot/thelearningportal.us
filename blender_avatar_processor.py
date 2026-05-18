#!/usr/bin/env python3
"""
Blender Avatar Processor
- Imports Mixamo FBX
- Adds 52 ARKit shape keys
- Exports to GLB with metadata
- For The Learning Portal
"""

import bpy
import os
import json
import uuid
from pathlib import Path

# ============================================================================
# CONFIGURATION
# ============================================================================

PROJECT_ROOT = "/Users/bartslot/Projects/thelearningportal.us"
AVATARS_DIR = os.path.join(PROJECT_ROOT, "public/avatars")
ARKIT_52 = [
    "aa", "ch", "dd", "ee", "ff", "gg", "ih", "jj", "kk", "ll", "mm", "nn",
    "oh", "ooh", "pp", "rr", "ss", "tt", "th",
    "viseme_PP", "viseme_FF", "viseme_TH", "viseme_DD", "viseme_kk", "viseme_nn",
    "viseme_sil", "viseme_aa", "viseme_ee", "viseme_ih", "viseme_oh", "viseme_ou",
    "viseme_er", "viseme_ah", "viseme_uh", "viseme_ao", "viseme_ae",
    "jawOpen", "jawLeft", "jawRight", "jawForward", "mouthDimple_L", "mouthDimple_R",
    "mouthStretch_L", "mouthStretch_R", "mouthSmile_L", "mouthSmile_R",
    "mouthFrown_L", "mouthFrown_R", "mouthPucker", "mouthRollUpper", "mouthRollLower",
    "tongueOut", "eyeBlink_L", "eyeBlink_R"
]

# ============================================================================
# FUNCTIONS
# ============================================================================

def generate_avatar_id():
    """Generate a unique avatar ID"""
    return str(uuid.uuid4())

def add_arkit_shapekeys(obj):
    """Add 52 ARKit shape keys to a mesh object"""
    if obj.type != 'MESH':
        return False, "Object is not a mesh"

    mesh = obj.data

    # Create basis if needed
    if mesh.shape_keys is None:
        obj.shape_key_add(name="Basis", from_mix=False)

    created = 0
    for shape_name in ARKIT_52:
        if shape_name not in mesh.shape_keys.key_blocks:
            obj.shape_key_add(name=shape_name, from_mix=False)
            created += 1

    return True, f"Added {created} shape keys (52 total)"

def add_avatar_metadata(obj, avatar_id, name, age, gender):
    """Add metadata to object for GLB export"""
    obj["avatar_id"] = avatar_id
    obj["character_name"] = name
    obj["character_age"] = int(age)
    obj["character_gender"] = gender

    # Also add to scene custom properties (some exporters read these)
    bpy.context.scene["avatar_meta"] = {
        "id": avatar_id,
        "name": name,
        "age": age,
        "gender": gender
    }

def export_to_glb(filepath, obj, avatar_id, name, age, gender):
    """Export object to GLB with metadata"""
    # Ensure directory exists
    os.makedirs(os.path.dirname(filepath), exist_ok=True)

    # Select the object for export
    bpy.context.view_layer.objects.active = obj
    obj.select_set(True)

    # Add metadata as custom properties
    add_avatar_metadata(obj, avatar_id, name, age, gender)

    # Export as GLB (glTF 2.0 binary)
    bpy.ops.export_scene.gltf(
        filepath=filepath,
        use_selection=True,
        export_format='GLB',
        export_draco_mesh_compression_level=6,
        export_animations=True,
        export_frame_range=True,
    )

    # Write metadata.json alongside GLB
    metadata = {
        "id": avatar_id,
        "name": name,
        "age": int(age),
        "gender": gender,
        "glb_file": "character.glb",
        "shape_keys_count": 52,
        "shape_keys": ARKIT_52
    }

    metadata_path = os.path.join(os.path.dirname(filepath), "metadata.json")
    with open(metadata_path, 'w') as f:
        json.dump(metadata, f, indent=2)

    return True

def process_avatar(fbx_path, character_name, character_age, character_gender):
    """
    Main function: Import FBX, add shape keys, export GLB

    Args:
        fbx_path (str): Path to Mixamo FBX file
        character_name (str): Character name for metadata
        character_age (int): Character age
        character_gender (str): Character gender (M/F/Other)

    Returns:
        tuple: (success, avatar_id, output_path, message)
    """

    # Validate input
    if not os.path.exists(fbx_path):
        return False, None, None, f"FBX file not found: {fbx_path}"

    # Clear scene
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete()

    # Import FBX
    try:
        bpy.ops.import_scene.fbx(filepath=fbx_path)
        print(f"✓ Imported FBX: {fbx_path}")
    except Exception as e:
        return False, None, None, f"Failed to import FBX: {str(e)}"

    # Find the mesh (usually the armature or character object)
    mesh_obj = None
    for obj in bpy.context.scene.objects:
        if obj.type == 'MESH':
            mesh_obj = obj
            break

    if mesh_obj is None:
        return False, None, None, "No mesh found in FBX"

    # Add shape keys
    success, msg = add_arkit_shapekeys(mesh_obj)
    if not success:
        return False, None, None, f"Failed to add shape keys: {msg}"
    print(f"✓ {msg}")

    # Generate avatar ID and output path
    avatar_id = generate_avatar_id()
    output_dir = os.path.join(AVATARS_DIR, avatar_id)
    output_path = os.path.join(output_dir, "character.glb")

    # Export GLB
    try:
        export_to_glb(output_path, mesh_obj, avatar_id, character_name, character_age, character_gender)
        print(f"✓ Exported to GLB: {output_path}")
    except Exception as e:
        return False, None, None, f"Failed to export GLB: {str(e)}"

    message = f"""
✅ Avatar Created Successfully!
   ID: {avatar_id}
   Name: {character_name}
   Age: {character_age}
   Gender: {character_gender}
   Output: {output_path}
   Metadata: {os.path.join(output_dir, 'metadata.json')}
   Shape Keys: 52 ARKit blend shapes
    """

    return True, avatar_id, output_path, message

# ============================================================================
# MAIN EXECUTION
# ============================================================================

if __name__ == "__main__":
    # Example usage (modify these for your character):
    FBX_FILE = "/path/to/mixamo_character.fbx"
    CHARACTER_NAME = "Julius Caesar"
    CHARACTER_AGE = 62
    CHARACTER_GENDER = "M"

    success, avatar_id, output_path, message = process_avatar(
        FBX_FILE,
        CHARACTER_NAME,
        CHARACTER_AGE,
        CHARACTER_GENDER
    )

    print(message)

    if not success:
        print(f"ERROR: {message}")
