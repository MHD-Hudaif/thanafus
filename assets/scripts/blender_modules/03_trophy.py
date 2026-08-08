"""
Module 03: Trophy & Pedestal Assembly
Musabaqa 3D Esports Leaderboard
"""
import bpy
import math

def build_trophy():
    mat_gold = bpy.data.materials.get("Mat_EsportsGold") or bpy.data.materials.new("Mat_EsportsGold")
    mat_glass = bpy.data.materials.get("Mat_CrystalGlass") or bpy.data.materials.new("Mat_CrystalGlass")
    mat_neon = bpy.data.materials.get("Mat_EmeraldNeon") or bpy.data.materials.new("Mat_EmeraldNeon")

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

    # Crescent Moon Emblem
    bpy.ops.mesh.primitive_torus_add(major_radius=0.4, minor_radius=0.08, location=(0, 0, 3.1))
    moon = bpy.context.active_object
    moon.rotation_euler = (math.radians(90), 0, 0)
    moon.name = "Trophy_Crescent"
    moon.data.materials.append(mat_neon)

if __name__ == "__main__":
    build_trophy()
    print("Module 03: Trophy assembly complete.")
