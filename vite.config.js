import { defineConfig } from 'vite';
import { resolve } from 'node:path';
import { copyFileSync, mkdirSync, readdirSync, rmSync } from 'node:fs';

const copyThreeDecoders = {
  name: 'copy-three-decoders',
  buildStart() {
    const target = resolve(import.meta.dirname, 'public/build');
    mkdirSync(target, { recursive: true });
    for (const entry of readdirSync(target, { withFileTypes: true })) {
      if (entry.isFile() && /^(spatial-viewer\.(js|css)|spark\.module-.*\.js|three\.module-.*\.js)$/.test(entry.name)) {
        rmSync(resolve(target, entry.name));
      }
    }
    rmSync(resolve(target, 'three-decoders'), { recursive: true, force: true });
  },
  closeBundle() {
    const target = resolve(import.meta.dirname, 'public/build/three-decoders');
    const decoderSets = {
      draco: ['draco_decoder.js', 'draco_decoder.wasm', 'draco_wasm_wrapper.js'],
      basis: ['basis_transcoder.js', 'basis_transcoder.wasm'],
    };
    for (const [directory, files] of Object.entries(decoderSets)) {
      const destination = resolve(target, directory);
      const source = resolve(import.meta.dirname, `node_modules/three/examples/jsm/libs/${directory === 'draco' ? 'draco/gltf' : 'basis'}`);
      mkdirSync(destination, { recursive: true });
      files.forEach((file) => copyFileSync(resolve(source, file), resolve(destination, file)));
    }
  },
};

export default defineConfig({
  publicDir: false,
  plugins: [copyThreeDecoders],
  build: {
    outDir: 'public/build',
    emptyOutDir: false,
    cssCodeSplit: false,
    lib: {
      entry: resolve(import.meta.dirname, 'resources/spatial/spatial-viewer.js'),
      formats: ['es'],
      fileName: () => 'spatial-viewer.js',
      cssFileName: 'spatial-viewer',
    },
  },
});
