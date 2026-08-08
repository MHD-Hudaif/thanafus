"""
===============================================================================
Musabaqa 3D Esports Leaderboard Arena Generator for Blender (bpy)
===============================================================================
This script procedurally generates a production-grade 3D Islamic Arena, 
floating glass team podiums, central trophy pedestal, cinematic lighting, 
and exports the scene directly to a optimized .glb file for Three.js / WebGL.

Usage:
  blender --background --python generate_leaderboard_scene.py
  OR run inside Blender's Scripting workspace editor.
===============================================================================
"""

import math
import sys
import os

try:
    import bpy
    import bmesh
except ImportError:
    print("Error: This script must be run inside Blender's Python environment (bpy).")
    sys.exit(1)


# =============================================================================
# 01. UTILITIES & MATERIAL BUILDER
# =============================================================================

def reset_scene():
    """Clear all existing objects, materials, and collections."""
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete(use_global=False)
    
    # Remove orphan materials and meshes
    for mat in bpy.data.materials:
        bpy.data.materials.remove(mat)
    for mesh in bpy.data.meshes:
        bpy.data.meshes.remove(mesh)

def create_material(name, color=(0.1, 0.1, 0.1, 1.0), metallic=0.0, roughness=0.5, 
                    transmission=0.0, emission_color=(0,0,0), emission_strength=0.0):
    """Helper to create a PBR material compatible across Blender 3.x and 4.x."""
    mat = bpy.data.materials.new(name=name)
    mat.use_nodes = True
    nodes = mat.node_tree.nodes
    principled = nodes.get("Principled BSDF")
    
    if principled:
        # Base Color
        if 'Base Color' in principled.inputs:
            principled.inputs['Base Color'].default_value = color
            
        # Metallic & Roughness
        if 'Metallic' in principled.inputs:
            principled.inputs['Metallic'].default_value = metallic
        if 'Roughness' in principled.inputs:
            principled.inputs['Roughness'].default_value = roughness
            
        # Glass / Transmission
        if transmission > 0:
            if 'Transmission' in principled.inputs: # Blender 3.x
                principled.inputs['Transmission'].default_value = transmission
            elif 'Transmission Weight' in principled.inputs: # Blender 4.x
                principled.inputs['Transmission Weight'].default_value = transmission
            if 'IOR' in principled.inputs:
                principled.inputs['IOR'].default_value = 1.45
                
        # Emission
        if emission_strength > 0:
            if 'Emission Color' in principled.inputs: # Blender 4.x
                principled.inputs['Emission Color'].default_value = emission_color + (1.0,)
                principled.inputs['Emission Strength'].default_value = emission_strength
            elif 'Emission' in principled.inputs: # Blender 3.x
                principled.inputs['Emission'].default_value = emission_color + (1.0,)
                if 'Emission Strength' in principled.inputs:
                    principled.inputs['Emission Strength'].default_value = emission_strength

    return mat


# =============================================================================
# 02. ARENA GEOMETRY (Floor, Pillars, Archways)
# =============================================================================

def build_arena(mat_marble, mat_gold, mat_neon):
    """Build polished dark marble stage floor with gold inlays and arches."""
    # Main Stage Platform
    bpy.ops.mesh.primitive_cylinder_add(radius=14, depth=0.4, vertices=32, location=(0, 0, -0.2))
    floor = bpy.context.active_object
    floor.name = "Arena_Floor"
    floor.data.materials.append(mat_marble)

    # Gold Inlay Trim
    bpy.ops.mesh.primitive_cylinder_add(radius=14.2, depth=0.1, vertices=32, location=(0, 0, -0.05))
    floor_trim = bpy.context.active_object
    floor_trim.name = "Arena_Floor_GoldTrim"
    floor_trim.data.materials.append(mat_gold)

    # Inner Emerald Ring
    bpy.ops.mesh.primitive_cylinder_add(radius=10.0, depth=0.08, vertices=32, location=(0, 0, 0.01))
    inner_ring = bpy.context.active_object
    inner_ring.name = "Arena_Inner_NeonRing"
    inner_ring.data.materials.append(mat_neon)

    # Perimeter Pillars & Arches
    num_pillars = 10
    radius_pillar_circle = 13.5
    for i in range(num_pillars):
        angle = (2 * math.pi / num_pillars) * i
        x = radius_pillar_circle * math.cos(angle)
        y = radius_pillar_circle * math.sin(angle)
        
        # Pillar Base
        bpy.ops.mesh.primitive_cube_add(size=1.2, location=(x, y, 2.5))
        pillar = bpy.context.active_object
        pillar.scale = (0.6, 0.6, 2.5)
        pillar.name = f"Pillar_{i+1}"
        pillar.data.materials.append(mat_marble)

        # Pillar Capital Trim
        bpy.ops.mesh.primitive_cube_add(size=1.4, location=(x, y, 5.2))
        cap = bpy.context.active_object
        cap.scale = (0.7, 0.7, 0.2)
        cap.name = f"Pillar_Cap_{i+1}"
        cap.data.materials.append(mat_gold)


# =============================================================================
# 03. TROPHY & CENTRAL PEDESTAL
# =============================================================================

def build_trophy(mat_gold, mat_glass, mat_neon):
    """Build central golden trophy on a tiered pedestal."""
    # Tier 1 Pedestal Base
    bpy.ops.mesh.primitive_cylinder_add(radius=2.0, depth=0.6, vertices=24, location=(0, 0, 0.3))
    pedestal_1 = bpy.context.active_object
    pedestal_1.name = "Pedestal_Base"
    pedestal_1.data.materials.append(mat_gold)

    # Tier 2 Glass Riser
    bpy.ops.mesh.primitive_cylinder_add(radius=1.5, depth=0.8, vertices=24, location=(0, 0, 0.9))
    pedestal_2 = bpy.context.active_object
    pedestal_2.name = "Pedestal_GlassRiser"
    pedestal_2.data.materials.append(mat_glass)

    # Tier 3 Top Plate
    bpy.ops.mesh.primitive_cylinder_add(radius=1.2, depth=0.2, vertices=24, location=(0, 0, 1.4))
    pedestal_3 = bpy.context.active_object
    pedestal_3.name = "Pedestal_TopPlate"
    pedestal_3.data.materials.append(mat_gold)

    # Trophy Base Cup
    bpy.ops.mesh.primitive_cone_add(radius1=0.8, radius2=0.3, depth=1.2, location=(0, 0, 2.1))
    trophy_cup = bpy.context.active_object
    trophy_cup.name = "Trophy_Cup"
    trophy_cup.data.materials.append(mat_gold)

    # Crescent Moon Emblem Top
    bpy.ops.mesh.primitive_torus_add(major_radius=0.4, minor_radius=0.08, location=(0, 0, 3.1))
    moon = bpy.context.active_object
    moon.rotation_euler = (math.radians(90), 0, 0)
    moon.name = "Trophy_Crescent"
    moon.data.materials.append(mat_neon)


# =============================================================================
# 04. TEAM PODIUM PLATFORMS (Ranks 1 to 4)
# =============================================================================

def build_podiums(mat_glass, mat_gold, mat_neon):
    """Build 4 floating crystal platforms for top ranking teams."""
    podium_configs = [
        {"rank": 1, "pos": (0, 4.2, 1.2), "radius": 2.2, "height": 1.2, "name": "Podium_Rank1"},
        {"rank": 2, "pos": (-4.5, 2.2, 0.8), "radius": 1.8, "height": 0.8, "name": "Podium_Rank2"},
        {"rank": 3, "pos": (4.5, 2.2, 0.6), "radius": 1.8, "height": 0.6, "name": "Podium_Rank3"},
        {"rank": 4, "pos": (0, -4.2, 0.4), "radius": 1.6, "height": 0.4, "name": "Podium_Rank4"},
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


# =============================================================================
# 05. FLOATING LEADERBOARD GLASS PANELS
# =============================================================================

def build_leaderboard_panels(mat_glass, mat_gold):
    """Build curved floating glass backdrop panels for score mounts."""
    # Main Floating Glass Screen
    bpy.ops.mesh.primitive_cube_add(size=1.0, location=(0, 7.5, 4.5))
    screen = bpy.context.active_object
    screen.scale = (10.0, 0.1, 4.5)
    screen.name = "Leaderboard_Glass_Screen"
    screen.data.materials.append(mat_glass)

    # Golden Outer Frame
    bpy.ops.mesh.primitive_cube_add(size=1.0, location=(0, 7.5, 4.5))
    frame = bpy.context.active_object
    frame.scale = (10.3, 0.15, 4.7)
    frame.name = "Leaderboard_Gold_Frame"
    frame.data.materials.append(mat_gold)


# =============================================================================
# 06. LIGHTING & CAMERA SETUP
# =============================================================================

def setup_lighting_and_camera():
    """Create cinematic broadcast lighting and camera angles."""
    # Camera
    bpy.ops.object.camera_add(location=(0, -15.5, 6.5), rotation=(math.radians(72), 0, 0))
    cam = bpy.context.active_object
    cam.name = "Cinematic_Camera"
    cam.data.lens = 35 # 35mm Wide Broadcast Lens
    bpy.context.scene.camera = cam

    # Sun Light (Key Light)
    bpy.ops.object.light_add(type='SUN', location=(5, -5, 12))
    key_light = bpy.context.active_object
    key_light.data.energy = 4.5
    key_light.data.color = (0.95, 0.95, 1.0)
    key_light.name = "Key_SunLight"

    # Emerald Rim Light (Backlight)
    bpy.ops.object.light_add(type='SPOT', location=(0, 10, 8))
    rim_light = bpy.context.active_object
    rim_light.rotation_euler = (math.radians(-45), 0, 0)
    rim_light.data.energy = 850.0
    rim_light.data.color = (0.06, 0.72, 0.5) # Emerald Green
    rim_light.data.spot_size = math.radians(75)
    rim_light.name = "Rim_SpotLight"

    # Center Trophy Spotlight
    bpy.ops.object.light_add(type='SPOT', location=(0, 0, 8))
    trophy_spot = bpy.context.active_object
    trophy_spot.data.energy = 1200.0
    trophy_spot.data.color = (1.0, 0.9, 0.7) # Warm Gold
    trophy_spot.data.spot_size = math.radians(35)
    trophy_spot.name = "Trophy_SpotLight"


# =============================================================================
# 07. MAIN EXECUTION & GLTF EXPORT
# =============================================================================

def main():
    print("[Musabaqa 3D] Clearing existing scene...")
    reset_scene()

    print("[Musabaqa 3D] Creating PBR Materials...")
    mat_marble = create_material("Mat_DarkMarble", color=(0.02, 0.03, 0.06, 1.0), metallic=0.1, roughness=0.12)
    mat_gold   = create_material("Mat_EsportsGold", color=(0.95, 0.75, 0.2, 1.0), metallic=0.95, roughness=0.18)
    mat_glass  = create_material("Mat_CrystalGlass", color=(0.9, 0.95, 1.0, 1.0), transmission=0.92, roughness=0.08)
    mat_neon   = create_material("Mat_EmeraldNeon", color=(0.06, 0.72, 0.5, 1.0), emission_color=(0.06, 0.72, 0.5), emission_strength=12.0)

    print("[Musabaqa 3D] Generating Arena Geometry...")
    build_arena(mat_marble, mat_gold, mat_neon)

    print("[Musabaqa 3D] Generating Central Trophy & Pedestal...")
    build_trophy(mat_gold, mat_glass, mat_neon)

    print("[Musabaqa 3D] Generating 4 Floating Team Podiums...")
    build_podiums(mat_glass, mat_gold, mat_neon)

    print("[Musabaqa 3D] Building Floating Glass Leaderboard Panels...")
    build_leaderboard_panels(mat_glass, mat_gold)

    print("[Musabaqa 3D] Setting up Camera & Lighting...")
    setup_lighting_and_camera()

    # GLB Export Path
    output_dir = os.path.dirname(os.path.abspath(__file__))
    output_glb = os.path.join(output_dir, "musabaqa_arena_leaderboard.glb")

    print(f"[Musabaqa 3D] Exporting scene to GLB: {output_glb}")
    try:
        if hasattr(bpy.ops.wm, "gltf_export"):
            bpy.ops.wm.gltf_export(filepath=output_glb, export_format='GLB')
        else:
            bpy.ops.export_scene.gltf(filepath=output_glb, export_format='GLB', export_apply=True)
        print("[Musabaqa 3D] Export completed successfully!")
    except Exception as e:
        print(f"[Musabaqa 3D] GLTF export error: {e}")

if __name__ == "__main__":
    main()
