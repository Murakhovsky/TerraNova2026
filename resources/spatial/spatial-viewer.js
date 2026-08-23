import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';
import { KTX2Loader } from 'three/addons/loaders/KTX2Loader.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';
import './spatial-viewer.css';

class SpatialViewer {
  constructor(element) {
    this.element = element;
    this.endpoint = element.dataset.endpoint;
    this.reference = element.dataset.scene;
    this.status = element.querySelector('[data-spatial-status]');
    this.hotspotLayer = element.querySelector('[data-spatial-hotspots]');
    this.scene = new THREE.Scene();
    this.camera = new THREE.PerspectiveCamera(52, 1, 0.01, 5000);
    this.camera.position.set(4, 2.5, 5);
    this.renderer = null;
    this.controls = null;
    this.clock = new THREE.Clock();
    this.mixers = [];
    this.hotspots = [];
    this.initialView = null;
    this.frame = 0;
    this.resizeObserver = null;
  }

  async init() {
    try {
      const response = await fetch(this.endpoint, { headers: { Accept: 'application/json' } });
      const payload = await response.json();
      if (!response.ok || !payload.scene) throw new Error(payload.message || 'Сцену не знайдено.');
      this.data = payload.scene;
      const viewer = this.data.viewer || {};
      if (viewer.type === 'external') return this.mountExternal(viewer.external_url);
      if (viewer.type === 'panorama') return this.mountPanorama(viewer.panorama_url);
      if (viewer.type === 'gaussian_splat') return this.mountSplat(viewer.splat_url, viewer);
      await this.mountModel(viewer.model_url, viewer);
    } catch (error) {
      this.fail(error.message || '3D-сцена недоступна.');
      this.track('error', { message: error.message || 'viewer error' });
    }
  }

  setupRenderer(background, antialias = true) {
    this.renderer = new THREE.WebGLRenderer({ antialias, alpha: true, powerPreference: 'high-performance' });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    this.renderer.outputColorSpace = THREE.SRGBColorSpace;
    this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
    this.renderer.toneMappingExposure = 1.05;
    this.renderer.setClearColor(background || '#e9ece9', 1);
    this.element.insertBefore(this.renderer.domElement, this.element.firstChild);
    this.controls = new OrbitControls(this.camera, this.renderer.domElement);
    this.controls.enableDamping = true;
    this.controls.dampingFactor = 0.07;
    this.controls.screenSpacePanning = true;
    this.controls.maxPolarAngle = Math.PI * 0.92;
    this.scene.add(new THREE.HemisphereLight(0xffffff, 0x647064, 2.2));
    const key = new THREE.DirectionalLight(0xffffff, 3.5);
    key.position.set(6, 10, 5);
    this.scene.add(key);
    const fill = new THREE.DirectionalLight(0xcfe0ff, 1.5);
    fill.position.set(-7, 4, -5);
    this.scene.add(fill);
    this.resizeObserver = new ResizeObserver(() => this.resize());
    this.resizeObserver.observe(this.element);
    this.resize();
    this.bindActions();
  }

  async mountModel(url, viewer) {
    if (!url) throw new Error('Для сцени не визначено готову GLB/glTF модель.');
    this.setupRenderer(viewer.settings?.background);
    const manager = new THREE.LoadingManager();
    manager.onProgress = (_url, loaded, total) => this.setStatus(`Завантаження сцени ${Math.round((loaded / total) * 100)}%`);
    const loader = new GLTFLoader(manager);
    const draco = new DRACOLoader(manager);
    draco.setDecoderPath('/build/three-decoders/draco/');
    const ktx2 = new KTX2Loader(manager);
    ktx2.setTranscoderPath('/build/three-decoders/basis/');
    ktx2.detectSupport(this.renderer);
    loader.setDRACOLoader(draco);
    loader.setKTX2Loader(ktx2);
    loader.setMeshoptDecoder(MeshoptDecoder);
    const gltf = await loader.loadAsync(url);
    this.scene.add(gltf.scene);
    gltf.scene.traverse((object) => {
      if (object.isMesh) {
        object.castShadow = false;
        object.receiveShadow = true;
      }
    });
    this.frameObject(gltf.scene, viewer.camera);
    gltf.animations.forEach((clip) => {
      const mixer = new THREE.AnimationMixer(gltf.scene);
      mixer.clipAction(clip).play();
      this.mixers.push(mixer);
    });
    this.mountHotspots(this.data.hotspots || []);
    this.setStatus('');
    this.status.hidden = true;
    this.element.classList.add('is-ready');
    this.track('load', { format: 'gltf', animations: gltf.animations.length });
    this.animate();
  }

  async mountPanorama(url) {
    if (!url) throw new Error('Для panorama viewer не визначено зображення.');
    this.setupRenderer('#101312');
    const texture = await new THREE.TextureLoader().loadAsync(url);
    texture.colorSpace = THREE.SRGBColorSpace;
    const geometry = new THREE.SphereGeometry(20, 64, 40);
    geometry.scale(-1, 1, 1);
    this.scene.add(new THREE.Mesh(geometry, new THREE.MeshBasicMaterial({ map: texture })));
    this.camera.position.set(0, 0, 0.1);
    this.controls.enablePan = false;
    this.controls.enableZoom = true;
    this.controls.target.set(0, 0, -1);
    this.initialView = this.snapshotView();
    this.setStatus('');
    this.status.hidden = true;
    this.element.classList.add('is-ready');
    this.mountHotspots(this.data.hotspots || []);
    this.track('load', { format: 'panorama' });
    this.animate();
  }

  mountExternal(url) {
    if (!url) throw new Error('URL зовнішньої сцени відсутній.');
    const frame = document.createElement('iframe');
    frame.src = url;
    frame.title = this.data.title || '3D scene';
    frame.allow = 'fullscreen; xr-spatial-tracking; accelerometer; gyroscope';
    frame.allowFullscreen = true;
    frame.loading = 'eager';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    this.element.insertBefore(frame, this.element.firstChild);
    this.status.hidden = true;
    this.element.classList.add('is-ready', 'is-external');
    this.bindActions();
    this.track('load', { format: 'external' });
  }

  async mountSplat(url, viewer) {
    if (!url) throw new Error('Для Gaussian Splat viewer не визначено PLY/SPZ/SPLAT/KSPLAT/SOG asset.');
    const { SparkRenderer, SplatMesh } = await import('@sparkjsdev/spark');
    this.setupRenderer(viewer.settings?.background || '#111714', false);
    const spark = new SparkRenderer({ renderer: this.renderer });
    const splat = new SplatMesh({
      url,
      lod: true,
      onProgress: (event) => {
        if (event.lengthComputable) this.setStatus(`Завантаження splat ${Math.round((event.loaded / event.total) * 100)}%`);
      },
    });
    this.scene.add(spark);
    this.scene.add(splat);
    await splat.initialized;
    const camera = viewer.camera?.position;
    this.camera.position.set(Number(camera?.x) || 0, Number(camera?.y) || 1.4, Number(camera?.z) || 4);
    this.controls.target.set(0, 0, 0);
    this.controls.update();
    this.initialView = this.snapshotView();
    this.setStatus('');
    this.status.hidden = true;
    this.element.classList.add('is-ready');
    this.mountHotspots(this.data.hotspots || []);
    this.track('load', { format: 'gaussian_splat' });
    this.animate();
  }

  frameObject(object, configuredCamera) {
    const box = new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    const radius = Math.max(size.x, size.y, size.z, 1);
    object.position.sub(center);
    const configured = configuredCamera?.position;
    if (configured && Number.isFinite(configured.x) && Number.isFinite(configured.y) && Number.isFinite(configured.z)) {
      this.camera.position.set(configured.x, configured.y, configured.z);
    } else {
      this.camera.position.set(radius * 1.15, radius * 0.72, radius * 1.25);
    }
    this.camera.near = Math.max(radius / 1000, 0.01);
    this.camera.far = Math.max(radius * 100, 100);
    this.camera.updateProjectionMatrix();
    this.controls.target.set(0, 0, 0);
    this.controls.minDistance = radius * 0.08;
    this.controls.maxDistance = radius * 6;
    this.controls.update();
    this.initialView = this.snapshotView();
  }

  mountHotspots(hotspots) {
    hotspots.forEach((data) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'tn-spatial-hotspot';
      button.textContent = data.title;
      button.title = data.body || data.title;
      button.addEventListener('click', () => {
        this.hotspotLayer.querySelectorAll('.is-active').forEach((node) => node.classList.remove('is-active'));
        button.classList.add('is-active');
        this.track('hotspot', { hotspot: data.public_id, type: data.hotspot_type });
        if (data.action_url) window.open(data.action_url, '_blank', 'noopener');
      });
      this.hotspotLayer.appendChild(button);
      const position = data.position || {};
      this.hotspots.push({ element: button, position: new THREE.Vector3(Number(position.x) || 0, Number(position.y) || 0, Number(position.z) || 0) });
    });
  }

  updateHotspots() {
    const width = this.element.clientWidth;
    const height = this.element.clientHeight;
    this.hotspots.forEach((hotspot) => {
      const point = hotspot.position.clone().project(this.camera);
      const visible = point.z > -1 && point.z < 1;
      hotspot.element.hidden = !visible;
      if (visible) hotspot.element.style.transform = `translate(-50%, -50%) translate(${(point.x * 0.5 + 0.5) * width}px, ${(-point.y * 0.5 + 0.5) * height}px)`;
    });
  }

  bindActions() {
    const scope = this.element.closest('.tn-spatial-stage') || document;
    scope.querySelector('[data-spatial-reset]')?.addEventListener('click', () => this.reset());
    scope.querySelector('[data-spatial-fullscreen]')?.addEventListener('click', () => this.element.requestFullscreen?.());
  }

  reset() {
    if (!this.initialView || !this.controls) return;
    this.camera.position.copy(this.initialView.position);
    this.camera.quaternion.copy(this.initialView.quaternion);
    this.controls.target.copy(this.initialView.target);
    this.controls.update();
  }

  snapshotView() {
    return { position: this.camera.position.clone(), quaternion: this.camera.quaternion.clone(), target: this.controls.target.clone() };
  }

  resize() {
    if (!this.renderer) return;
    const width = Math.max(1, this.element.clientWidth);
    const height = Math.max(1, this.element.clientHeight);
    this.camera.aspect = width / height;
    this.camera.updateProjectionMatrix();
    this.renderer.setSize(width, height, false);
  }

  animate() {
    this.frame = requestAnimationFrame(() => this.animate());
    const delta = Math.min(this.clock.getDelta(), 0.1);
    this.mixers.forEach((mixer) => mixer.update(delta));
    this.controls?.update();
    this.updateHotspots();
    this.renderer?.render(this.scene, this.camera);
  }

  track(eventType, payload) {
    const body = JSON.stringify({ scene: this.reference, event_type: eventType, source_page: location.href, payload: payload || {} });
    if (navigator.sendBeacon) navigator.sendBeacon('/api/spatial/events', new Blob([body], { type: 'application/json' }));
    else fetch('/api/spatial/events', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true }).catch(() => {});
  }

  setStatus(message) { if (this.status) this.status.textContent = message; }
  fail(message) { this.setStatus(message); this.element.classList.add('has-error'); }
}

document.querySelectorAll('[data-spatial-viewer]').forEach((element) => new SpatialViewer(element).init());
