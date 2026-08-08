"""
Module 02: Islamic Arena Architecture
Musabaqa 3D Esports Leaderboard
"""
import bpy
import math

def build_arena():
    # Fetch or create materials
    mat_marble = bpy.data.materials.get("Mat_DarkMarble") or bpy.data.materials.new("Mat_DarkMarble")
    mat_gold = bpy.data.materials.get("Mat_EsportsGold") or bpy.data.materials.new("Mat_EsportsGold")
    mat_neon = bpy.data.materials.get("Mat_EmeraldNeon") or bpy.data.materials.new("Mat_EmeraldNeon")

    # Main Stage Floor
    bpy.ops.mesh.primitive_cylinder_add(radius=14, depth=0.4, vertices=32, location=(0, 0, -0.2))
    floor = bpy.context.active_object
    floor.name = "Arena_Floor"
    floor.data.materials.append(mat_marble)

    # Gold Inlay Trim
    bpy.ops.mesh.primitive_cylinder_add(radius=14.2, depth=0.1, vertices=32, location=(0, 0, -0.05))
    floor_trim = bpy.context.active_object
    floor_trim.name = "Arena_Floor_GoldTrim"
    floor_trim.data.materials.append(mat_gold)

    # Inner Neon Ring
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

if __name__ == "__main__":
    build_arena()
    print("Module 02: Arena geometry complete.")
