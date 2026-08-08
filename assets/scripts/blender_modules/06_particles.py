"""
Module 06: Particle Sparkles & Dust
Musabaqa 3D Esports Leaderboard
"""
import bpy
import random

def build_particles():
    mat_neon = bpy.data.materials.get("Mat_EmeraldNeon") or bpy.data.materials.new("Mat_EmeraldNeon")
    
    # Create floating ambient particle dots around the arena
    for i in range(30):
        rx = random.uniform(-8, 8)
        ry = random.uniform(-6, 8)
        rz = random.uniform(0.5, 6)
        rsize = random.uniform(0.04, 0.12)

        bpy.ops.mesh.primitive_ico_sphere_add(radius=rsize, location=(rx, ry, rz))
        particle = bpy.context.active_object
        particle.name = f"Particle_Sparkle_{i+1}"
        particle.data.materials.append(mat_neon)

if __name__ == "__main__":
    build_particles()
    print("Module 06: Particles complete.")
