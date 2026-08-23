# Terra Nova Spatial module

Audit date: 2026-08-23.

## Purpose

`app/modules/spatial` is the shared spatial domain for the web site, manager cabinet and future iOS/Android applications. It owns scenes, captures, versions, assets, property relations, hotspots, processing jobs and viewer analytics. Public and manager HTML adapters remain in the frontend module; API and domain logic do not depend on frontend controllers.

## Supported presentation profiles

| Profile | Input | Web output |
| --- | --- | --- |
| Three.js model | GLB/glTF; OBJ/FBX through Blender | Interactive WebGL viewer, animation, DRACO, Meshopt and KTX2 |
| Apple AR | USDZ | Native Quick Look link on supported Apple devices |
| Panorama | JPG/PNG/WebP equirectangular image | Interactive 360 viewer |
| Gaussian Splat | PLY, SPZ, SPLAT, KSPLAT or SOG | Spark renderer with mobile/WebXR-capable Three.js integration |
| External digital twin | HTTPS provider URL | Sandboxed presentation iframe, for example Matterport/Polycam |
| Plans and source packages | PDF, DXF, ZIP and source models | Versioned storage for downstream processing |

The module does not perform LiDAR scanning or photogrammetry reconstruction inside PHP. An iPhone capture app, Polycam/Scaniverse export, RoomPlan pipeline or an external reconstruction service produces source assets and sends them to this API. Optional Blender processing converts OBJ/FBX into GLB. A production photogrammetry or NeRF/3DGS generator is a separate worker/provider connected through assets and processing jobs.

## Manager workflow

1. Open `/spatial/manage` and create a scene, optionally preselected from the property editor.
2. Choose the scene and viewer types and register the capture source/device.
3. Drag source, web, AR, panorama, poster or splat assets into the scene.
4. Run `bin/spatial-worker.php`; ready assets move the scene to review.
5. Add hotspots and check the public preview.
6. Publish. The related property automatically receives its Spatial URL and renders the scene in its public card.

Scenes preserve capture/version history, so a new scan after repair does not overwrite the previous state.

## API

Public endpoints:

- `GET /api/spatial/scenes/{publicId}` returns a published viewer payload.
- `POST /api/spatial/events` records view, load, error, hotspot, fullscreen and AR events.

Authenticated endpoints accept the current manager session or `Authorization: Bearer <JWT>`:

- `POST /api/spatial/auth/token`
- `POST /api/spatial/scenes`
- `POST /api/spatial/scenes/{id}/assets` as multipart form data
- `POST /api/spatial/scenes/{id}/external-assets`
- `POST /api/spatial/scenes/{id}/captures`
- `POST /api/spatial/scenes/{id}/hotspots`
- `POST /api/spatial/scenes/{id}/publish`
- `GET /api/spatial/jobs/{publicId}`

The token endpoint uses existing Terra Nova user credentials and only issues Spatial JWTs to `admin`, `manager`, `realtor`, `partner` and `developer` roles. Native apps should request a short-lived token and never store the application JWT secret.

## Installation

```bash
php bin/apply-migration.php 20260823_000017_spatial_core.sql
npm install
npm run build:spatial
php bin/spatial-worker.php --limit=10
php tests/integration/spatial_module.php
node tests/browser/spatial_viewer.mjs http://127.0.0.1:8001/spatial/scene/{slug}
```

Required environment values:

```dotenv
SPATIAL_MAX_UPLOAD_BYTES=209715200
SPATIAL_JWT_SECRET=replace-with-a-long-random-production-secret
SPATIAL_JWT_TTL=28800
SPATIAL_BLENDER_BINARY=
SPATIAL_GLTF_TRANSFORM_BINARY=
```

`SPATIAL_BLENDER_BINARY` may point to `blender` and enables `bin/spatial-convert.py`. `SPATIAL_GLTF_TRANSFORM_BINARY` is an optional executable command for a compatible glTF optimization tool. Both stay empty when conversion is handled by n8n, a container worker or an external provider.

## Server limits and worker

For the default 200 MB application limit configure at least:

```ini
file_uploads = On
upload_max_filesize = 210M
post_max_size = 220M
memory_limit = 512M
max_execution_time = 120
```

Also configure `client_max_body_size 220m` in Nginx or the equivalent proxy/CDN limit. Store `public/uploads/spatial` on persistent storage and include it in backups. Run the worker every minute or under Supervisor/systemd; processing is atomic, stale locks are recoverable and jobs retry up to three times.

## Production boundary

The delivered module covers the complete application-side lifecycle: ingest, versions, validation, conversion hooks, publication, web/AR/panorama/splat rendering, property embedding and analytics. Production still needs infrastructure choices for object storage/CDN, virus scanning, Blender/reconstruction workers, iOS RoomPlan capture, large-scene LOD policy and real-device performance budgets.
