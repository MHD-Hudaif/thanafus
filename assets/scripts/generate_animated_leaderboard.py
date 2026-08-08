"""
===============================================================================
Musabaqa 3D Esports Animated Leaderboard Arena Generator for Blender (bpy)
===============================================================================
This script procedurally generates a 3D Islamic Arena, float-animating 
the team podiums, spinning the central trophy emblem, moving cinematic cameras,
and keyframing neon lights for direct WebGL animation in Three.js.

Usage:
  blender-launcher --background --python assets/scripts/generate_animated_leaderboard.py
===============================================================================
"""

import math
import sys
import os

try:
    import bpy
    import bmesh
except ImportError:
    print("Error: This script must be executed inside Blender's Python environment (bpy).")
    sys.exit(1)

# Import animation utilities from same directory
script_dir = os.path.dirname(os.path.abspath(__file__))
if script_dir not in sys.path:
    sys.path.append(script_dir)

import create_blender_animation as anim


# =============================================================================
# 01. SCENE CLEANUP & PBR MATERIALS
# =============================================================================

def reset_scene():
    """Clear all existing objects, materials, and collections."""
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete(use_global=False)
    
    for mat in list(bpy.data.materials):
        bpy.data.materials.remove(mat)
    for mesh in list(bpy.data.meshes):
        bpy.data.meshes.remove(mesh)


def create_pbr_material(name, color=(0.1, 0.1, 0.1, 1.0), metallic=0.0, roughness=0.5, 
                        transmission=0.0, emission_color=(0,0,0), emission_strength=0.0):
    """Creates a PBR material compatible across Blender 3.x and 4.x."""
    mat = bpy.data.materials.new(name=name)
    mat.use_nodes = True
    nodes = mat.node_tree.nodes
    principled = nodes.get("Principled BSDF")
    
    if principled:
        if 'Base Color' in principled.inputs:
            principled.inputs['Base Color'].default_value = color
        if 'Metallic' in principled.inputs:
            principled.inputs['Metallic'].default_value = metallic
        if 'Roughness' in principled.inputs:
            principled.inputs['Roughness'].default_value = roughness
            
        if transmission > 0:
            if 'Transmission' in principled.inputs:
                principled.inputs['Transmission'].default_value = transmission
            elif 'Transmission Weight' in principled.inputs:
                principled.inputs['Transmission Weight'].default_value = transmission
            if 'IOR' in principled.inputs:
                principled.inputs['IOR'].default_value = 1.45
                
        if emission_strength > 0:
            if 'Emission Color' in principled.inputs:
                principled.inputs['Emission Color'].default_value = emission_color + (1.0,)
                principled.inputs['Emission Strength'].default_value = emission_strength
            elif 'Emission' in principled.inputs:
                principled.inputs['Emission'].default_value = emission_color + (1.0,)
                if 'Emission Strength' in principled.inputs:
                    principled.inputs['Emission Strength'].default_value = emission_strength

    return mat


# =============================================================================
# 02. GEOMETRY BUILDERS
# =============================================================================

def build_arena_stage(mat_marble, mat_gold, mat_neon):
    """Build polished dark marble stage floor with gold inlays and arches."""
    # Floor
    bpy.ops.mesh.primitive_cylinder_add(radius=14, depth=0.4, vertices=32, location=(0, 0, -0.2))
    floor = bpy.context.active_object
    floor.name = "Arena_Floor"
    floor.data.materials.append(mat_marble)

    # Outer Gold Trim
    bpy.ops.mesh.primitive_cylinder_add(radius=14.2, depth=0.1, vertices=32, location=(0, 0, -0.05))
    floor_trim = bpy.context.active_object
    floor_trim.name = "Arena_Floor_GoldTrim"
    floor_trim.data.materials.append(mat_gold)

    # Inner Neon Glow Ring
    bpy.ops.mesh.primitive_cylinder_add(radius=10.0, depth=0.08, vertices=32, location=(0, 0, 0.01))
    inner_ring = bpy.context.active_object
    inner_ring.name = "Arena_Inner_NeonRing"
    inner_ring.data.materials.append(mat_neon)

    # Perimeter Pillars
    num_pillars = 10
    radius = 13.5
    for i in range(num_pillars):
        angle = (2 * math.pi / num_pillars) * i
        x = radius * math.cos(angle)
        y = radius * math.sin(angle)
        
        bpy.ops.mesh.primitive_cube_add(size=1.2, location=(x, y, 2.5))
        pillar = bpy.context.active_object
        pillar.scale = (0.6, 0.6, 2.5)
        pillar.name = f"Pillar_{i+1}"
        pillar.data.materials.append(mat_marble)

        bpy.ops.mesh.primitive_cube_add(size=1.4, location=(x, y, 5.2))
        cap = bpy.context.active_object
        cap.scale = (0.7, 0.7, 0.2)
        cap.name = f"Pillar_Cap_{i+1}"
        cap.data.materials.append(mat_gold)


def build_animated_trophy(mat_gold, mat_glass, mat_neon):
    """Build central trophy and animate continuous 360-degree rotation."""
    # Pedestal Base
    bpy.ops.mesh.primitive_cylinder_add(radius=2.0, depth=0.6, vertices=24, location=(0, 0, 0.3))
    pedestal_1 = bpy.context.active_object
    pedestal_1.name = "Pedestal_Base"
    pedestal_1.data.materials.append(mat_gold)

    # Glass Riser
    bpy.ops.mesh.primitive_cylinder_add(radius=1.5, depth=0.8, vertices=24, location=(0, 0, 0.9))
    pedestal_2 = bpy.context.active_object
    pedestal_2.name = "Pedestal_GlassRiser"
    pedestal_2.data.materials.append(mat_glass)

    # Top Plate
    bpy.ops.mesh.primitive_cylinder_add(radius=1.2, depth=0.2, vertices=24, location=(0, 0, 1.4))
    pedestal_3 = bpy.context.active_object
    pedestal_3.name = "Pedestal_TopPlate"
    pedestal_3.data.materials.append(mat_gold)

    # Trophy Cup
    bpy.ops.mesh.primitive_cone_add(radius1=0.8, radius2=0.3, depth=1.2, location=(0, 0, 2.1))
    trophy_cup = bpy.context.active_object
    trophy_cup.name = "Trophy_Cup"
    trophy_cup.data.materials.append(mat_gold)

    # Crescent Moon Emblem (Animated 360 Spin)
    bpy.ops.mesh.primitive_torus_add(major_radius=0.45, minor_radius=0.09, location=(0, 0, 3.1))
    moon = bpy.context.active_object
    moon.rotation_euler = (math.radians(90), 0, 0)
    moon.name = "Trophy_Crescent"
    moon.data.materials.append(mat_neon)

    # ANIMATION 1: Continuous Trophy & Crescent Rotation
    anim.animate_continuous_rotation(trophy_cup, axis='Z', duration_frames=240, rotations=1.0)
    anim.animate_continuous_rotation(moon, axis='Z', duration_frames=240, rotations=2.0)


def build_animated_podiums(mat_glass, mat_gold, mat_neon):
    """Build 4 team podiums with synchronized floating bobbing animations."""
    podium_configs = [
        {"rank": 1, "pos": (0, 4.2, 1.2), "radius": 2.2, "height": 1.2, "name": "Podium_Rank1", "phase": 0.0},
        {"rank": 2, "pos": (-4.5, 2.2, 0.8), "radius": 1.8, "height": 0.8, "name": "Podium_Rank2", "phase": math.pi / 2},
        {"rank": 3, "pos": (4.5, 2.2, 0.6), "radius": 1.8, "height": 0.6, "name": "Podium_Rank3", "phase": math.pi},
        {"rank": 4, "pos": (0, -4.2, 0.4), "radius": 1.6, "height": 0.4, "name": "Podium_Rank4", "phase": (3 * math.pi) / 2},
    ]

    for config in podium_configs:
        x, y, h = config["pos"]
        
        # Crystal Platform Body
        bpy.ops.mesh.primitive_cylinder_add(radius=config["radius"], depth=h, vertices=6, location=(x, y, h / 2.0))
        podium = bpy.context.active_object
        podium.name = config["name"]
        podium.data.materials.append(mat_glass)

        # Neon Glow Ring Cap
        bpy.ops.mesh.primitive_cylinder_add(radius=config["radius"] + 0.05, depth=0.06, vertices=6, location=(x, y, h + 0.03))
        neon_cap = bpy.context.active_object
        neon_cap.name = f"{config['name']}_NeonRing"
        neon_cap.data.materials.append(mat_neon)

        # ANIMATION 2: Gentle Floating Bobbing Animation
        anim.animate_floating_bobbing(podium, delta_z=0.25, cycles=2, duration_frames=240, phase_shift=config["phase"])
        anim.animate_floating_bobbing(neon_cap, delta_z=0.25, cycles=2, duration_frames=240, phase_shift=config["phase"])


def build_backdrop_screen(mat_glass, mat_gold):
    """Build backdrop glass screen for leaderboard mounts."""
    bpy.ops.mesh.primitive_cube_add(size=1.0, location=(0, 7.5, 4.5))
    screen = bpy.context.active_object
    screen.scale = (10.0, 0.1, 4.5)
    screen.name = "Leaderboard_Glass_Screen"
    screen.data.materials.append(mat_glass)

    bpy.ops.mesh.primitive_cube_add(size=1.0, location=(0, 7.5, 4.5))
    frame = bpy.context.active_object
    frame.scale = (10.3, 0.15, 4.7)
    frame.name = "Leaderboard_Gold_Frame"
    frame.data.materials.append(mat_gold)

    # ANIMATION 3: Gentle Floating Motion for Glass Frame
    anim.animate_floating_bobbing(screen, delta_z=0.15, cycles=1, duration_frames=240)
    anim.animate_floating_bobbing(frame, delta_z=0.15, cycles=1, duration_frames=240)


def setup_animated_camera_and_lights():
    """Setup broadcast lighting and animated camera sweep."""
    # Camera
    bpy.ops.object.camera_add(location=(0, -15.5, 6.5), rotation=(math.radians(72), 0, 0))
    cam = bpy.context.active_object
    cam.name = "Cinematic_Camera"
    cam.data.lens = 35
    bpy.context.scene.camera = cam

    # Key Sun Light
    bpy.ops.object.light_add(type='SUN', location=(5, -5, 12))
    key_light = bpy.context.active_object
    key_light.data.energy = 4.5
    key_light.data.color = (0.95, 0.95, 1.0)
    key_light.name = "Key_SunLight"

    # Emerald Rim Light
    bpy.ops.object.light_add(type='SPOT', location=(0, 10, 8))
    rim_light = bpy.context.active_object
    rim_light.rotation_euler = (math.radians(-45), 0, 0)
    rim_light.data.energy = 850.0
    rim_light.data.color = (0.06, 0.72, 0.5)
    rim_light.data.spot_size = math.radians(75)
    rim_light.name = "Rim_SpotLight"

    # ANIMATION 4: Animated Camera Orbit Sweep
    anim.animate_camera_orbit(cam, target_loc=(0, 0, 1.5), radius=15.5, height=6.0, duration_frames=240)


# =============================================================================
# 03. MAIN EXECUTION & GLB EXPORT
# =============================================================================

def main():
    print("=========================================================")
    print("[Musabaqa 3D] Generating Animated Leaderboard Scene...")
    print("=========================================================")

    anim.set_scene_timeline(start_frame=1, end_frame=240, fps=30)
    reset_scene()

    mat_marble = create_pbr_material("Mat_DarkMarble", color=(0.02, 0.03, 0.06, 1.0), metallic=0.1, roughness=0.12)
    mat_gold   = create_pbr_material("Mat_EsportsGold", color=(0.95, 0.75, 0.2, 1.0), metallic=0.95, roughness=0.18)
    mat_glass  = create_pbr_material("Mat_CrystalGlass", color=(0.9, 0.95, 1.0, 1.0), transmission=0.92, roughness=0.08)
    mat_neon   = create_pbr_material("Mat_EmeraldNeon", color=(0.06, 0.72, 0.5, 1.0), emission_color=(0.06, 0.72, 0.5), emission_strength=12.0)

    build_arena_stage(mat_marble, mat_gold, mat_neon)
    build_animated_trophy(mat_gold, mat_glass, mat_neon)
    build_animated_podiums(mat_glass, mat_gold, mat_neon)
    build_backdrop_screen(mat_glass, mat_gold)
    setup_animated_camera_and_lights()

    # Output paths
    web_assets_dir = os.path.abspath(os.path.join(script_dir, ".."))
    out_glb_1 = os.path.join(web_assets_dir, "musabaqa_arena_animated.glb")
    out_glb_2 = os.path.join(web_assets_dir, "musabaqa_arena_leaderboard.glb")
    out_glb_3 = os.path.join(web_assets_dir, "Untitled.glb")

    print(f"[Musabaqa 3D] Exporting GLB animations to:\n  - {out_glb_1}\n  - {out_glb_2}\n  - {out_glb_3}")
    
    success = anim.export_animated_glb(out_glb_1)
    if success:
        anim.export_animated_glb(out_glb_2)
        anim.export_animated_glb(out_glb_3)
        print("=========================================================")
        print("[Musabaqa 3D] Animated 3D Leaderboard build completed!")
        print("=========================================================")


if __name__ == "__main__":
    main()
