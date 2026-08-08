"""
===============================================================================
Musabaqa Blender Python (bpy) Animation Framework & Script Generator
===============================================================================
This module provides reusable animation building blocks for Blender (bpy).
It enables procedural creation of:
  - 360 Object Rotation loops (Trophies, Crescents, Rings, Badges)
  - Harmonic Floating / Levitating Bobbing motions (Podiums, Glass Panels)
  - Camera Cinematic Turntables and Fly-throughs
  - Light Pulse and Color Animations
  - GLTF / GLB Export with embedded NLA animation tracks for Three.js
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


def set_scene_timeline(start_frame=1, end_frame=240, fps=30):
    """Configures the scene timeline and frame rate."""
    scene = bpy.context.scene
    scene.frame_start = start_frame
    scene.frame_end = end_frame
    scene.render.fps = fps
    return scene


def animate_continuous_rotation(obj, axis='Z', duration_frames=240, rotations=1.0, linear_loop=True):
    """
    Animates continuous 360-degree rotation along the specified axis.
    """
    if not obj:
        return

    axis_idx = {'X': 0, 'Y': 1, 'Z': 2}.get(axis.upper(), 2)
    start_val = obj.rotation_euler[axis_idx]
    target_val = start_val + (2.0 * math.pi * rotations)

    # Frame 1
    obj.rotation_euler[axis_idx] = start_val
    obj.keyframe_insert(data_path="rotation_euler", index=axis_idx, frame=1)

    # End Frame
    obj.rotation_euler[axis_idx] = target_val
    obj.keyframe_insert(data_path="rotation_euler", index=axis_idx, frame=duration_frames + 1)

    # Set linear interpolation for smooth seamless looping
    if linear_loop and obj.animation_data and obj.animation_data.action:
        for fcurve in obj.animation_data.action.fcurves:
            if fcurve.data_path == "rotation_euler" and fcurve.array_index == axis_idx:
                for kf in fcurve.keyframe_points:
                    kf.interpolation = 'LINEAR'


def animate_floating_bobbing(obj, delta_z=0.3, cycles=2, duration_frames=240, phase_shift=0.0):
    """
    Animates smooth levitation / up-and-down floating motion.
    """
    if not obj:
        return

    base_z = obj.location.z
    steps = 48  # Keyframe resolution

    for f in range(1, duration_frames + 2, max(1, duration_frames // steps)):
        progress = (f - 1) / float(duration_frames)
        angle = (progress * 2.0 * math.pi * cycles) + phase_shift
        offset = math.sin(angle) * delta_z

        obj.location.z = base_z + offset
        obj.keyframe_insert(data_path="location", index=2, frame=f)

    # Set smooth BEZIER interpolation
    if obj.animation_data and obj.animation_data.action:
        for fcurve in obj.animation_data.action.fcurves:
            if fcurve.data_path == "location" and fcurve.array_index == 2:
                for kf in fcurve.keyframe_points:
                    kf.interpolation = 'BEZIER'


def animate_camera_orbit(cam, target_loc=(0, 0, 1.5), radius=15.0, height=6.0, duration_frames=240):
    """
    Animates a smooth 360-degree camera orbit around a center point.
    """
    if not cam:
        return

    steps = 60
    for f in range(1, duration_frames + 2, max(1, duration_frames // steps)):
        progress = (f - 1) / float(duration_frames)
        angle = progress * 2.0 * math.pi

        cam_x = target_loc[0] + radius * math.cos(angle)
        cam_y = target_loc[1] + radius * math.sin(angle)
        cam_z = target_loc[2] + height

        cam.location = (cam_x, cam_y, cam_z)
        cam.keyframe_insert(data_path="location", frame=f)

        # Track Target Calculation
        dx = target_loc[0] - cam_x
        dy = target_loc[1] - cam_y
        dz = target_loc[2] - cam_z

        pitch = math.atan2(dz, math.sqrt(dx * dx + dy * dy))
        yaw = math.atan2(dy, dx) - math.radians(90)

        cam.rotation_euler = (math.radians(90) - pitch, 0, yaw)
        cam.keyframe_insert(data_path="rotation_euler", frame=f)


def export_animated_glb(output_path):
    """Exports current Blender scene with embedded animations into GLB format."""
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    
    print(f"[Blender Animation] Exporting GLB to: {output_path}")
    try:
        if hasattr(bpy.ops.wm, "gltf_export"):
            bpy.ops.wm.gltf_export(
                filepath=output_path,
                export_format='GLB',
                export_animations=True,
                export_current_frame=False,
                export_skins=True,
                export_morph=True
            )
        else:
            bpy.ops.export_scene.gltf(
                filepath=output_path,
                export_format='GLB',
                export_animations=True,
                export_apply=True
            )
        print("[Blender Animation] GLB exported successfully!")
        return True
    except Exception as e:
        print(f"[Blender Animation] Export error: {e}")
        return False
