"""
Module 05: Leaderboard Panels
Musabaqa 3D Esports Leaderboard
"""
import bpy

def build_leaderboard_panels():
    mat_glass = bpy.data.materials.get("Mat_CrystalGlass") or bpy.data.materials.new("Mat_CrystalGlass")
    mat_gold = bpy.data.materials.get("Mat_EsportsGold") or bpy.data.materials.new("Mat_EsportsGold")

    # Screen Panel
    bpy.ops.mesh.primitive_cube_add(size=1.0, location=(0, 7.5, 4.5))
    screen = bpy.context.active_object
    screen.scale = (10.0, 0.1, 4.5)
    screen.name = "Leaderboard_Glass_Screen"
    screen.data.materials.append(mat_glass)

    # Frame Outer
    bpy.ops.mesh.primitive_cube_add(size=1.0, location=(0, 7.5, 4.5))
    frame = bpy.context.active_object
    frame.scale = (10.3, 0.15, 4.7)
    frame.name = "Leaderboard_Gold_Frame"
    frame.data.materials.append(mat_gold)

if __name__ == "__main__":
    build_leaderboard_panels()
    print("Module 05: Leaderboard panels complete.")
