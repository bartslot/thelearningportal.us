"""
Author a subtle standing "Idle" loop onto a rigged GLB that only ships a walk cycle.

Run headless:
  Blender --background --python add_idle_action.py -- <in.glb> <out.glb> [seconds] [fps]

The horse model arrived with a single "Walking" clip, so a traveller parked at a landfall either
marched on the spot or froze solid. Neither reads as an animal standing still. This builds a real
idle instead: breathing through the spine, a slow head settle and a lazy tail swish, keyed from the
REST pose so all four hooves stay planted (keying from a walk frame leaves the legs mid-stride).

The loop is closed — the last keyframe repeats the first — so it cycles without a hitch.
"""
import math
import sys

import bpy
from mathutils import Euler

argv = sys.argv[sys.argv.index("--") + 1:]
src, dst = argv[0], argv[1]
seconds = float(argv[2]) if len(argv) > 2 else 4.0
fps = int(argv[3]) if len(argv) > 3 else 24

bpy.ops.wm.read_factory_settings(use_empty=True)
bpy.ops.import_scene.gltf(filepath=src)

scene = bpy.context.scene
scene.render.fps = fps
total = int(seconds * fps)

arm = next((o for o in bpy.data.objects if o.type == "ARMATURE"), None)
if arm is None:
    raise SystemExit("no armature in " + src)

bpy.ops.object.select_all(action="DESELECT")
arm.select_set(True)
bpy.context.view_layer.objects.active = arm

# Start from rest, NOT from whatever the walk action left on the bones.
if arm.animation_data:
    arm.animation_data.action = None
bpy.ops.object.mode_set(mode="POSE")
bpy.ops.pose.select_all(action="SELECT")
bpy.ops.pose.transforms_clear()

idle = bpy.data.actions.new("Idle")
if arm.animation_data is None:
    arm.animation_data_create()
arm.animation_data.action = idle


def pick(*fragments):
    """Pose bones whose name contains any fragment (CAT rigs use CATRigSpine3_04 and friends)."""
    out = []
    for b in arm.pose.bones:
        low = b.name.lower()
        if any(f in low for f in fragments):
            out.append(b)
    return out


spine = pick("spine", "chest")
head = pick("head")
neck = pick("neck")
tail = pick("tail")

# Amplitudes are TOTALS for the whole chain, in radians, and get divided across the bones in it.
#
# Spine bones are chained, so rotating each by the same angle compounds: the horse's 10 spine bones
# at ~1° each added up to ~11° of body bend, which read as a boat rocking rather than an animal
# breathing. Budget the total instead, and weight it toward the chest so the ribcage lifts while
# the hindquarters stay planted.
BREATH_TOTAL = math.radians(1.6)
HEAD_TOTAL = math.radians(2.6)
TAIL_TOTAL = math.radians(9.0)
SWAY_TOTAL = math.radians(0.7)   # slow weight shift, the giveaway that something is alive


def key(bone, frame, euler_xyz):
    rot = Euler(euler_xyz, "XYZ")
    if bone.rotation_mode == "QUATERNION":
        bone.rotation_quaternion = rot.to_quaternion()
        bone.keyframe_insert("rotation_quaternion", frame=frame)
    else:
        bone.rotation_euler = rot
        bone.keyframe_insert("rotation_euler", frame=frame)


# Sample the loop at eighths; Blender's bezier interpolation smooths between them.
steps = 8
for i in range(steps + 1):
    frame = 1 + round(i * total / steps)
    phase = (i / steps) * 2 * math.pi

    # Breathing: the budgeted total, spread over the chain and biased toward the chest so the
    # ribcage does the work. Ramp runs 0 at the hips to 1 at the withers.
    if spine:
        n = len(spine)
        for idx, b in enumerate(spine):
            bias = (idx + 1) / n
            share = (BREATH_TOTAL / n) * 2.0 * bias
            key(b, frame, (math.sin(phase) * share, 0.0, 0.0))

    # Head settles a beat behind the breath, and on its own slower rhythm, so it reads as an
    # animal glancing about rather than nodding in time with its own ribs.
    if neck + head:
        chain = neck + head
        share = HEAD_TOTAL / len(chain)
        nod = math.sin(phase * 0.5 - 0.7) * share
        turn = math.sin(phase * 0.33) * share * 0.6
        for b in chain:
            key(b, frame, (nod, turn, 0.0))

    # Tail on a third rhythm, so nothing in the body pulses in unison.
    if tail:
        share = TAIL_TOTAL / len(tail)
        swish = math.sin(phase * 0.75) * share
        for b in tail:
            key(b, frame, (0.0, 0.0, swish))

    # A very slow lateral weight shift across the whole spine. Tiny, but it stops the horse
    # looking like a statue that happens to breathe.
    if spine:
        sway = math.sin(phase * 0.25) * (SWAY_TOTAL / len(spine))
        for idx, b in enumerate(spine):
            rot = Euler((0.0, sway, 0.0), "XYZ")
            if b.rotation_mode == "QUATERNION":
                b.rotation_quaternion = b.rotation_quaternion @ rot.to_quaternion()
                b.keyframe_insert("rotation_quaternion", frame=frame)
            else:
                b.rotation_euler.y += sway
                b.keyframe_insert("rotation_euler", frame=frame)

bpy.ops.object.mode_set(mode="OBJECT")

# Keep the walk too: the map cross-fades between them.
bpy.ops.export_scene.gltf(
    filepath=dst,
    export_format="GLB",
    export_animations=True,
    export_animation_mode="ACTIONS",
    export_skins=True,
    export_morph=False,
    export_apply=False,
    export_cameras=False,
    export_lights=False,
    export_extras=False,
    export_yup=True,
    export_frame_step=2,
    export_optimize_animation_size=True,
)
print("RESULT actions:", [a.name for a in bpy.data.actions])
