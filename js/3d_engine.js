/**
 * 3d_engine.js — WebGL Virtual Lab Engine (Clean Architecture)
 * ────────────────────────────────────────────────────────────
 * ระยะที่ 4:
 *   - Touch Gesture Isolation: ล็อก touch events บน canvas ไม่ให้ทะลุ browser
 *   - Adaptive Pixel Ratio: ลด pixelRatio บนมือถือเพื่อประหยัด GPU
 *   - Garbage Collection: dispose geometry+material ทันทีที่ไม่ใช้
 *   - cleanup3DScene(): เรียกเมื่อเปลี่ยนหน้า เพื่อป้องกัน Memory Leak
 * ────────────────────────────────────────────────────────────
 */

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/OrbitControls.js';
import { createBeakerModel } from './beaker_model.js';

let scene, camera, renderer, controls, beaker;
let particles = [];
let shakeTime = 0;
let animFrameId = null;
let colorInterval = null;
const originalCameraPos = new THREE.Vector3(0, 9, 14);

// ── Init ──────────────────────────────────────────────────────
export function init3DScene(container) {
    scene = new THREE.Scene();

    // Camera
    const aspect = container.clientWidth / container.clientHeight;
    camera = new THREE.PerspectiveCamera(50, aspect, 0.1, 100);
    camera.position.copy(originalCameraPos);

    // Renderer — ลด pixelRatio บนมือถือเพื่อประหยัด GPU
    const isMobile = window.innerWidth < 768;
    renderer = new THREE.WebGLRenderer({ antialias: !isMobile, alpha: true });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, isMobile ? 1.5 : 2));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.1;
    renderer.shadowMap.enabled = !isMobile; // ปิด shadow บนมือถือ
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;

    while (container.firstChild) container.removeChild(container.firstChild);
    container.appendChild(renderer.domElement);

    // ── Touch Isolation ───────────────────────────────────────
    // ป้องกัน browser scroll/zoom เวลาผู้ใช้หมุนโมเดล 3D
    renderer.domElement.style.touchAction = 'none';
    renderer.domElement.addEventListener('touchstart', (e) => e.stopPropagation(), { passive: false });
    renderer.domElement.addEventListener('touchmove',  (e) => e.stopPropagation(), { passive: false });
    renderer.domElement.addEventListener('touchend',   (e) => e.stopPropagation(), { passive: false });

    // OrbitControls
    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping  = true;
    controls.dampingFactor  = 0.05;
    controls.target.set(0, 3, 0);
    controls.minDistance    = 5;
    controls.maxDistance    = 25;
    controls.maxPolarAngle  = Math.PI / 2 + 0.1;
    // รองรับ pinch-to-zoom บนมือถือ (OrbitControls รองรับอยู่แล้ว)
    controls.enableZoom     = true;
    controls.zoomSpeed      = 0.8;
    controls.rotateSpeed    = 0.6;

    // Lighting
    scene.add(new THREE.AmbientLight(0xffffff, 0.6));

    const mainLight = new THREE.DirectionalLight(0xffffff, 1.2);
    mainLight.position.set(5, 12, 8);
    if (!isMobile) {
        mainLight.castShadow = true;
        mainLight.shadow.mapSize.width  = 1024;
        mainLight.shadow.mapSize.height = 1024;
    }
    scene.add(mainLight);

    const rimLight = new THREE.PointLight(0x38bdf8, 0.8, 20);
    rimLight.position.set(-6, 4, -6);
    scene.add(rimLight);

    // Beaker Model
    beaker = createBeakerModel();
    if (beaker) {
        beaker.position.y = 0;
        scene.add(beaker);
    } else {
        console.warn('⚠️ Model not found, using fallback geometry.');
        const geo = new THREE.CylinderGeometry(1.5, 1.5, 3, 32);
        const mat = new THREE.MeshPhysicalMaterial({
            color: 0xffffff, transparent: true, opacity: 0.2,
            transmission: 0.9, roughness: 0.1, ior: 1.5,
        });
        beaker = new THREE.Mesh(geo, mat);
        beaker.castShadow = true;
        scene.add(beaker);

        const liquidGeo = new THREE.CylinderGeometry(1.4, 1.4, 2, 32);
        const liquidMat = new THREE.MeshPhysicalMaterial({ color: 0xccddff, transparent: true, opacity: 0.8 });
        const liquid    = new THREE.Mesh(liquidGeo, liquidMat);
        liquid.position.y = -0.4;
        liquid.name = 'liquid';
        beaker.add(liquid);
    }

    // Responsive resize
    window.addEventListener('resize', _onResize);

    _animate();
}

// ── Cleanup (เรียกเมื่อออกจากหน้า lab) ───────────────────────
export function cleanup3DScene() {
    // หยุด animation loop
    if (animFrameId !== null) {
        cancelAnimationFrame(animFrameId);
        animFrameId = null;
    }

    // หยุด color interval
    if (colorInterval !== null) {
        clearInterval(colorInterval);
        colorInterval = null;
    }

    // ล้าง particles ที่ยังค้างอยู่
    _disposeParticles();

    // ล้าง scene objects
    if (scene) {
        scene.traverse((obj) => {
            if (obj.geometry) obj.geometry.dispose();
            if (obj.material) {
                if (Array.isArray(obj.material)) {
                    obj.material.forEach(m => m.dispose());
                } else {
                    obj.material.dispose();
                }
            }
        });
        scene.clear();
    }

    // ล้าง renderer
    if (renderer) {
        renderer.dispose();
        renderer.forceContextLoss();
        renderer.domElement.remove();
        renderer = null;
    }

    // ล้าง controls
    if (controls) { controls.dispose(); controls = null; }

    // ล้าง resize listener
    window.removeEventListener('resize', _onResize);

    scene = null; camera = null; beaker = null;
    particles = [];
}

// ── Liquid Visuals ───────────────────────────────────────────
export function updateLiquidVisuals(result) {
    if (!beaker) return;

    const liquid = beaker.getObjectByName('liquid');
    if (!liquid) return;

    const getColorHex = (name) => {
        if (!name) return 0xFFFFFF;
        if (name.startsWith('#')) return parseInt(name.replace('#', '0x'), 16);
        if (name === 'ใส') return 0xccddff;
        return 0xFFFFFF;
    };

    const colorHex   = getColorHex(result.color || '#FFFFFF');
    const targetColor = new THREE.Color(colorHex);

    // หยุด interval เก่าก่อนเริ่มใหม่
    if (colorInterval !== null) clearInterval(colorInterval);
    let t = 0;
    colorInterval = setInterval(() => {
        t += 0.05;
        liquid.material.color.lerp(targetColor, 0.1);
        if (t >= 1) { clearInterval(colorInterval); colorInterval = null; }
    }, 30);

    liquid.visible = true;
    liquid.material.opacity = (result.color === 'ใส' || result.color === '#FFFFFF') ? 0.4 : 0.9;

    if (result.is_explosion) {
        _triggerCameraShake(40);
        _createParticles('explosion', 100, 0xff4400);
        liquid.material.color.setHex(0x111111);
    } else if (result.gas && result.gas !== 'ไม่มีแก๊ส') {
        _createParticles('gas', 50, 0xdddddd);
    }
}

// ── Private Helpers ──────────────────────────────────────────
function _onResize() {
    const container = renderer?.domElement?.parentElement;
    if (!renderer || !camera || !container) return;
    camera.aspect = container.clientWidth / container.clientHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(container.clientWidth, container.clientHeight);
}

function _triggerCameraShake(duration = 30) {
    shakeTime = duration;
}

function _createParticles(type, count, colorHex) {
    const geometry = new THREE.BufferGeometry();
    const vertices = [], sizes = [];

    for (let i = 0; i < count; i++) {
        vertices.push(
            (Math.random() - 0.5) * 2,
            Math.random() * 1.5 + 1,
            (Math.random() - 0.5) * 2,
        );
        sizes.push(Math.random() * 0.2 + 0.1);
    }

    geometry.setAttribute('position', new THREE.Float32BufferAttribute(vertices, 3));
    geometry.setAttribute('size',     new THREE.Float32BufferAttribute(sizes, 1));

    const material = new THREE.PointsMaterial({
        color: colorHex,
        size: type === 'gas' ? 0.3 : 0.5,
        transparent: true,
        opacity: 0.8,
        blending:   type === 'explosion' ? THREE.AdditiveBlending : THREE.NormalBlending,
        depthWrite: false,
    });

    const ps = new THREE.Points(geometry, material);
    ps.userData = { type, life: 1.0, maxLife: type === 'gas' ? 0.8 : 0.5 };
    scene.add(ps);
    particles.push(ps);
}

function _disposeParticles() {
    for (const p of particles) {
        scene?.remove(p);
        p.geometry.dispose();
        p.material.dispose();
    }
    particles = [];
}

function _animate() {
    animFrameId = requestAnimationFrame(_animate);

    // Camera Shake
    if (shakeTime > 0) {
        const mag = (shakeTime / 30) * 0.5;
        camera.position.x = originalCameraPos.x + (Math.random() - 0.5) * mag;
        camera.position.y = originalCameraPos.y + (Math.random() - 0.5) * mag;
        camera.position.z = originalCameraPos.z + (Math.random() - 0.5) * mag;
        shakeTime--;
    } else {
        camera.position.lerp(originalCameraPos, 0.1);
    }

    // Particles — dispose เมื่อหมดอายุ
    for (let i = particles.length - 1; i >= 0; i--) {
        const p = particles[i];
        p.userData.life -= 0.01;

        const pos = p.geometry.attributes.position.array;
        for (let j = 0; j < pos.length; j += 3) {
            pos[j + 1] += p.userData.type === 'gas' ? 0.05 : 0.02;
            pos[j]     += (Math.random() - 0.5) * 0.02;
        }
        p.geometry.attributes.position.needsUpdate = true;
        p.material.opacity = p.userData.life / p.userData.maxLife;

        if (p.userData.life <= 0) {
            scene.remove(p);
            p.geometry.dispose();
            p.material.dispose();
            particles.splice(i, 1);
        }
    }

    if (controls) controls.update();
    if (renderer && scene && camera) renderer.render(scene, camera);
}
