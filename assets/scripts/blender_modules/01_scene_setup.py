"""
Module 01: Scene Setup, Lighting, and Materials
Musabaqa 3D Esports Leaderboard
"""
import bpy
import math

def reset_scene():
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete(use_global=False)
    for mat in bpy.data.materials:
        bpy.data.materials.remove(mat)
    for mesh in bpy.data.meshes:
        bpy.data.meshes.remove(mesh)

def create_pbr_material(name, color=(0.1, 0.1, 0.1, 1.0), metallic=0.0, roughness=0.5, transmission=0.0, emission_color=(0,0,0), emission_strength=0.0):
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
        if emission_strength > 0:
            if 'Emission Color' in principled.inputs:
                principled.inputs['Emission Color'].default_value = emission_color + (1.0,)
                principled.inputs['Emission Strength'].default_value = emission_strength
            elif 'Emission' in principled.inputs:
                principled.inputs['Emission'].default_value = emission_color + (1.0,)
                if 'Emission Strength' in principled.inputs:
                    principled.inputs['Emission Strength'].default_value = emission_strength
    return mat

def setup_environment():
    reset_scene()
    
    # 35mm Broadcast Camera
    bpy.ops.object.camera_add(location=(0, -15.5, 6.5), rotation=(math.radians(72), 0, 0))
    cam = bpy.context.active_object
    cam.name = "Cinematic_Camera"
    cam.data.lens = 35
    bpy.context.scene.camera = cam

    # Sun Light (Key)
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
    rim_light.data.color = (0.06, 0.72, 0.5)
    rim_light.data.spot_size = math.radians(75)
    rim_light.name = "Rim_SpotLight"

if __name__ == "__main__":
    setup_environment()
    print("Module 01: Scene setup complete.")
