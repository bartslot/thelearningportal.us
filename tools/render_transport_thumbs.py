"""
Render a square thumbnail of a transport model for the wizard's picker.

Run headless:
  Blender --background --python render_transport_thumbs.py -- <in.glb|in.fbx> <out.png> [px] [yaw_deg]

The picker shows the teacher which model they are choosing, so the thumbnail has to be the
ACTUAL model rather than a hand-drawn icon that drifts the moment a model is swapped. Rendering
it here keeps the two in step: change the GLB, re-run this, the tile updates.

Workbench (not Cycles/EEVEE) is deliberate: these are silhouettes on a dark tile, and Workbench
renders one in well under a second with no light setup and no denoiser.
"""
import math
import sys

import bpy
import mathutils

argv = sys.argv[sys.argv.index("--") + 1:]
src, dst = argv[0], argv[1]
px = int(argv[2]) if len(argv) > 2 else 192
yaw = math.radians(float(argv[3])) if len(argv) > 3 else math.radians(35.0)

bpy.ops.wm.read_factory_settings(use_empty=True)

# The FBX importer writes Cycles-specific light properties as it builds each imported light, and
# on Blender 5.x that raises (`CyclesLightSettings has no attribute cast_shadow`) — the whole
# import fails on a model whose lights we do not want anyway. Workbench needs no light data, so
# turn Cycles off and the importer takes a path that does not touch it.
for module in ("cycles", "bl_ext.blender_org.cycles"):
    try:
        bpy.ops.preferences.addon_disable(module=module)
    except Exception:  # noqa: BLE001 - absent module, nothing to disable
        pass

if src.lower().endswith(".fbx"):
    bpy.ops.import_scene.fbx(filepath=src)
else:
    bpy.ops.import_scene.gltf(filepath=src)

# The rig's rest pose is what we want framed — an imported walk cycle would otherwise render
# whatever frame 1 happens to be mid-stride, which reads as a stumble at thumbnail size.
for obj in bpy.data.objects:
    if obj.type == "ARMATURE":
        obj.animation_data_clear()

meshes = [o for o in bpy.data.objects if o.type == "MESH"]
if not meshes:
    raise SystemExit(f"no mesh in {src}")

# Bounds over every mesh's world-space corners. Good enough for framing: these models arrive
# normalised (feet on y=0, centred) from optimize_glb.py, and the ship's FBX is origin-built.
lo = mathutils.Vector((1e9, 1e9, 1e9))
hi = mathutils.Vector((-1e9, -1e9, -1e9))
for o in meshes:
    for corner in o.bound_box:
        p = o.matrix_world @ mathutils.Vector(corner)
        lo = mathutils.Vector((min(lo[i], p[i]) for i in range(3)))
        hi = mathutils.Vector((max(hi[i], p[i]) for i in range(3)))
centre = (lo + hi) / 2
extent = max((hi - lo)[i] for i in range(3)) or 1.0

# Three-quarter view: readable as a horse/camel/ship rather than the ambiguous flat side-on.
# Orthographic so the tile has no perspective distortion at this size.
cam_data = bpy.data.cameras.new("thumb")
cam_data.type = "ORTHO"
cam_data.ortho_scale = extent * 1.35
cam = bpy.data.objects.new("thumb", cam_data)
bpy.context.scene.collection.objects.link(cam)

direction = mathutils.Vector((math.sin(yaw), -math.cos(yaw), 0.42)).normalized()
cam.location = centre + direction * (extent * 3)
cam.rotation_euler = (-direction).to_track_quat("-Z", "Y").to_euler()
bpy.context.scene.camera = cam

scene = bpy.context.scene
scene.render.engine = "BLENDER_WORKBENCH"
scene.render.film_transparent = True
scene.render.resolution_x = px
scene.render.resolution_y = px
scene.render.image_settings.file_format = "PNG"
scene.render.image_settings.color_mode = "RGBA"
scene.render.filepath = dst

shading = scene.display.shading
shading.light = "STUDIO"
shading.color_type = "TEXTURE"
shading.show_shadows = False
shading.show_cavity = True

bpy.ops.render.render(write_still=True)
print(f"[thumb] {src} -> {dst} ({px}px)")
