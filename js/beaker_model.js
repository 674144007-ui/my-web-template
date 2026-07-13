import * as THREE from 'three';

export function createBeakerModel() {
    const group = new THREE.Group();
    group.name = "beaker_container";

    // ---------------------------------------------------
    // 1. ตัวแก้ว (Glass Beaker)
    // ---------------------------------------------------
    const glassGeo = new THREE.CylinderGeometry(3, 3, 7, 32, 1, true);
    const glassMat = new THREE.MeshPhysicalMaterial({
        color: 0xffffff,
        metalness: 0.1,
        roughness: 0.05,
        transmission: 1.0, // โปร่งใสแบบแก้ว
        thickness: 0.5,
        side: THREE.DoubleSide,
        depthWrite: false, // ช่วยเรื่องการแสดงผลซ้อนทับ
        transparent: true
    });
    const glass = new THREE.Mesh(glassGeo, glassMat);
    glass.position.y = 3.5;
    glass.renderOrder = 1; // เรนเดอร์ทีหลังสุด
    group.add(glass);

    // ก้นแก้ว (Bottom)
    const bottomGeo = new THREE.CircleGeometry(3, 32);
    const bottomMat = new THREE.MeshPhysicalMaterial({
        color: 0xffffff,
        metalness: 0.1,
        roughness: 0.05,
        transmission: 1.0,
        thickness: 0.5,
        transparent: true,
        side: THREE.DoubleSide
    });
    const bottom = new THREE.Mesh(bottomGeo, bottomMat);
    bottom.rotation.x = -Math.PI / 2;
    bottom.position.y = 0.1;
    group.add(bottom);

    // ---------------------------------------------------
    // 2. ของเหลว (Liquid) - พระเอกของเรา
    // ---------------------------------------------------
    const liquidGeo = new THREE.CylinderGeometry(2.85, 2.85, 4.5, 32);
    const liquidMat = new THREE.MeshPhysicalMaterial({
        color: 0xccddff, // สีเริ่มต้น (น้ำใส)
        transmission: 0.6,
        opacity: 0.8,
        transparent: true,
        roughness: 0.1,
        metalness: 0
    });
    const liquid = new THREE.Mesh(liquidGeo, liquidMat);
    liquid.position.y = 2.4;
    liquid.name = "liquid"; // 🔥 สำคัญมาก: 3d_engine หาชื่อนี้
    liquid.visible = true;
    group.add(liquid);

    // ---------------------------------------------------
    // 3. ของแข็ง/ตะกอน (Solid)
    // ---------------------------------------------------
    const solidGroup = new THREE.Group();
    solidGroup.name = "solid"; // 🔥 สำคัญมาก: 3d_engine หาชื่อนี้
    
    // สร้างก้อนหิน/ผลึก 20 ก้อน เตรียมไว้
    const chunkGeo = new THREE.DodecahedronGeometry(0.5, 0); 
    const chunkMat = new THREE.MeshStandardMaterial({ 
        color: 0x888888, 
        roughness: 0.8,
        metalness: 0.2
    });

    for(let i=0; i<20; i++) {
        const chunk = new THREE.Mesh(chunkGeo, chunkMat.clone()); // clone เพื่อให้เปลี่ยนสีแยกกันได้
        // สุ่มตำแหน่งกองๆ กันข้างล่าง
        chunk.position.set(
            (Math.random() - 0.5) * 4, 
            0.5 + Math.random() * 1.5, 
            (Math.random() - 0.5) * 4
        );
        chunk.rotation.set(Math.random()*Math.PI, Math.random()*Math.PI, 0);
        chunk.scale.setScalar(0.5 + Math.random() * 0.5);
        solidGroup.add(chunk);
    }
    solidGroup.visible = false; // ซ่อนไว้ก่อน
    group.add(solidGroup);

    // ---------------------------------------------------
    // 4. แก๊ส/ควัน (Gas)
    // ---------------------------------------------------
    const gasGroup = new THREE.Group();
    gasGroup.name = "gas"; // 🔥 สำคัญมาก: 3d_engine หาชื่อนี้

    const gasCount = 40;
    const gasGeo = new THREE.SphereGeometry(0.2, 8, 8);
    const gasMat = new THREE.MeshBasicMaterial({ 
        color: 0xffffff, 
        transparent: true, 
        opacity: 0.5 
    });

    for(let i=0; i<gasCount; i++) {
        const particle = new THREE.Mesh(gasGeo, gasMat.clone());
        particle.position.set(
            (Math.random() - 0.5) * 4,
            1 + Math.random() * 5,
            (Math.random() - 0.5) * 4
        );
        gasGroup.add(particle);
    }
    gasGroup.visible = false; // ซ่อนไว้ก่อน
    group.add(gasGroup);

    return group;
}