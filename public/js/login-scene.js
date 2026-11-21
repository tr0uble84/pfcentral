// PFCentral login scene (three.js): the CFBTEL cloud mark spins at the centre of a network.
// Traffic flows in from the edge; cyan packets pass the firewall ring, red ones are stopped at it.
import * as THREE from 'three';
import { SVGLoader } from '/vendor/three/SVGLoader.js';

const canvas = document.getElementById('pfc-scene');
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function webglAvailable() {
    try {
        const c = document.createElement('canvas');
        return !!(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
    } catch (e) {
        return false;
    }
}

if (canvas && webglAvailable()) {
    start();
} else {
    document.documentElement.classList.add('pfc-no-webgl');
}

function start() {
    const CYAN = new THREE.Color('#33C5F3');
    const RED = new THREE.Color('#FF5A64');
    const NAVY = new THREE.Color('#0E2238');

    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;

    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(NAVY, 14, 30);
    const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 100);
    camera.position.set(0, 0.6, 16);

    scene.add(new THREE.AmbientLight(0x9fdcff, 0.55));
    const key = new THREE.DirectionalLight(0xffffff, 1.6);
    key.position.set(4, 6, 8);
    scene.add(key);
    const rim = new THREE.DirectionalLight(0x33c5f3, 1.4);
    rim.position.set(-6, -2, -4);
    scene.add(rim);
    const core = new THREE.PointLight(0x33c5f3, 18, 9, 1.6);
    core.position.set(0, 0, 2.2);
    scene.add(core);

    // World root: shifted left on wide screens so the sign-in panel never covers the logo.
    const world = new THREE.Group();
    scene.add(world);

    /* ------------------------------------------------------------ the logo */
    // The CFBTEL cloud mark, extruded from the logo's own SVG path, spinning at the centre.
    const logo = new THREE.Group();
    world.add(logo);

    const CLOUD = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 40"><path d="M49,24.43c0,2.26-0.69,4.46-1.98,6.32c-1.29,1.85-3.11,3.28-5.24,4.08c-2.12,0.8-4.43,0.94-6.63,0.4 c-2.2-0.54-4.18-1.73-5.69-3.43c-0.02-0.02-0.03-0.03-0.03-0.04c-0.25-0.31-0.4-0.7-0.4-1.1s0.14-0.79,0.4-1.1 c0.03-0.04,0.08-0.08,0.11-0.12c0.18-0.18,0.39-0.31,0.63-0.4c0.24-0.08,0.49-0.12,0.74-0.1c0.52,0.04,0.98,0.4,1.34,0.76 c1.23,1.29,2.87,2.12,4.64,2.34c0.31,0.04,0.63,0.06,0.95,0.06c1.16,0,2.31-0.26,3.35-0.76c1.05-0.5,1.96-1.24,2.68-2.14 c0.72-0.91,1.23-1.96,1.48-3.09c0.25-1.13,0.25-2.3-0.02-3.43c-0.26-1.13-0.79-2.17-1.51-3.07c-0.73-0.9-1.66-1.62-2.71-2.11 c-1.05-0.49-2.2-0.74-3.36-0.73c-1.16,0.02-2.3,0.29-3.34,0.8c-0.71,0.36-1.35,0.81-1.92,1.36c-0.17,0.16-0.32,0.33-0.47,0.51 c-0.04,0.05-0.09,0.1-0.14,0.15c-0.08,0.09-0.15,0.19-0.23,0.27l-5.6,6.02l-5.78,6.2c0,0-0.03,0.03-0.03,0.03 c-0.26,0.25-0.53,0.49-0.8,0.73c0,0-0.02,0.01-0.02,0.02c-1.86,1.6-4.2,2.54-6.65,2.67c-0.12,0-0.24,0.01-0.36,0.02h-0.26 c-2.96,0-5.8-1.17-7.89-3.26c-2.1-2.1-3.27-4.92-3.27-7.87c0-0.2,0-0.41,0.02-0.61c0.02-0.2,0.03-0.4,0.05-0.6 c0.02-0.2,0.05-0.4,0.08-0.59c0.03-0.19,0.07-0.38,0.11-0.58c0.04-0.19,0.08-0.38,0.14-0.58c0.05-0.19,0.11-0.38,0.17-0.56 c0.06-0.18,0.13-0.36,0.19-0.54s0.14-0.36,0.23-0.53c0.08-0.18,0.17-0.35,0.25-0.52s0.18-0.34,0.28-0.5 c0.09-0.16,0.19-0.33,0.3-0.48C2.9,18.19,3,18.03,3.11,17.88c0.11-0.15,0.23-0.3,0.36-0.46c0.13-0.15,0.25-0.29,0.37-0.43 s0.25-0.28,0.38-0.41c0.13-0.14,0.27-0.26,0.41-0.39c0.14-0.13,0.29-0.25,0.43-0.37c0.14-0.12,0.3-0.24,0.45-0.35 s0.31-0.22,0.47-0.32c0.16-0.1,0.32-0.2,0.48-0.3c0.17-0.1,0.33-0.19,0.5-0.28c0.17-0.08,0.34-0.18,0.52-0.25 c0.18-0.08,0.36-0.16,0.53-0.23c0.18-0.07,0.36-0.14,0.54-0.19c0.18-0.06,0.37-0.12,0.56-0.17c0.19-0.05,0.38-0.1,0.58-0.14 s0.38-0.08,0.58-0.11s0.41-0.06,0.62-0.08c0.2-0.03,0.4-0.04,0.59-0.05h0.19c0.07-0.34,0.15-0.68,0.25-1.01 c0.21-0.69,0.48-1.35,0.82-1.99c0.65-1.23,1.53-2.31,2.6-3.22c0.52-0.44,1.08-0.84,1.68-1.18c2.47-1.44,5.41-1.86,8.19-1.18 c0.2,0.05,0.41,0.1,0.62,0.16c0.89,0.25,1.73,0.62,2.53,1.09c0.18,0.1,0.35,0.21,0.52,0.32l0.14,0.09 c0.29,0.21,0.57,0.43,0.84,0.68c0.28,0.24,0.55,0.49,0.8,0.76c0.12,0.12,0.24,0.25,0.35,0.37l0.17,0.19 c0.08,0.09,0.15,0.19,0.22,0.27c0.07,0.08,0.11,0.14,0.16,0.21c0.14,0.19,0.27,0.38,0.4,0.58c0.19,0.3,0.38,0.6,0.55,0.91 c0.18,0.3,0.3,0.62,0.34,0.96c0.02,0.22,0,0.44-0.07,0.65c-0.1,0.3-0.27,0.57-0.51,0.78c-0.24,0.21-0.52,0.36-0.83,0.42 c-0.23,0.05-0.47,0.04-0.7,0c-0.23-0.04-0.45-0.15-0.63-0.3c-0.14-0.14-0.25-0.29-0.34-0.45c-0.28-0.41-0.51-0.86-0.8-1.28 c-0.1-0.14-0.21-0.29-0.32-0.42c-0.52-0.66-1.17-1.23-1.89-1.67c-0.41-0.25-0.83-0.46-1.27-0.63c-0.26-0.11-0.53-0.2-0.8-0.28 c-0.35-0.09-0.69-0.16-1.05-0.21c-0.35-0.04-0.69-0.07-1.04-0.07c-0.34,0-0.69,0.03-1.02,0.08c-0.34,0.05-0.67,0.12-1,0.2 c-0.33,0.09-0.64,0.2-0.96,0.34c-0.31,0.13-0.61,0.28-0.9,0.45c-0.29,0.17-0.57,0.36-0.84,0.58c-0.26,0.21-0.52,0.43-0.75,0.67 s-0.46,0.5-0.67,0.76c-0.2,0.28-0.4,0.57-0.56,0.86c-0.17,0.3-0.32,0.63-0.45,0.96c-0.07,0.18-0.13,0.36-0.19,0.53 c-0.04,0.13-0.08,0.26-0.11,0.4c0.24,0.07,0.47,0.14,0.7,0.22c1.01,0.37,1.96,0.89,2.83,1.51c0.5,0.36,0.96,0.77,1.4,1.21 c0.38,0.38,0.75,0.83,0.75,1.4c0,0.39-0.14,0.77-0.38,1.08l-0.16,0.18c-0.32,0.31-0.75,0.48-1.2,0.48c-0.25,0-0.5-0.05-0.73-0.15 c-0.22-0.12-0.42-0.28-0.58-0.47c-0.09-0.1-0.19-0.19-0.28-0.29c-0.19-0.18-0.37-0.34-0.58-0.5c-0.14-0.11-0.29-0.22-0.43-0.31 c-0.27-0.19-0.56-0.36-0.85-0.5c-0.16-0.08-0.33-0.16-0.49-0.22c-0.31-0.13-0.63-0.24-0.96-0.32c-0.29-0.08-0.58-0.14-0.88-0.18 c-0.27-0.03-0.52-0.06-0.8-0.08c-0.03,0-0.07,0-0.1,0h-0.26c-0.21,0-0.42,0-0.63,0.03c-1.12,0.09-2.19,0.42-3.16,0.97 S6.55,19.1,5.9,20c-0.65,0.91-1.09,1.94-1.3,3.03c-0.21,1.09-0.18,2.22,0.09,3.29c0.27,1.07,0.78,2.08,1.47,2.95 c0.7,0.86,1.58,1.57,2.58,2.06s2.1,0.76,3.22,0.79c1.12,0.03,2.23-0.19,3.25-0.63c1.03-0.47,1.96-1.14,2.73-1.98l0.27-0.28 c0,0,1.31-1.41,3.04-3.27c0.57-0.61,1.18-1.26,1.8-1.94c0.41-0.44,0.83-0.89,1.24-1.34c2.66-2.85,5.18-5.56,5.18-5.56l0.46-0.5 l0.02-0.02c0.39-0.39,0.81-0.75,1.26-1.08c0,0,0-0.01,0.02-0.02c0.08-0.07,0.16-0.13,0.25-0.19c0.96-0.67,2.01-1.18,3.13-1.53 c0.01,0,0.02,0,0.03,0c0.98-0.3,2-0.45,3.03-0.47h0.19c1.46,0,2.92,0.29,4.27,0.85s2.59,1.38,3.63,2.41s1.86,2.26,2.42,3.61 s0.85,2.8,0.85,4.26l0,0L49,24.43z"/></svg>';
    const shapes = new SVGLoader().parse(CLOUD).paths.flatMap((p) => SVGLoader.createShapes(p));
    const cloudGeo = new THREE.ExtrudeGeometry(shapes, {
        depth: 5, bevelEnabled: true, bevelThickness: 1.1, bevelSize: 0.55, bevelSegments: 4, curveSegments: 24,
    });
    cloudGeo.center();
    cloudGeo.scale(0.1, -0.1, 0.1); // SVG y points down
    cloudGeo.computeVertexNormals();
    const cloud = new THREE.Mesh(cloudGeo, new THREE.MeshStandardMaterial({
        color: CYAN, emissive: 0x0b5f80, emissiveIntensity: 0.55, metalness: 0.35, roughness: 0.28,
    }));
    logo.add(cloud);
    // Soft glow disc behind the mark.
    const glow = new THREE.Mesh(new THREE.CircleGeometry(3.2, 64), new THREE.MeshBasicMaterial({
        color: CYAN, transparent: true, opacity: 0.07, depthWrite: false,
    }));
    glow.position.z = -1.2;
    world.add(glow);

    /* ------------------------------------------------------ firewall rings */
    const RING_R = 3.9;
    const ringMat = new THREE.MeshBasicMaterial({ color: CYAN, transparent: true, opacity: 0.55 });
    const ring = new THREE.Mesh(new THREE.TorusGeometry(RING_R, 0.025, 8, 180), ringMat);
    ring.rotation.x = Math.PI / 2.35;
    world.add(ring);
    const ring2 = new THREE.Mesh(new THREE.TorusGeometry(RING_R + 0.35, 0.012, 6, 180),
        new THREE.MeshBasicMaterial({ color: CYAN, transparent: true, opacity: 0.22 }));
    ring2.rotation.set(Math.PI / 2.1, 0.35, 0);
    world.add(ring2);

    // Faint shield sphere: where packets are inspected.
    const dome = new THREE.Mesh(new THREE.SphereGeometry(RING_R, 48, 24),
        new THREE.MeshBasicMaterial({ color: CYAN, transparent: true, opacity: 0.035, depthWrite: false }));
    world.add(dome);

    /* ------------------------------------------------------- outer network */
    const small = window.innerWidth < 768;
    const NODE_COUNT = small ? 26 : 44;
    const nodes = [];
    const net = new THREE.Group();
    world.add(net);
    const netNodeGeo = new THREE.SphereGeometry(0.065, 12, 8);
    const netNodeMat = new THREE.MeshBasicMaterial({ color: 0x8fdcf8, transparent: true, opacity: 0.85 });
    for (let i = 0; i < NODE_COUNT; i++) {
        // Even spread on a shell (golden spiral), slightly jittered.
        const t = (i + 0.5) / NODE_COUNT;
        const inc = Math.acos(1 - 2 * t);
        const az = Math.PI * (1 + Math.sqrt(5)) * i;
        const r = 7.2 + Math.random() * 1.8;
        const v = new THREE.Vector3(Math.sin(inc) * Math.cos(az), Math.cos(inc) * 0.72, Math.sin(inc) * Math.sin(az) * 0.55).multiplyScalar(r);
        const m = new THREE.Mesh(netNodeGeo, netNodeMat);
        m.position.copy(v);
        net.add(m);
        nodes.push(v);
    }
    const linkPts = [];
    nodes.forEach((v, i) => {
        nodes.map((w, j) => [j, v.distanceTo(w)]).filter(([j]) => j !== i).sort((x, y) => x[1] - y[1]).slice(0, 2)
            .forEach(([j]) => { if (j > i) linkPts.push(v, nodes[j]); });
    });
    net.add(new THREE.LineSegments(new THREE.BufferGeometry().setFromPoints(linkPts),
        new THREE.LineBasicMaterial({ color: 0x33c5f3, transparent: true, opacity: 0.16 })));

    // Dust: a soft starfield for depth.
    const dustGeo = new THREE.BufferGeometry();
    const DUST = small ? 220 : 420;
    const dust = new Float32Array(DUST * 3);
    for (let i = 0; i < DUST; i++) {
        dust[i * 3] = (Math.random() - 0.5) * 46;
        dust[i * 3 + 1] = (Math.random() - 0.5) * 26;
        dust[i * 3 + 2] = -Math.random() * 22 - 2;
    }
    dustGeo.setAttribute('position', new THREE.BufferAttribute(dust, 3));
    scene.add(new THREE.Points(dustGeo, new THREE.PointsMaterial({ color: 0x9fdcff, size: 0.05, transparent: true, opacity: 0.5 })));

    /* ------------------------------------------------------------- packets */
    const packets = [];
    const packetGeo = new THREE.SphereGeometry(0.095, 10, 8);
    const bursts = [];
    const burstGeo = new THREE.RingGeometry(0.05, 0.12, 24);

    function spawnPacket() {
        const from = nodes[Math.floor(Math.random() * nodes.length)];
        const threat = Math.random() < 0.3;
        const mat = new THREE.MeshBasicMaterial({ color: threat ? RED : CYAN, transparent: true, opacity: 0 });
        const mesh = new THREE.Mesh(packetGeo, mat);
        mesh.position.copy(from);
        net.add(mesh);
        // Trail
        const trailGeo = new THREE.BufferGeometry().setFromPoints([from.clone(), from.clone()]);
        const trail = new THREE.Line(trailGeo, new THREE.LineBasicMaterial({ color: threat ? RED : CYAN, transparent: true, opacity: 0 }));
        net.add(trail);
        packets.push({ mesh, trail, from: from.clone(), threat, t: 0, speed: 0.16 + Math.random() * 0.12 });
    }

    function burst(position) {
        const m = new THREE.Mesh(burstGeo, new THREE.MeshBasicMaterial({ color: RED, transparent: true, opacity: 0.9, side: THREE.DoubleSide, depthWrite: false }));
        m.position.copy(position);
        m.lookAt(camera.position);
        net.add(m);
        bursts.push({ m, t: 0 });
        ring.material.opacity = 0.95; // the ring flashes when it stops something
    }

    /* -------------------------------------------------------------- layout */
    let offsetX = 0;
    function resize() {
        const w = canvas.clientWidth || window.innerWidth;
        const h = canvas.clientHeight || window.innerHeight;
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        // Visible width at z = 0, then move the scene into the free area left of the panel.
        const visibleW = 2 * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * camera.position.z * camera.aspect;
        const visibleH = visibleW / camera.aspect;
        offsetX = w >= 1024 ? -visibleW * 0.2 : 0;
        world.position.x = offsetX;
        // Narrow screens: smaller, in the space above the sign-in panel.
        world.scale.setScalar(w >= 1024 ? 1 : THREE.MathUtils.clamp(visibleW / 12, 0.42, 0.9));
        world.position.y = w >= 1024 ? 0 : visibleH * 0.3;
    }
    window.addEventListener('resize', resize);
    resize();

    const pointer = { x: 0, y: 0 };
    window.addEventListener('pointermove', (ev) => {
        pointer.x = (ev.clientX / window.innerWidth) * 2 - 1;
        pointer.y = (ev.clientY / window.innerHeight) * 2 - 1;
    }, { passive: true });

    /* ---------------------------------------------------------------- loop */
    const clock = new THREE.Clock();
    let spawnClock = 0;
    const target = new THREE.Vector3();

    function frame() {
        const dt = Math.min(clock.getDelta(), 0.05);
        const time = clock.elapsedTime;

        logo.rotation.y += dt * 0.55;
        logo.position.y = Math.sin(time * 0.9) * 0.12;
        logo.rotation.x = Math.sin(time * 0.6) * 0.06;
        net.rotation.y += dt * 0.035;
        ring.rotation.z += dt * 0.25;
        ring2.rotation.z -= dt * 0.12;
        ring.material.opacity += (0.55 - ring.material.opacity) * Math.min(1, dt * 3);
        core.intensity = 16 + Math.sin(time * 2.2) * 3;

        spawnClock += dt;
        const every = small ? 0.32 : 0.2;
        while (spawnClock > every) {
            spawnClock -= every;
            if (packets.length < (small ? 26 : 48)) spawnPacket();
        }

        for (let i = packets.length - 1; i >= 0; i--) {
            const p = packets[i];
            p.t += dt * p.speed;
            const stopAt = p.threat ? 1 - RING_R / p.from.length() : 0.98;
            const tt = Math.min(p.t, stopAt);
            p.mesh.position.copy(p.from).multiplyScalar(1 - tt);
            const fadeIn = Math.min(1, p.t * 6);
            p.mesh.material.opacity = fadeIn;
            const tail = Math.max(0, tt - 0.12);
            const pos = p.trail.geometry.attributes.position;
            pos.setXYZ(0, p.from.x * (1 - tail), p.from.y * (1 - tail), p.from.z * (1 - tail));
            pos.setXYZ(1, p.mesh.position.x, p.mesh.position.y, p.mesh.position.z);
            pos.needsUpdate = true;
            p.trail.material.opacity = 0.45 * fadeIn;
            if (p.t >= stopAt) {
                if (p.threat) burst(p.mesh.position);
                net.remove(p.mesh, p.trail);
                p.mesh.material.dispose();
                p.trail.geometry.dispose();
                p.trail.material.dispose();
                packets.splice(i, 1);
            }
        }

        for (let i = bursts.length - 1; i >= 0; i--) {
            const b = bursts[i];
            b.t += dt;
            b.m.scale.setScalar(1 + b.t * 9);
            b.m.material.opacity = Math.max(0, 0.9 - b.t * 1.8);
            if (b.t > 0.5) {
                net.remove(b.m);
                b.m.material.dispose();
                bursts.splice(i, 1);
            }
        }

        target.set(pointer.x * 1.2, 0.6 - pointer.y * 0.7, 16);
        camera.position.lerp(target, Math.min(1, dt * 1.5));
        camera.lookAt(0, 0, 0);

        renderer.render(scene, camera);
    }

    if (reduceMotion) {
        logo.rotation.y = 0.5;
        for (let i = 0; i < 18; i++) spawnPacket();
        packets.forEach((p) => { p.t = Math.random() * 0.6; });
        frame();
        window.addEventListener('resize', () => renderer.render(scene, camera));
    } else {
        renderer.setAnimationLoop(frame);
    }
    document.documentElement.classList.add('pfc-webgl-ready');
}
