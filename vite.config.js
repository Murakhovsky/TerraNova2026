import { defineConfig } from 'vite';
import { resolve } from 'node:path';
import { copyFileSync, mkdirSync } from 'node:fs';

const copyThreeDecoders = {
  name: 'copy-three-decoders',
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
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        'cos-site': resolve(import.meta.dirname, 'resources/frontend/entrypoints/cos-site.js'),
        'terranova-catalog-api': resolve(import.meta.dirname, 'resources/frontend/entrypoints/terranova-catalog-api.js'),
        'terranova-club': resolve(import.meta.dirname, 'resources/frontend/entrypoints/terranova-club.js'),
        'terranova-copy': resolve(import.meta.dirname, 'resources/frontend/entrypoints/terranova-copy.js'),
        'terranova-home': resolve(import.meta.dirname, 'resources/frontend/entrypoints/terranova-home.js'),
        'terranova-media-manager': resolve(import.meta.dirname, 'resources/frontend/entrypoints/terranova-media-manager.js'),
        'terranova-property-gallery': resolve(import.meta.dirname, 'resources/frontend/entrypoints/terranova-property-gallery.js'),
        'terranova-spatial-admin': resolve(import.meta.dirname, 'resources/frontend/entrypoints/terranova-spatial-admin.js'),
        'spatial-viewer': resolve(import.meta.dirname, 'resources/spatial/spatial-viewer.js'),
      },
      output: {
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
});
