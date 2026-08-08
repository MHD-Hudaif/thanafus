"""
Module 07: Camera & Floating Motion Animation Keyframes
Musabaqa 3D Esports Leaderboard
"""
import bpy
import math

def add_camera_orbit_animation():
    cam = bpy.data.objects.get("Cinematic_Camera")
    if not cam:
        return

    bpy.context.scene.frame_start = 1
    bpy.context.scene.frame_end = 250

    # Keyframe initial position
    cam.location = (0, -15.5, 6.5)
    cam.keyframe_insert(data_path="location", frame=1)

    # Keyframe subtle pan rotation across 250 frames
    cam.location = (2.5, -15.0, 6.8)
    cam.keyframe_insert(data_path="location", frame=125)

    cam.location = (0, -15.5, 6.5)
    cam.keyframe_insert(data_path="location", frame=250)

if __name__ == "__main__":
    add_camera_orbit_animation()
    print("Module 07: Animation keyframes complete.")
