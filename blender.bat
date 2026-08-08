@echo off
REM Musabaqa Blender Animation & CLI Launcher
if "%1"=="animate" (
    echo [Musabaqa 3D] Running Blender Python Animation Generator...
    blender-launcher --background --python assets/scripts/generate_animated_leaderboard.py %2 %3 %4
) else if "%1"=="scene" (
    echo [Musabaqa 3D] Generating 3D Leaderboard Scene...
    blender-launcher --background --python assets/scripts/generate_leaderboard_scene.py %2 %3 %4
) else (
    blender-launcher %*
)
