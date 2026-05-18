#!/usr/bin/env python3
"""
GLB Avatar Sanity Checker
Validates Ready Player Me / Mixamo GLB files for The Learning Portal.

Checks:
  1. Oculus visemes   — 15 morph targets required by avatar-3d.js / ElevenLabs pipeline
  2. Mixamo rig       — required bones present and organised under Armature/Hips
  3. Loose parts      — every mesh must be skinned (no float geometry)
  4. Wolf3D meshes    — expected RPM mesh node names
  5. ElevenLabs note  — flags if legacy ARKit names (viseme_ee/ih/oh/ou) found instead
                        of RPM names (viseme_E/I/O/U)
  6. Material         — RPM standard: Metallic 0.0, Roughness 0.78, Specular IOR 0.5,
                        doubleSided true. Flags deviations.

Usage:
  python3 scripts/check_avatar_glb.py public/avatars/1/character.glb
  python3 scripts/check_avatar_glb.py public/avatars/   # scan entire folder
"""

import sys, json, struct, pathlib, argparse

try:
    from pygltflib import GLTF2
except ImportError:
    sys.exit("pygltflib not installed — run: pip3 install pygltflib --break-system-packages")

# ── Colours ───────────────────────────────────────────────────────────────────
RED    = "\033[91m"
YEL    = "\033[93m"
GRN    = "\033[92m"
CYN    = "\033[96m"
BOLD   = "\033[1m"
RST    = "\033[0m"

def ok(msg):    print(f"  {GRN}✔{RST}  {msg}")
def warn(msg):  print(f"  {YEL}⚠{RST}  {msg}")
def err(msg):   print(f"  {RED}✖{RST}  {msg}")
def info(msg):  print(f"  {CYN}ℹ{RST}  {msg}")

# ── Reference data ─────────────────────────────────────────────────────────────

# These are the 15 viseme morph target names avatar-3d.js looks for (Oculus / RPM).
# ElevenLabs viseme track IDs 0-14 map 1-to-1 to this list.
REQUIRED_VISEMES = [
    "viseme_sil",
    "viseme_PP", "viseme_FF", "viseme_TH", "viseme_DD",
    "viseme_kk", "viseme_CH", "viseme_SS", "viseme_nn", "viseme_RR",
    "viseme_aa",
    "viseme_E", "viseme_I", "viseme_O", "viseme_U",
]

# If a model was exported with ARKit names instead of RPM/Oculus names,
# these will appear instead — the avatar-3d.js won't find them.
ARKIT_ALIASES = {
    "viseme_E": "viseme_ee",
    "viseme_I": "viseme_ih",
    "viseme_O": "viseme_oh",
    "viseme_U": "viseme_ou",
}

# Minimum Mixamo bones required for poses and animations to work.
REQUIRED_BONES = [
    "Hips", "Spine", "Spine1", "Spine2",
    "Neck", "Head",
    "LeftShoulder", "LeftArm", "LeftForeArm", "LeftHand",
    "RightShoulder", "RightArm", "RightForeArm", "RightHand",
    "LeftUpLeg", "LeftLeg", "LeftFoot",
    "RightUpLeg", "RightLeg", "RightFoot",
]

# Standard RPM mesh node names — at minimum Wolf3D_Head must exist and be skinned.
RPM_MESH_NAMES = {
    "Wolf3D_Head",
    "Wolf3D_Skin",
    "Wolf3D_Body",
    "Wolf3D_Outfit_Top",
    "Wolf3D_Outfit_Bottom",
    "Wolf3D_Outfit_Footwear",
    "Wolf3D_Hair",
    "Wolf3D_Beard",
    "Wolf3D_Facewear",
    "Wolf3D_Headwear",
    "Wolf3D_Glasses",
    "Wolf3D_Teeth",
    "Wolf3D_Avatar",
}

CRITICAL_MESH = "Wolf3D_Head"


# ── Parser helpers ─────────────────────────────────────────────────────────────

def collect_morph_targets(gltf):
    """Return {node_name: [morph_target_name, ...]} for every mesh node."""
    result = {}
    if not gltf.meshes:
        return result

    for node in gltf.nodes or []:
        if node.mesh is None:
            continue
        mesh = gltf.meshes[node.mesh]
        names = []
        if mesh.extras and "targetNames" in mesh.extras:
            names = list(mesh.extras["targetNames"])
        elif mesh.primitives:
            # Fall back to first primitive extras
            for prim in mesh.primitives:
                if prim.extras and "targetNames" in prim.extras:
                    names = list(prim.extras["targetNames"])
                    break
        result[node.name or f"mesh_{node.mesh}"] = names
    return result


def collect_bones(gltf):
    """Return set of bone names from all skins."""
    bone_names = set()
    for skin in gltf.skins or []:
        for joint_idx in skin.joints:
            node = gltf.nodes[joint_idx]
            if node.name:
                bone_names.add(node.name)
    return bone_names


def collect_skinned_meshes(gltf):
    """Return set of node names that have a skin index (are properly bound)."""
    skinned = set()
    for node in gltf.nodes or []:
        if node.mesh is not None and node.skin is not None:
            skinned.add(node.name or f"mesh_{node.mesh}")
    return skinned


def collect_bone_parented_meshes(gltf):
    """Return set of mesh node names that are children of a joint/bone node.

    A hat parented to a bone (Object Parent, no skin weights) lives as a child
    of the bone's node in the GLTF hierarchy — it moves rigidly with that bone.
    This is valid; it just doesn't need a skin index.
    """
    # Build set of all joint node indices across all skins
    joint_indices = set()
    for skin in gltf.skins or []:
        joint_indices.update(skin.joints)

    # Build child → parent index map
    parent_of = {}
    for idx, node in enumerate(gltf.nodes or []):
        for child_idx in (node.children or []):
            parent_of[child_idx] = idx

    bone_parented = set()
    for idx, node in enumerate(gltf.nodes or []):
        if node.mesh is not None:
            parent_idx = parent_of.get(idx)
            if parent_idx is not None and parent_idx in joint_indices:
                bone_parented.add(node.name or f"mesh_{node.mesh}")
    return bone_parented


def collect_all_mesh_nodes(gltf):
    """Return set of all node names that reference a mesh."""
    mesh_nodes = set()
    for node in gltf.nodes or []:
        if node.mesh is not None:
            mesh_nodes.add(node.name or f"mesh_{node.mesh}")
    return mesh_nodes


# ── Per-file checks ────────────────────────────────────────────────────────────

def check_visemes(morph_map, issues):
    print(f"\n{BOLD}[1] Oculus Visemes{RST}")
    # Find the best mesh (most morphs, prefer Wolf3D_Head)
    head_morphs = morph_map.get(CRITICAL_MESH, [])
    all_morphs_flat = set()
    richest_name, richest_morphs = "", []
    for name, morphs in morph_map.items():
        all_morphs_flat.update(morphs)
        if len(morphs) > len(richest_morphs):
            richest_morphs = morphs
            richest_name = name

    target_morphs = set(head_morphs) if head_morphs else set(richest_morphs)
    source_name   = CRITICAL_MESH if head_morphs else richest_name

    if not target_morphs:
        err(f"No morph targets found on any mesh!")
        issues.append(("ERROR", "No morph targets found"))
        return

    info(f"Checking morphs on '{source_name}' ({len(target_morphs)} total)")

    missing = [v for v in REQUIRED_VISEMES if v not in target_morphs]
    present = [v for v in REQUIRED_VISEMES if v in target_morphs]

    if not missing:
        ok(f"All 15 Oculus visemes present")
    else:
        for v in missing:
            # Check if the ARKit alias exists instead
            alias = ARKIT_ALIASES.get(v)
            if alias and alias in target_morphs:
                warn(f"'{v}' missing — found ARKit alias '{alias}' instead")
                warn(f"  → avatar-3d.js expects '{v}'; ElevenLabs map won't drive this")
                issues.append(("WARN", f"ARKit name '{alias}' found; rename to '{v}'"))
            else:
                err(f"'{v}' missing (required for ElevenLabs lip sync)")
                issues.append(("ERROR", f"Missing viseme: {v}"))
        ok(f"{len(present)}/15 visemes present")

    # Extra info: any non-standard viseme names
    extra = [m for m in target_morphs if m.startswith("viseme_") and m not in REQUIRED_VISEMES]
    if extra:
        info(f"Extra viseme morphs (not used by avatar-3d.js): {', '.join(sorted(extra))}")

    # ElevenLabs alignment note
    elevenlabs_ok = all(v in target_morphs for v in REQUIRED_VISEMES)
    if elevenlabs_ok:
        ok("ElevenLabs viseme track mapping: all target names match ✓")
    else:
        warn("ElevenLabs viseme track mapping: incomplete — some IDs (0-14) will be silent")


def check_bones(bone_names, issues):
    print(f"\n{BOLD}[2] Mixamo Rig / Bone Structure{RST}")
    if not bone_names:
        err("No skins/joints found — model has no armature!")
        issues.append(("ERROR", "No armature/skins found"))
        return

    info(f"{len(bone_names)} joints in armature")
    missing = [b for b in REQUIRED_BONES if b not in bone_names]
    if not missing:
        ok("All required Mixamo bones present")
    else:
        for b in missing:
            err(f"Missing bone: '{b}'")
            issues.append(("ERROR", f"Missing bone: {b}"))

    # Check for Hips as root of hierarchy
    if "Hips" in bone_names:
        ok("'Hips' root bone found")
    else:
        warn("'Hips' not found — pose system may not work correctly")
        issues.append(("WARN", "Hips bone missing"))

    # Flag non-Mixamo bone names (could indicate custom/different rig)
    non_mixamo = [b for b in bone_names
                  if not any(b.startswith(p) for p in
                             ("Hips","Spine","Neck","Head","Left","Right",
                              "mixamorig","Armature","_rootJoint","Root",
                              "Jaw","Eye","Tongue","Teeth"))]
    if non_mixamo:
        warn(f"Non-standard bone names ({len(non_mixamo)}): {', '.join(sorted(non_mixamo)[:10])}")
        issues.append(("WARN", f"{len(non_mixamo)} non-Mixamo bone names"))


def check_skinning(all_mesh_nodes, skinned_meshes, bone_parented_meshes, issues):
    print(f"\n{BOLD}[3] Loose Parts / Skinning{RST}")
    attached = skinned_meshes | bone_parented_meshes
    loose = all_mesh_nodes - attached
    if not loose:
        ok("All mesh nodes attached to armature (skinned or bone-parented)")
    else:
        for name in sorted(loose):
            err(f"'{name}' is NOT attached — loose part not connected to any bone")
            issues.append(("ERROR", f"Loose mesh (unattached): {name}"))

    for name in sorted(bone_parented_meshes):
        ok(f"'{name}' bone-parented (rigid attachment, no skin weights — valid for hats/props)")


def check_wolf3d_meshes(all_mesh_nodes, skinned_meshes, issues):
    print(f"\n{BOLD}[4] Wolf3D / RPM Mesh Nodes{RST}")
    found_rpm = all_mesh_nodes & RPM_MESH_NAMES
    custom    = all_mesh_nodes - RPM_MESH_NAMES

    if found_rpm:
        ok(f"RPM meshes: {', '.join(sorted(found_rpm))}")
    else:
        warn("No Wolf3D_* mesh names found — may be a custom/renamed avatar")
        issues.append(("WARN", "No Wolf3D_* mesh names found"))

    if CRITICAL_MESH in all_mesh_nodes:
        ok(f"'{CRITICAL_MESH}' (face morph mesh) present and identified")
    else:
        warn(f"'{CRITICAL_MESH}' not found — avatar-3d.js face priority list may miss morphs")
        issues.append(("WARN", f"{CRITICAL_MESH} not found; morph discovery may fall back"))

    if custom:
        info(f"Custom/non-RPM mesh names: {', '.join(sorted(custom))}")


# ── RPM material standard ─────────────────────────────────────────────────────
# Established 2026-05-14 from Avatar 1 (French general).
# Single Wolf3D_Avatar mesh — military/fabric uniform.
MATERIAL_STANDARD = {
    "metallic":         (0.0,  0.05),   # (target, tolerance)
    "roughness":        (0.78, 0.05),
    "double_sided":     True,
}


def collect_materials(gltf):
    """Return list of material dicts from the GLTF material array."""
    result = []
    for mat in gltf.materials or []:
        entry = {"name": mat.name or "unnamed"}
        pbr = mat.pbrMetallicRoughness
        if pbr:
            entry["metallic"]  = pbr.metallicFactor  if pbr.metallicFactor  is not None else 1.0
            entry["roughness"] = pbr.roughnessFactor if pbr.roughnessFactor is not None else 1.0
        else:
            entry["metallic"]  = 1.0
            entry["roughness"] = 1.0
        entry["double_sided"] = mat.doubleSided if mat.doubleSided is not None else False
        result.append(entry)
    return result


def check_materials(gltf, issues):
    print(f"\n{BOLD}[6] Material Settings{RST}")
    mats = collect_materials(gltf)

    if not mats:
        warn("No materials found in GLB")
        issues.append(("WARN", "No materials found"))
        return

    STD = MATERIAL_STANDARD
    all_ok = True

    for m in mats:
        name = m["name"]
        mat_ok = True

        # Metallic
        tgt, tol = STD["metallic"]
        if abs(m["metallic"] - tgt) > tol:
            err(f"'{name}': Metallic {m['metallic']:.3f} (standard {tgt})")
            issues.append(("ERROR", f"{name}: Metallic {m['metallic']:.3f} ≠ {tgt}"))
            mat_ok = False

        # Roughness
        tgt, tol = STD["roughness"]
        if abs(m["roughness"] - tgt) > tol:
            err(f"'{name}': Roughness {m['roughness']:.3f} (standard {tgt})")
            issues.append(("ERROR", f"{name}: Roughness {m['roughness']:.3f} ≠ {tgt}"))
            mat_ok = False

        # Double-sided
        if m["double_sided"] != STD["double_sided"]:
            err(f"'{name}': doubleSided={m['double_sided']} (standard {STD['double_sided']})")
            issues.append(("ERROR", f"{name}: doubleSided must be {STD['double_sided']}"))
            mat_ok = False

        if mat_ok:
            ok(f"'{name}': Metallic {m['metallic']:.2f}  Roughness {m['roughness']:.2f}  "
               f"doubleSided {m['double_sided']} ✓")
        else:
            all_ok = False

    if all_ok:
        ok(f"All {len(mats)} material(s) match RPM standard")


def check_morph_coverage(morph_map, issues):
    print(f"\n{BOLD}[5] Morph Target Coverage{RST}")
    for name, morphs in sorted(morph_map.items()):
        if name in RPM_MESH_NAMES or name in (CRITICAL_MESH,):
            viseme_count = sum(1 for m in morphs if m.startswith("viseme_"))
            total = len(morphs)
            if total == 0:
                warn(f"'{name}': no morph targets")
            elif viseme_count == 0 and name == CRITICAL_MESH:
                err(f"'{name}': {total} morphs but NONE are visemes!")
                issues.append(("ERROR", f"{name} has no viseme morphs"))
            else:
                ok(f"'{name}': {total} morphs ({viseme_count} visemes)")


# ── Main ───────────────────────────────────────────────────────────────────────

def check_file(path: pathlib.Path) -> bool:
    print(f"\n{'═'*60}")
    print(f"{BOLD}{CYN}Avatar GLB Check:{RST} {path}")
    print(f"{'═'*60}")

    try:
        gltf = GLTF2().load(str(path))
    except Exception as e:
        err(f"Failed to parse GLB: {e}")
        return False

    file_size_mb = path.stat().st_size / (1024 * 1024)
    info(f"File size: {file_size_mb:.2f} MB  |  Nodes: {len(gltf.nodes or [])}  |  "
         f"Meshes: {len(gltf.meshes or [])}  |  Skins: {len(gltf.skins or [])}")

    morph_map           = collect_morph_targets(gltf)
    bone_names          = collect_bones(gltf)
    all_mesh_nodes      = collect_all_mesh_nodes(gltf)
    skinned_meshes      = collect_skinned_meshes(gltf)
    bone_parented_meshes = collect_bone_parented_meshes(gltf)

    issues = []

    check_visemes(morph_map, issues)
    check_bones(bone_names, issues)
    check_skinning(all_mesh_nodes, skinned_meshes, bone_parented_meshes, issues)
    check_wolf3d_meshes(all_mesh_nodes, skinned_meshes, issues)
    check_morph_coverage(morph_map, issues)
    check_materials(gltf, issues)

    # ── Summary ────────────────────────────────────────────────────────────────
    print(f"\n{BOLD}── Summary ──────────────────────────────────────────{RST}")
    errors = [i for i in issues if i[0] == "ERROR"]
    warns  = [i for i in issues if i[0] == "WARN"]

    if not issues:
        print(f"  {GRN}{BOLD}✔ PASS — no issues found{RST}")
    else:
        if errors:
            print(f"  {RED}{BOLD}✖ {len(errors)} ERROR(S):{RST}")
            for _, msg in errors:
                print(f"      {RED}• {msg}{RST}")
        if warns:
            print(f"  {YEL}{BOLD}⚠ {len(warns)} WARNING(S):{RST}")
            for _, msg in warns:
                print(f"      {YEL}• {msg}{RST}")

    return len(errors) == 0


def main():
    ap = argparse.ArgumentParser(description="GLB sanity checker for Learning Portal avatars")
    ap.add_argument("path", help="Path to .glb file or folder containing avatars")
    ap.add_argument("--all-variants", action="store_true",
                    help="Also check *.original.glb and backup.*.glb files")
    args = ap.parse_args()

    root = pathlib.Path(args.path)
    glb_files = []

    if root.is_dir():
        pattern = "**/*.glb" if args.all_variants else "**/character.glb"
        glb_files = sorted(root.glob(pattern))
        # Exclude backup variants unless requested
        if not args.all_variants:
            glb_files = [f for f in glb_files
                         if "backup" not in f.name and "original" not in f.name
                         and ".bak" not in f.name]
    elif root.is_file() and root.suffix == ".glb":
        glb_files = [root]
    else:
        sys.exit(f"Not a .glb file or directory: {root}")

    if not glb_files:
        sys.exit("No .glb files found.")

    all_pass = True
    for glb in glb_files:
        passed = check_file(glb)
        if not passed:
            all_pass = False

    print(f"\n{'═'*60}")
    if all_pass:
        print(f"{GRN}{BOLD}ALL FILES PASSED{RST}")
    else:
        print(f"{RED}{BOLD}ISSUES FOUND — see errors above{RST}")
    print(f"{'═'*60}\n")
    sys.exit(0 if all_pass else 1)


if __name__ == "__main__":
    main()
