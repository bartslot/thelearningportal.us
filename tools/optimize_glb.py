"""
Shrink a rigged, animated GLB down to something a map indicator can stream.

Run headless:
  Blender --background --python optimize_glb.py -- <in.glb> <out.glb> <max_tris> <tex_px> [keep_anim_substr]

The two source models fail in different ways, so both levers are exposed:
  - horse_walking.glb is GEOMETRY-bound (237k triangles, 0.7 MB of textures)
  - camel.glb is TEXTURE-bound (26k triangles, 7.0 MB of PNGs)

Decimation uses COLLAPSE and is applied per-mesh, preserving the armature and its
vertex groups so the walk cycle still deforms the mesh after the cut.
"""
import math
import sys
import bpy
import mathutils

argv = sys.argv[sys.argv.index("--") + 1:]
src, dst, max_tris, tex_px = argv[0], argv[1], int(argv[2]), int(argv[3])
keep_anim = argv[4] if len(argv) > 4 else None
drop = [d for d in (argv[5].split(",") if len(argv) > 5 and argv[5] else []) if d]

bpy.ops.wm.read_factory_settings(use_empty=True)
bpy.ops.import_scene.gltf(filepath=src)

# Drop meshes that cost geometry but add nothing at map scale. On the horse this is Object_8:
# 95k of the model's 159k triangles, made of ~18k disconnected hair cards that neither weld nor
# collapse (it floored at 35k). Rendering the model with and without it produced two identical
# images, so it is pure download weight for a marker drawn ~46px wide.
for name in drop:
    o = bpy.data.objects.get(name)
    if o:
        bpy.data.objects.remove(o, do_unlink=True)

meshes = [o for o in bpy.data.objects if o.type == "MESH"]
before_tris = sum(len(o.data.loop_triangles) if o.data.loop_triangles else
                  sum(max(0, len(p.vertices) - 2) for p in o.data.polygons) for o in meshes)

# ── Geometry ──────────────────────────────────────────────────────────────────
# Ratio is computed across the WHOLE model so parts keep their relative density —
# decimating each mesh to the same ratio would flatten a small head next to a big body.
def tri_count(o):
    return sum(max(0, len(p.vertices) - 2) for p in o.data.polygons)


def weld(o):
    """Merge vertices the glTF importer split apart.

    glTF stores one vertex per unique (position, normal, UV) combination, so importing splits the
    mesh along every seam and hard edge. Decimate-collapse cannot merge across those duplicates,
    which is why asking for ratio 0.05 on the horse stalled at 0.50 — the modifier had nothing
    left it was allowed to join. Welding first restores a connected surface and the ratio lands.
    """
    bpy.ops.object.select_all(action="DESELECT")
    o.select_set(True)
    bpy.context.view_layer.objects.active = o
    bpy.ops.object.mode_set(mode="EDIT")
    bpy.ops.mesh.select_all(action="SELECT")
    bpy.ops.mesh.remove_doubles(threshold=1e-4)
    bpy.ops.object.mode_set(mode="OBJECT")


# Collapse refuses to cross UV seams and sharp edges, so a single pass stalls well short of an
# aggressive target (asking 0.028 on the horse landed at 0.35). Re-running re-evaluates the new
# topology and gets further; the loop stops as soon as a pass stops paying, so a mesh that has
# genuinely bottomed out never gets ground into holes.
for o in meshes:
    if tri_count(o) >= 200:
        weld(o)

for _ in range(8):
    total = sum(tri_count(o) for o in meshes)
    if total <= max_tris:
        break
    ratio = max(0.05, max_tris / float(total))
    moved = False
    for o in meshes:
        tri = tri_count(o)
        if tri < 200:              # already too coarse to survive a cut
            continue
        mod = o.modifiers.new(name="lp", type="DECIMATE")
        mod.decimate_type = "COLLAPSE"
        mod.ratio = ratio
        mod.use_collapse_triangulate = True
        bpy.context.view_layer.objects.active = o
        bpy.ops.object.modifier_apply(modifier=mod.name)
        if tri_count(o) < tri:
            moved = True
    if not moved:
        break

# ── Material channels ─────────────────────────────────────────────────────────
# The voyage map lights models with one ambient + one directional light and draws them ~46px wide.
# Normal, roughness, metallic and AO maps are physically invisible at that size but carried most of
# the camel's 7 MB. Keep Base Color, drop the rest; the exporter then omits the orphaned images.
if "--basecolor-only" in argv:
    for mat in bpy.data.materials:
        if not mat.use_nodes:
            continue
        bsdf = next((n for n in mat.node_tree.nodes if n.type == "BSDF_PRINCIPLED"), None)
        if not bsdf:
            continue
        for inp in bsdf.inputs:
            if inp.name == "Base Color":
                continue
            for link in list(inp.links):
                mat.node_tree.links.remove(link)
    # Normal maps hang off a Normal Map node, not the BSDF directly — sweep the leftovers.
    for mat in bpy.data.materials:
        if not mat.use_nodes:
            continue
        for node in list(mat.node_tree.nodes):
            if node.type in {"NORMAL_MAP", "BUMP"} or (node.type == "TEX_IMAGE" and not node.outputs[0].links):
                mat.node_tree.nodes.remove(node)

# ── Alpha ─────────────────────────────────────────────────────────────────────
# The glTF importer marks every material alpha-HASHED whether or not it uses cutout, and the
# exporter then has to write PNG for all of them. Only genuinely transparent materials need it —
# on the camel that is the shaggy coat (CamelHair), which a render test showed does change the
# silhouette, so it stays. The rest go opaque and export as JPEG.
keep_alpha = ""
for a in argv:
    if a.startswith("--alpha="):
        keep_alpha = a.split("=", 1)[1]
alpha_names = {n for n in keep_alpha.split(",") if n}
alpha_images = set()
for mat in bpy.data.materials:
    if mat.name not in alpha_names:
        if hasattr(mat, "blend_method"):
            mat.blend_method = "OPAQUE"
        continue
    if mat.use_nodes:                     # remember which images must stay PNG
        for n in mat.node_tree.nodes:
            if n.type == "TEX_IMAGE" and n.image:
                alpha_images.add(n.image.name)

# blend_method alone does not move the exporter — it writes PNG whenever the IMAGE carries an
# alpha channel. Dropping alpha on the images no material needs it for is what actually lets
# them out as JPEG.
for img in bpy.data.images:
    if img.name not in alpha_images:
        img.alpha_mode = "NONE"

# ── Textures ──────────────────────────────────────────────────────────────────
# A model drawn ~46px wide never resolves a 2k map. Scaling in place keeps every
# material binding intact; the exporter then packs the smaller pixels.
for img in bpy.data.images:
    if img.size[0] > tex_px or img.size[1] > tex_px:
        img.scale(min(tex_px, img.size[0]), min(tex_px, img.size[1]))

# ── Animation ─────────────────────────────────────────────────────────────────
# Idle clips are dead weight for a model that only ever walks along a route.
if keep_anim:
    wanted = [w.strip().lower() for w in keep_anim.split("|") if w.strip()]
    for act in list(bpy.data.actions):
        if not any(w in act.name.lower() for w in wanted):
            bpy.data.actions.remove(act)

# ── Origin ────────────────────────────────────────────────────────────────────
# Park the whole rig on the origin: centred in X/Y, feet exactly on Z=0 (Blender is Z-up; the
# exporter flips to Y-up).
#
# Source rigs are authored wherever the artist left them — the camel's mesh coordinates sit ~158
# units off. The renderer cannot reliably measure that back out at load time: Box3.setFromObject
# ignores skinning, SkinnedMesh.computeBoundingBox needs bone matrices the renderer has not filled
# in yet, and bone positions include stray root/IK bones that drag the centre. Normalising the ASSET
# makes every model behave the same, so the map can place them all with one simple rule.
# Facing. Models are authored nose-first along whichever axis the artist chose, and the map assumes
# one convention for all of them. The camel arrived facing the opposite way from the horse and
# moonwalked along its route. Rotating the rig here keeps the renderer free of per-model special
# cases: --yaw=180 turns the whole animal about the up axis (Blender is Z-up) before export.
yaw_deg = 0.0
for a in argv:
    if a.startswith("--yaw="):
        yaw_deg = float(a.split("=", 1)[1])
if yaw_deg:
    rot = mathutils.Matrix.Rotation(math.radians(yaw_deg), 4, "Z")
    for o in bpy.data.objects:
        if o.parent is None:
            o.matrix_world = rot @ o.matrix_world
    bpy.ops.object.select_all(action="DESELECT")
    _arms = [o for o in bpy.data.objects if o.type == "ARMATURE"]
    for a_obj in _arms:
        a_obj.select_set(True)
        bpy.context.view_layer.objects.active = a_obj
    if _arms:
        bpy.ops.object.transform_apply(location=False, rotation=True, scale=False)

mesh_objs = [o for o in bpy.data.objects if o.type == "MESH"]
if mesh_objs:
    xs, ys, zs = [], [], []
    for o in mesh_objs:
        for corner in o.bound_box:
            w = o.matrix_world @ mathutils.Vector(corner)
            xs.append(w.x); ys.append(w.y); zs.append(w.z)
    offset = mathutils.Vector((
        -(min(xs) + max(xs)) / 2.0,
        -(min(ys) + max(ys)) / 2.0,
        -min(zs),
    ))

    # glTF IGNORES a skinned mesh's own node transform — the skin's joints place it. So nudging the
    # mesh object does nothing on export; the offset has to land on the armature and then be baked
    # into the bone rest positions, which is what the joint nodes actually carry.
    for o in bpy.data.objects:
        if o.parent is None:
            o.location = o.location + offset

    bpy.ops.object.select_all(action="DESELECT")
    armatures = [o for o in bpy.data.objects if o.type == "ARMATURE"]
    for a in armatures:
        a.select_set(True)
        bpy.context.view_layer.objects.active = a
    if armatures:
        bpy.ops.object.transform_apply(location=True, rotation=False, scale=False)

after_tris = 0
for o in [x for x in bpy.data.objects if x.type == "MESH"]:
    after_tris += sum(max(0, len(p.vertices) - 2) for p in o.data.polygons)

export_kwargs = dict(
    filepath=dst,
    export_format="GLB",
    export_animations=True,
    export_skins=True,
    export_morph=False,          # no shape keys in either source
    export_apply=True,
    export_cameras=False,
    export_lights=False,
    export_extras=False,
    export_yup=True,
    # Keyframes, not textures, are the bulk once the meshes are cut: the camel rig alone is 124
    # bones, each with a channel per frame. Sampling every 2nd frame and dropping keys that repeat
    # the previous value halves that, and a walk cycle looping at 46px shows no difference.
    export_frame_step=2,
    export_optimize_animation_size=True,
)
# Forced JPEG is only safe where nothing relies on cutout alpha — the caller decides.
export_kwargs["export_image_format"] = "JPEG" if "--jpeg" in argv else "AUTO"

bpy.ops.export_scene.gltf(**export_kwargs)

print(f"RESULT tris {before_tris} -> {after_tris} | actions kept: "
      f"{[a.name for a in bpy.data.actions]}")
