/* global THREE, gsap */
(() => {
    'use strict';

    let active = null;

    const podiumPositions = [
        new THREE.Vector3(0, 1.9, 0.6),
        new THREE.Vector3(-4.3, 0.55, -0.45),
        new THREE.Vector3(4.3, 0.75, -0.7),
        new THREE.Vector3(0, -0.9, -2.0)
    ];

    const fallbackColors = ['#f7c948', '#387af2', '#d7e9ff', '#ff9c4b'];

    function dispose() {
        if (!active) return;
        cancelAnimationFrame(active.frame);
        active.observer?.disconnect();
        
        // Clean DOM labels
        const labelContainer = document.querySelector('#team-labels');
        if (labelContainer) labelContainer.innerHTML = '';

        active.scene.traverse((object) => {
            if (object.material) {
                const materials = Array.isArray(object.material) ? object.material : [object.material];
                materials.forEach((mat) => {
                    if (mat.map) mat.map.dispose();
                    mat.dispose();
                });
            }
            object.geometry?.dispose?.();
        });
        active.renderer.dispose();
        active = null;
    }

    function color(value, fallback) {
        return new THREE.Color(/^#[0-9a-f]{6}$/i.test(String(value || '')) ? value : fallback);
    }

    function makeCrystal(colorHex) {
        const group = new THREE.Group();
        const c = color(colorHex, '#10b981');
        
        const material = new THREE.MeshPhysicalMaterial({
            color: c,
            metalness: 0.08,
            roughness: 0.08,
            transmission: 0.35,
            thickness: 1.1,
            transparent: true,
            opacity: 0.92,
            emissive: c,
            emissiveIntensity: 0.42
        });

        const crystal = new THREE.Mesh(new THREE.OctahedronGeometry(1.05, 1), material);
        crystal.scale.set(0.9, 1.45, 0.9);
        crystal.rotation.set(0.2, 0.25, 0);

        const ring = new THREE.Mesh(
            new THREE.TorusGeometry(1.18, 0.035, 8, 48),
            new THREE.MeshBasicMaterial({ color: c, transparent: true, opacity: 0.65, side: THREE.DoubleSide })
        );
        ring.rotation.x = Math.PI / 2;
        ring.position.y = -1.25;

        const light = new THREE.PointLight(c, 3.5, 7, 2);
        light.position.set(0, 0.25, 0);

        group.add(crystal, ring, light);
        group.userData = { crystal, ring, light };
        return group;
    }

    function makeLabelElement(team) {
        const label = document.createElement('article');
        label.className = 'team-label';
        label.style.setProperty('--team-color', team.team_color || '#10b981');
        label.innerHTML = `
            <span class="team-label__rank"></span>
            <span class="team-label__name"></span>
            <span class="team-label__score"></span>
        `;
        const container = document.querySelector('#team-labels');
        if (container) container.append(label);
        return label;
    }

    function addSwarmParticles(scene) {
        const count = 620;
        const positions = new Float32Array(count * 3);
        for (let index = 0; index < count; index += 1) {
            positions[index * 3] = (Math.random() - 0.5) * 23;
            positions[index * 3 + 1] = Math.random() * 11 - 2;
            positions[index * 3 + 2] = (Math.random() - 0.5) * 20;
        }
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        const particles = new THREE.Points(geometry, new THREE.PointsMaterial({
            color: '#f7cd76',
            size: 0.035,
            transparent: true,
            opacity: 0.75,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        }));
        scene.add(particles);
        return particles;
    }

    function mount(canvas, teams) {
        dispose();
        if (!canvas || !window.THREE || !window.WebGLRenderingContext) return false;

        let championId = null;

        try {
            const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'high-performance' });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            renderer.toneMappingExposure = 1.18;

            const scene = new THREE.Scene();
            scene.background = new THREE.Color('#040812');
            scene.fog = new THREE.FogExp2('#071020', 0.032);

            const camera = new THREE.PerspectiveCamera(42, window.innerWidth / window.innerHeight, 0.1, 100);
            camera.position.set(0, 4.7, 15.5);
            const lookAt = new THREE.Vector3(0, 0.55, 0);
            camera.lookAt(lookAt);

            // Postprocessing EffectComposer with UnrealBloomPass if available
            let composer = null;
            try {
                if (window.THREE && window.THREE.EffectComposer && window.THREE.RenderPass && window.THREE.UnrealBloomPass) {
                    composer = new THREE.EffectComposer(renderer);
                    composer.addPass(new THREE.RenderPass(scene, camera));
                    const bloomPass = new THREE.UnrealBloomPass(
                        new THREE.Vector2(window.innerWidth, window.innerHeight),
                        0.75, 0.65, 0.2
                    );
                    composer.addPass(bloomPass);
                }
            } catch (e) {
                console.warn('Postprocessing bloom notice:', e);
                composer = null;
            }

            // Environment Lights
            scene.add(new THREE.HemisphereLight('#7897db', '#060307', 1.55));
            const keyLight = new THREE.DirectionalLight('#ffe4a0', 3.2);
            keyLight.position.set(-5, 9, 5);
            const blueLight = new THREE.PointLight('#387af2', 16, 30, 2);
            blueLight.position.set(-7, 4, 3);
            const warmLight = new THREE.PointLight('#f1a04e', 13, 28, 2);
            warmLight.position.set(7, 3, -2);
            scene.add(keyLight, blueLight, warmLight);

            // Floor & Gold Rings
            const floor = new THREE.Mesh(
                new THREE.CircleGeometry(19, 96),
                new THREE.MeshPhysicalMaterial({ color: '#07101a', metalness: 0.65, roughness: 0.19, clearcoat: 0.75 })
            );
            floor.rotation.x = -Math.PI / 2;
            floor.position.y = -2.15;
            scene.add(floor);

            const goldMat = new THREE.MeshStandardMaterial({
                color: '#dca940', metalness: 0.95, roughness: 0.2, emissive: '#4a2803', emissiveIntensity: 0.35
            });
            for (let i = 0; i < 4; i++) {
                const ring = new THREE.Mesh(new THREE.TorusGeometry(4.6 + i * 0.95, 0.014, 8, 96), goldMat);
                ring.rotation.x = Math.PI / 2;
                ring.position.y = -2.1;
                scene.add(ring);
            }

            // Arches
            const archMat = new THREE.MeshStandardMaterial({ color: '#16243b', metalness: 0.7, roughness: 0.25 });
            for (let i = 0; i < 8; i++) {
                const angle = (i / 8) * Math.PI * 2;
                const group = new THREE.Group();
                const colGeo = new THREE.CylinderGeometry(0.28, 0.38, 5, 20);
                const left = new THREE.Mesh(colGeo, archMat);
                const right = left.clone();
                left.position.x = -1.55;
                right.position.x = 1.55;
                const arch = new THREE.Mesh(new THREE.TorusGeometry(1.55, 0.24, 12, 32, Math.PI), archMat);
                arch.rotation.z = Math.PI;
                arch.position.y = 2.5;
                const crest = new THREE.Mesh(new THREE.SphereGeometry(0.12, 12, 12), goldMat);
                crest.position.y = 4.15;
                group.add(left, right, arch, crest);
                group.position.set(Math.sin(angle) * 11.5, 0.35, Math.cos(angle) * 11.5);
                group.rotation.y = -angle;
                scene.add(group);
            }

            // Rotating Trophy
            const trophyGroup = new THREE.Group();
            const goldTrophyMat = new THREE.MeshPhysicalMaterial({
                color: '#f4bd46', metalness: 1, roughness: 0.16, emissive: '#8b4e06', emissiveIntensity: 0.55
            });
            const cup = new THREE.Mesh(new THREE.CylinderGeometry(0.72, 1.12, 1.4, 48, 1, true), goldTrophyMat);
            cup.position.y = 4.3;
            const stem = new THREE.Mesh(new THREE.CylinderGeometry(0.16, 0.24, 0.8, 28), goldTrophyMat);
            stem.position.y = 3.15;
            const base = new THREE.Mesh(new THREE.CylinderGeometry(0.8, 1.05, 0.26, 48), goldTrophyMat);
            base.position.y = 2.72;
            const halo = new THREE.Mesh(
                new THREE.TorusGeometry(1.85, 0.035, 8, 72),
                new THREE.MeshBasicMaterial({ color: '#ffd36d', transparent: true, opacity: 0.75 })
            );
            halo.position.y = 4.25;
            halo.rotation.x = Math.PI / 2;
            trophyGroup.add(cup, stem, base, halo);
            scene.add(trophyGroup);

            // Particles
            const particles = addSwarmParticles(scene);

            // Teams & Crystal Map
            const teamMap = new Map();

            const updateTeamsData = (records) => {
                const effectiveRecords = (Array.isArray(records) && records.length > 0) ? records : [];
                const ranked = [...effectiveRecords].sort((a, b) => (Number(b.total_score || b.score || 0)) - (Number(a.total_score || a.score || 0)));
                
                ranked.forEach((record, rankIndex) => {
                    const teamHex = record.team_color || fallbackColors[rankIndex] || '#10b981';
                    const teamId = record.id || record.team_id || `team_${rankIndex}`;
                    let entry = teamMap.get(teamId);

                    if (!entry) {
                        const object = makeCrystal(teamHex);
                        const targetPos = podiumPositions[rankIndex] || podiumPositions[3];
                        object.position.copy(targetPos).add(new THREE.Vector3(0, 1, 0));
                        scene.add(object);

                        const label = makeLabelElement({ ...record, color: teamHex });
                        entry = { object, label, score: Number(record.total_score || record.score || 0), record, rank: rankIndex };
                        teamMap.set(teamId, entry);
                    }

                    const previousRank = entry.rank;
                    entry.rank = rankIndex;
                    entry.record = record;

                    const label = entry.label;
                    const rankLabelEl = label.querySelector('.team-label__rank');
                    const nameLabelEl = label.querySelector('.team-label__name');
                    const scoreLabelEl = label.querySelector('.team-label__score');

                    if (rankLabelEl) rankLabelEl.textContent = `RANK ${rankIndex + 1}`;
                    if (nameLabelEl) nameLabelEl.textContent = record.short_name || record.team_name || `Team ${rankIndex + 1}`;
                    if (scoreLabelEl) scoreLabelEl.textContent = Number(record.total_score || record.score || 0).toLocaleString();
                    label.classList.toggle('is-leading', rankIndex === 0);
                    label.style.setProperty('--team-color', teamHex);

                    // GSAP Position Swap Animation
                    const targetPos = podiumPositions[rankIndex] || podiumPositions[3];
                    if (window.gsap) {
                        gsap.to(entry.object.position, {
                            x: targetPos.x, y: targetPos.y, z: targetPos.z,
                            duration: previousRank === rankIndex ? 0.8 : 1.75,
                            ease: 'power3.inOut'
                        });
                        const targetScale = rankIndex === 0 ? 1.16 : 0.88;
                        gsap.to(entry.object.scale, {
                            x: targetScale, y: targetScale, z: targetScale,
                            duration: 1.15,
                            ease: 'back.out(1.5)'
                        });
                        gsap.to(entry.object.userData.crystal.material, {
                            emissiveIntensity: rankIndex === 0 ? 0.95 : 0.35,
                            duration: 0.75
                        });

                        const scoreObj = { value: entry.score };
                        const finalScore = Number(record.total_score || record.score || 0);
                        gsap.to(scoreObj, {
                            value: finalScore,
                            duration: 1.2,
                            ease: 'power2.out',
                            onUpdate: () => {
                                if (scoreLabelEl) scoreLabelEl.textContent = Math.round(scoreObj.value).toLocaleString();
                            }
                        });
                    } else {
                        entry.object.position.copy(targetPos);
                    }

                    entry.score = Number(record.total_score || record.score || 0);
                });

                // Champion Focus camera motion
                if (ranked[0] && ranked[0].id !== championId) {
                    championId = ranked[0].id;
                    const championObj = teamMap.get(championId)?.object;
                    if (championObj && window.gsap) {
                        const targetVec = new THREE.Vector3();
                        championObj.getWorldPosition(targetVec);
                        gsap.to(lookAt, { x: targetVec.x * 0.24, y: targetVec.y * 0.16 + 0.45, z: 0, duration: 1.6, ease: 'power2.inOut' });
                        gsap.fromTo(trophyGroup.scale, { x: 1, y: 1, z: 1 }, { x: 1.18, y: 1.18, z: 1.18, duration: 0.45, yoyo: true, repeat: 1 });
                    }
                }
            };

            updateTeamsData(teams);

            const clock = new THREE.Clock();
            const stage = {
                renderer, composer, scene, camera, lookAt, particles, trophyGroup,
                teamMap, updateTeamsData, frame: 0, observer: null
            };
            active = stage;

            // GLB Model loader if available
            let glbModel = null;
            const modelUrl = window.LIVE_DISPLAY_GLB_MODEL_URL || window.TV_GLB_MODEL_URL;
            if (window.THREE && window.THREE.GLTFLoader && modelUrl) {
                const gltfLoader = new THREE.GLTFLoader();
                gltfLoader.load(modelUrl, (gltf) => {
                    if (!active || active.renderer !== renderer) return;
                    glbModel = gltf.scene;

                    // Auto-scale and center GLB model
                    try {
                        const box = new THREE.Box3().setFromObject(glbModel);
                        const size = box.getSize(new THREE.Vector3());
                        const maxDim = Math.max(size.x, size.y, size.z);
                        if (maxDim > 0) {
                            const scaleFactor = 4.5 / maxDim;
                            glbModel.scale.setScalar(scaleFactor);
                        }
                        glbModel.position.set(0, -0.2, 0);

                        glbModel.traverse((child) => {
                            if (child.isMesh) {
                                child.castShadow = true;
                                child.receiveShadow = true;
                                if (child.material) {
                                    child.material.needsUpdate = true;
                                }
                            }
                        });
                    } catch (e) {
                        console.warn('GLB processing notice:', e);
                    }

                    scene.add(glbModel);
                    if (active) active.glbModel = glbModel;
                }, undefined, (err) => console.warn('GLB load note:', err));
            }

            const resize = () => {
                const width = canvas.clientWidth || canvas.parentElement?.clientWidth || window.innerWidth;
                const height = canvas.clientHeight || canvas.parentElement?.clientHeight || window.innerHeight;
                renderer.setSize(width, height, false);
                if (composer) composer.setSize(width, height);
                camera.aspect = width / Math.max(height, 1);
                camera.updateProjectionMatrix();
            };
            stage.observer = new ResizeObserver(resize);
            stage.observer.observe(canvas);
            resize();

            // Screen-space 3D Label Projection Loop
            const projectVec = new THREE.Vector3();

            const animate = () => {
                if (active !== stage || !canvas.isConnected) return dispose();
                const elapsed = clock.getElapsedTime();

                // Slow Camera Orbit
                camera.position.x = Math.sin(elapsed * 0.09) * 1.35;
                camera.position.y = 4.55 + Math.sin(elapsed * 0.14) * 0.23;
                camera.lookAt(lookAt);

                trophyGroup.rotation.y += 0.005;
                particles.rotation.y -= 0.00032;

                // Animate Crystal Oscillation & Torus Ring Rotation
                teamMap.forEach((entry, idx) => {
                    entry.object.children[0].position.y = Math.sin(elapsed * 1.1 + idx * 1.7) * 0.18;
                    entry.object.userData.ring.rotation.z += 0.005 + idx * 0.0007;
                    entry.object.rotation.y += 0.0025;

                    // Project 3D World Position to 2D Screen Coordinates
                    entry.object.getWorldPosition(projectVec);
                    projectVec.y += 1.75;
                    projectVec.project(camera);

                    const width = canvas.clientWidth || window.innerWidth;
                    const height = canvas.clientHeight || window.innerHeight;
                    entry.label.style.opacity = projectVec.z < 1 ? '1' : '0';
                    entry.label.style.left = `${(projectVec.x * 0.5 + 0.5) * width}px`;
                    entry.label.style.top = `${(-projectVec.y * 0.5 + 0.5) * height}px`;
                });

                if (composer) {
                    composer.render();
                } else {
                    renderer.render(scene, camera);
                }

                stage.frame = requestAnimationFrame(animate);
            };
            animate();
            return true;
        } catch (error) {
            console.warn('Three.js 3D Broadcast engine error:', error);
            dispose();
            return false;
        }
    }

    function update(teams) {
        if (!active || !active.updateTeamsData) return;
        active.updateTeamsData(teams);
    }

    window.TVLeaderboard3D = { mount, update, dispose };
})();
