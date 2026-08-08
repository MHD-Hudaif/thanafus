"""
Module 04: Floating Team Podiums
Musabaqa 3D Esports Leaderboard
"""
import bpy

def build_podiums():
    mat_glass = bpy.data.materials.get("Mat_CrystalGlass") or bpy.data.materials.new("Mat_CrystalGlass")
    mat_neon = bpy.data.materials.get("Mat_EmeraldNeon") or bpy.data.materials.new("Mat_EmeraldNeon")

    podium_configs = [
        {"rank": 1, "pos": (0, 4.2, 1.2), "radius": 2.2, "height": 1.2, "name": "Podium_Rank1"},
        {"rank": 2, "pos": (-4.5, 2.2, 0.8), "radius": 1.8, "height": 0.8, "name": "Podium_Rank2"},
        {"rank": 3, "pos": (4.5, 2.2, 0.6), "radius": 1.8, "height": 0.6, "name": "Podium_Rank3"},
        {"rank": 4, "pos": (0, -4.2, 0.4), "radius": 1.6, "height": 0.4, "name": "Podium_Rank4"},
    ]

    for config in podium_configs:
        x, y, h = config["pos"]
        
        bpy.ops.mesh.primitive_cylinder_add(radius=config["radius"], depth=h, vertices=6, location=(x, y, h / 2.0))
        podium = bpy.context.active_object
        podium.name = config["name"]
        podium.data.materials.append(mat_glass)

        bpy.ops.mesh.primitive_cylinder_add(radius=config["radius"] + 0.05, depth=0.06, vertices=6, location=(x, y, h + 0.03))
        neon_cap = bpy.context.active_object
        neon_cap.name = f"{config['name']}_NeonRing"
        neon_cap.data.materials.append(mat_neon)

if __name__ == "__main__":
    build_podiums()
    print("Module 04: Team podiums complete.")
