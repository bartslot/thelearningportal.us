#!/usr/bin/env node
/**
 * strip-root-motion.mjs
 *
 * Strips X/Z translation from the root/hip bone of a GLB animation file.
 * CMU mocap data bakes root motion into the hip bone which causes the avatar
 * to drift across the scene. This script zeroes X and Z while preserving Y
 * (vertical movement for crouch/jump animations).
 *
 * Usage: node scripts/strip-root-motion.mjs path/to/animation.glb
 */

import { NodeIO } from '@gltf-transform/core';
import { resolve } from 'path';

const ROOT_BONE_NAMES = new Set([
    'root', 'Root', 'ROOT',
    'hips', 'Hips', 'HIPS',
    'hip',  'Hip',  'HIP',
]);

const glbPath = process.argv[2];

if (!glbPath) {
    console.error('Usage: node strip-root-motion.mjs <path-to-glb>');
    process.exit(1);
}

const absolutePath = resolve(glbPath);

try {
    const io = new NodeIO();
    const document = await io.read(absolutePath);
    const root = document.getRoot();

    let stripped = false;

    for (const animation of root.listAnimations()) {
        for (const channel of animation.listChannels()) {
            const node = channel.getTargetNode();
            const path = channel.getTargetPath();

            if (!node || path !== 'translation') continue;

            const boneName = node.getName();
            if (!ROOT_BONE_NAMES.has(boneName)) continue;

            const sampler = channel.getSampler();
            const output = sampler.getOutput();

            if (!output) continue;

            // Clone and zero X (index 0) and Z (index 2), preserve Y (index 1)
            const array = output.getArray();
            if (!array) continue;

            const zeroed = new Float32Array(array);
            for (let i = 0; i < zeroed.length; i += 3) {
                zeroed[i]     = 0; // X
                zeroed[i + 2] = 0; // Z
                // zeroed[i + 1] preserved (Y)
            }
            output.setArray(zeroed);
            stripped = true;

            console.log(`Stripped root motion from bone: "${boneName}" in animation: "${animation.getName()}"`);
        }
    }

    if (!stripped) {
        console.log('No root bone translation track found — file unchanged.');
    }

    await io.write(absolutePath, document);
    console.log(`Done: ${absolutePath}`);

} catch (err) {
    console.error('strip-root-motion failed:', err.message);
    process.exit(1);
}
