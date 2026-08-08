"""
Module 08: GLTF / GLB Exporter for Three.js
Musabaqa 3D Esports Leaderboard
"""
import bpy
import os

def export_to_glb(filename="musabaqa_arena_leaderboard.glb"):
    current_dir = os.path.dirname(os.path.abspath(__file__))
    target_path = os.path.join(current_dir, filename)
    
    print(f"Exporting scene to {target_path}...")
    try:
        if hasattr(bpy.ops.wm, "gltf_export"):
            bpy.ops.wm.gltf_export(filepath=target_path, export_format='GLB')
        else:
            bpy.ops.export_scene.gltf(filepath=target_path, export_format='GLB', export_apply=True)
        print("GLB Export Successful!")
    except Exception as e:
        print(f"GLB Export error: {e}")

if __name__ == "__main__":
    export_to_glb()
