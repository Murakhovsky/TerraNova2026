import bpy
import os
import sys


def arguments():
    values = sys.argv[sys.argv.index("--") + 1:]
    if len(values) != 2:
        raise RuntimeError("Expected input and output paths.")
    return values[0], values[1]


def reset_scene():
    bpy.ops.object.select_all(action="SELECT")
    bpy.ops.object.delete(use_global=False)


def import_source(path):
    extension = os.path.splitext(path)[1].lower()
    if extension == ".obj":
        bpy.ops.wm.obj_import(filepath=path)
    elif extension == ".fbx":
        bpy.ops.import_scene.fbx(filepath=path)
    elif extension in (".glb", ".gltf"):
        bpy.ops.import_scene.gltf(filepath=path)
    else:
        raise RuntimeError(f"Unsupported Blender source format: {extension}")


source, destination = arguments()
reset_scene()
import_source(source)
bpy.ops.export_scene.gltf(
    filepath=destination,
    export_format="GLB",
    export_apply=True,
    export_yup=True,
)
