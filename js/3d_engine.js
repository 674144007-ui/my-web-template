import * as THREE from 'three';
import { OrbitControls } from 'three/addons/OrbitControls.js'; // เรียกผ่าน Map ที่แก้แล้ว
import { createBeakerModel } from './beaker_model.js'; 

let scene, camera, renderer, controls, beaker;

// ✅ มี export function init3DScene
export function init3DScene(container) {
    scene = new THREE.Scene();
    
    // Camera
    const aspect = container.clientWidth / container.clientHeight;
    camera = new THREE.PerspectiveCamera(50, aspect, 0.1, 100);
    camera.position.set(0, 9, 14);

    // Renderer
    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.setPixelRatio(window.devicePixelRatio);
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.0;
    
    while(container.firstChild) container.removeChild(container.firstChild);
    container.appendChild(renderer.domElement);

    // Controls
    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.target.set(0, 3, 0);

    // Lights
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.7);
    scene.add(ambientLight);
    const mainLight = new THREE.DirectionalLight(0xffffff, 1.2);
    mainLight.position.set(5, 10, 7);
    scene.add(mainLight);
    const rimLight = new THREE.PointLight(0x00aaff, 0.5);
    rimLight.position.set(-5, 5, -5);
    scene.add(rimLight);

    // Load Model
    beaker = createBeakerModel(); 
    if(beaker) {
        beaker.position.y = 0;
        scene.add(beaker);
    } else {
        console.warn("⚠️ Model not found, using dummy.");
        const geo = new THREE.CylinderGeometry(1.5, 1.5, 3, 32);
        const mat = new THREE.MeshPhongMaterial({ color: 0xcccccc, transparent: true, opacity: 0.3 });
        beaker = new THREE.Mesh(geo, mat);
        scene.add(beaker);
        
        // Dummy parts
        const liquid = new THREE.Mesh(new THREE.CylinderGeometry(1.4,1.4,2,32), new THREE.MeshBasicMaterial({color:0xffffff}));
        liquid.name = "liquid"; beaker.add(liquid);
        const gas = new THREE.Mesh(new THREE.BoxGeometry(0.1,0.1,0.1), new THREE.MeshBasicMaterial({visible:false}));
        gas.name = "gas"; beaker.add(gas);
        const solid = new THREE.Mesh(new THREE.BoxGeometry(0.1,0.1,0.1), new THREE.MeshBasicMaterial({visible:false}));
        solid.name = "solid"; beaker.add(solid);
    }

    animate();
    
    window.addEventListener('resize', () => {
        if(renderer && camera && container) {
            camera.aspect = container.clientWidth / container.clientHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(container.clientWidth, container.clientHeight);
        }
    });
}

function animate() {
    requestAnimationFrame(animate);
    
    if (beaker) {
        const gasGroup = beaker.getObjectByName("gas");
        if (gasGroup && gasGroup.visible && gasGroup.children.length > 0) {
            gasGroup.children.forEach(p => {
                p.position.y += 0.03;
                p.rotation.x += 0.01;
                if(p.position.y > 8) {
                    p.position.y = 1;
                    p.position.x = (Math.random() - 0.5) * 4;
                    p.position.z = (Math.random() - 0.5) * 4;
                }
            });
        }
    }

    if(controls) controls.update();
    if(renderer && scene && camera) renderer.render(scene, camera);
}

// ✅ มี export function updateLiquidVisuals
export function updateLiquidVisuals(result) {
    if (!beaker) return;

    const liquid = beaker.getObjectByName("liquid");
    const solid = beaker.getObjectByName("solid");
    const gas = beaker.getObjectByName("gas");

    if(!liquid || !solid || !gas) return;

    const getColorHex = (name) => {
        if(!name) return 0xFFFFFF;
        if (name.startsWith('#')) return parseInt(name.replace('#', '0x'), 16);
        if (name === 'ใส') return 0xccddff;
        return 0xFFFFFF;
    };

    let colorString = result.special_color || result.liquid_color || "#FFFFFF";
    let colorHex = getColorHex(colorString);

    liquid.visible = false;
    solid.visible = false;
    gas.visible = false;

    const state = result.final_state || 'liquid';

    if (state === 'gas' || result.is_explosion) {
        gas.visible = true;
        let gasColor = result.bubble_color || colorString;
        let gasHex = getColorHex(gasColor);
        if(gas.children.length > 0) {
            gas.children.forEach(p => {
                p.material.color.setHex(result.is_explosion ? 0x333333 : gasHex);
                p.material.opacity = 0.8;
            });
        }
        if (result.is_explosion) {
             liquid.visible = true;
             liquid.material.color.setHex(0x000000);
        }

    } else if (state === 'solid') {
        solid.visible = true;
        if(solid.children.length > 0) {
            solid.children.forEach(chunk => chunk.material.color.setHex(colorHex));
        } else if(solid.material) {
            solid.material.color.setHex(colorHex);
        }

    } else {
        liquid.visible = true;
        liquid.material.color.setHex(colorHex);
        liquid.material.opacity = (colorString === 'ใส' || colorString === '#FFFFFF') ? 0.3 : 0.9;

        if (result.precipitate && result.precipitate !== "ไม่มีตะกอน") {
            solid.visible = true;
            if(solid.children.length > 0) {
                solid.children.forEach(chunk => {
                    chunk.material.color.setHex(colorHex);
                    chunk.scale.set(0.5, 0.5, 0.5);
                    chunk.position.y = 0.5;
                });
            }
        }
        
        if ((result.gas && result.gas !== "ไม่มีแก๊ส") || result.has_bubbles) {
            gas.visible = true;
            let bubbleHex = getColorHex(result.bubble_color || "#FFFFFF");
            if(gas.children.length > 0) {
                gas.children.forEach(p => {
                    p.material.color.setHex(bubbleHex);
                    p.material.opacity = 0.6;
                });
            }
        }
    }
}