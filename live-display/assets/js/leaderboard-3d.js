/* global THREE */
(() => {
    'use strict';

    let active = null;

    function dispose() {
        if (!active) return;
        cancelAnimationFrame(active.frame);
        active.observer?.disconnect();
        active.scene.traverse((object) => {
            object.geometry?.dispose?.();
            const materials = Array.isArray(object.material) ? object.material : [object.material];
            materials.filter(Boolean).forEach((material) => material.dispose?.());
        });
        active.renderer.dispose();
        active = null;
    }

    function color(value, fallback) {
        return new THREE.Color(/^#[0-9a-f]{6}$/i.test(String(value || '')) ? value : fallback);
    }

    function glow(value, intensity = 2, opacity = 1) {
        return new THREE.MeshStandardMaterial({
            color: value,
            emissive: value,
            emissiveIntensity: intensity,
            metalness: 0.72,
            roughness: 0.2,
            transparent: opacity < 1,
            opacity
        });
    }

    function addTruss(scene) {
        const metal = new THREE.MeshStandardMaterial({ color: 0x17233b, metalness: 0.9, roughness: 0.28 });
        const blue = glow(0x2563eb, 1.2, 0.75);
        const beam = (x, y, sx, sy, material = metal) => {
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(sx, sy, 0.12), material);
            mesh.position.set(x, y, -1.8);
            scene.add(mesh);
        };
        beam(0, 5.7, 15, 0.12);
        beam(-7.4, 2.8, 0.12, 5.8);
        beam(7.4, 2.8, 0.12, 5.8);
        for (let x = -7; x <= 7; x += 1) beam(x, 5.7, 0.035, 0.3, blue);
    }

    function addParticles(scene) {
        const count = 420;
        const positions = new Float32Array(count * 3);
        for (let i = 0; i < count; i += 1) {
            positions[i * 3] = (Math.random() - 0.5) * 20;
            positions[i * 3 + 1] = Math.random() * 8 - 0.8;
            positions[i * 3 + 2] = Math.random() * 7 - 4;
        }
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        const points = new THREE.Points(geometry, new THREE.PointsMaterial({
            color: 0x8cc8ff,
            size: 0.035,
            transparent: true,
            opacity: 0.72,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        }));
        scene.add(points);
        return points;
    }

    function addPodium(scene, team, x, height, index) {
        const group = new THREE.Group();
        const teamColor = color(team.color, ['#f7c948', '#c026d3', '#22c55e', '#168cff'][index]);
        group.position.x = x;
        group.scale.y = 0.001;

        const base = new THREE.Mesh(
            new THREE.CylinderGeometry(1.28, 1.42, 0.48, 64),
            new THREE.MeshStandardMaterial({ color: 0x05070c, metalness: 0.94, roughness: 0.2 })
        );
        base.position.y = 0.24;
        group.add(base);

        const baseRing = new THREE.Mesh(new THREE.TorusGeometry(1.3, 0.045, 14, 80), glow(teamColor, 3));
        baseRing.rotation.x = Math.PI / 2;
        baseRing.position.y = 0.49;
        group.add(baseRing);

        const column = new THREE.Mesh(
            new THREE.CylinderGeometry(0.83, 0.9, height, 48),
            new THREE.MeshPhysicalMaterial({
                color: teamColor.clone().multiplyScalar(0.42),
                emissive: teamColor,
                emissiveIntensity: 0.74,
                metalness: 0.44,
                roughness: 0.18,
                transparent: true,
                opacity: 0.88,
                clearcoat: 1
            })
        );
        column.position.y = 0.52 + height / 2;
        group.add(column);

        const top = new THREE.Mesh(new THREE.CylinderGeometry(0.96, 0.96, 0.16, 64), glow(teamColor, 2.3));
        top.position.y = 0.56 + height;
        group.add(top);

        const crown = new THREE.Mesh(new THREE.OctahedronGeometry(index === 0 ? 0.44 : 0.34), glow(teamColor, 3));
        crown.position.y = 1.28 + height;
        crown.rotation.z = Math.PI / 4;
        group.add(crown);

        const halo = new THREE.Mesh(new THREE.TorusGeometry(index === 0 ? 0.7 : 0.54, 0.035, 12, 64), glow(teamColor, 3.4, 0.9));
        halo.position.y = 1.28 + height;
        halo.rotation.x = Math.PI / 2;
        group.add(halo);

        const beam = new THREE.Mesh(
            new THREE.CylinderGeometry(0.22, 1.28, 7.5, 40, 1, true),
            new THREE.MeshBasicMaterial({
                color: teamColor,
                transparent: true,
                opacity: index === 0 ? 0.105 : 0.065,
                side: THREE.DoubleSide,
                depthWrite: false,
                blending: THREE.AdditiveBlending
            })
        );
        beam.position.y = 4.1;
        group.add(beam);

        const light = new THREE.PointLight(teamColor, index === 0 ? 18 : 11, 5.5, 2);
        light.position.set(0, 0.75 + height, 0.2);
        group.add(light);
        scene.add(group);

        return { group, halo, crown, beam, delay: 0.18 + [2, 0, 1, 3][index] * 0.16 };
    }

    function mount(canvas, teams) {
        dispose();
        if (!canvas || !window.THREE || !window.WebGLRenderingContext) return false;

        try {
            const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'high-performance' });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
            renderer.outputColorSpace = THREE.SRGBColorSpace;
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            renderer.toneMappingExposure = 1.35;
            renderer.shadowMap.enabled = true;

            const scene = new THREE.Scene();
            scene.fog = new THREE.FogExp2(0x02040a, 0.075);
            const camera = new THREE.PerspectiveCamera(38, 16 / 9, 0.1, 80);
            camera.position.set(0, 4.6, 13.8);
            camera.lookAt(0, 2, 0);
            scene.add(new THREE.HemisphereLight(0x5b77ff, 0x030407, 1.3));

            const key = new THREE.DirectionalLight(0xffffff, 2.8);
            key.position.set(-2, 8, 7);
            scene.add(key);

            const floor = new THREE.Mesh(
                new THREE.CylinderGeometry(8.7, 8.9, 0.28, 96),
                new THREE.MeshStandardMaterial({ color: 0x03060c, metalness: 0.92, roughness: 0.24 })
            );
            floor.position.y = -0.14;
            scene.add(floor);

            const floorRing = new THREE.Mesh(new THREE.TorusGeometry(7.9, 0.035, 12, 160), glow(0x287cff, 3, 0.8));
            floorRing.rotation.x = Math.PI / 2;
            floorRing.position.y = 0.02;
            scene.add(floorRing);
            addTruss(scene);
            const particles = addParticles(scene);

            const scores = teams.map((team) => Number(team.score || 0));
            const maxScore = Math.max(...scores, 1);
            const xs = [-4.75, -1.58, 1.58, 4.75];
            const podiums = teams.slice(0, 4).map((team, index) => addPodium(
                scene,
                team,
                xs[index],
                1.15 + (Number(team.score || 0) / maxScore) * 2.55,
                index
            ));

            const clock = new THREE.Clock();
            const startedAt = performance.now() / 1000;
            const stage = { renderer, scene, camera, podiums, particles, floorRing, frame: 0, observer: null };
            active = stage;
            const resize = () => {
                const width = canvas.clientWidth || canvas.parentElement?.clientWidth || 1280;
                const height = canvas.clientHeight || canvas.parentElement?.clientHeight || 720;
                renderer.setSize(width, height, false);
                camera.aspect = width / Math.max(height, 1);
                camera.fov = width < 900 ? 49 : 38;
                camera.position.z = width < 900 ? 17.5 : 13.8;
                camera.updateProjectionMatrix();
            };
            stage.observer = new ResizeObserver(resize);
            stage.observer.observe(canvas);
            resize();

            const animate = () => {
                if (active !== stage || !canvas.isConnected) return dispose();
                const t = clock.getElapsedTime();
                const elapsed = performance.now() / 1000 - startedAt;
                podiums.forEach((podium, index) => {
                    const progress = Math.min(1, Math.max(0, (elapsed - podium.delay) / 1.25));
                    podium.group.scale.y = Math.max(0.001, 1 - Math.pow(1 - progress, 4));
                    podium.halo.rotation.z = t * (index % 2 ? -0.45 : 0.45);
                    podium.crown.rotation.y = t * 0.7 + index;
                    podium.beam.rotation.z = Math.sin(t * 0.45 + index) * 0.025;
                });
                particles.rotation.y = t * 0.018;
                particles.position.y = Math.sin(t * 0.22) * 0.12;
                floorRing.rotation.z = t * 0.035;
                camera.position.x = Math.sin(t * 0.12) * 0.2;
                camera.lookAt(0, 2.05 + Math.sin(t * 0.18) * 0.04, 0);
                renderer.render(scene, camera);
                stage.frame = requestAnimationFrame(animate);
            };
            animate();
            return true;
        } catch (error) {
            console.warn('Three.js leaderboard unavailable; using CSS stage.', error);
            dispose();
            return false;
        }
    }

    window.TVLeaderboard3D = { mount, dispose };
})();
