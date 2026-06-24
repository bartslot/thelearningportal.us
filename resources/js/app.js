import './bootstrap';
import { gsap } from 'gsap';
import Flickity from 'flickity';
import 'flickity/css/flickity.css';
import Sortable                from 'sortablejs';

window.Sortable = Sortable;

// The 3D scene system (three.js, ~1.7 MB) is used ONLY by the lesson-creation wizard. Load it on
// demand via window.loadLessonScene() so the landing page and other app pages never download three.
// The wizard step views await this before touching window.LessonScene.
window.loadLessonScene = () => import('./scene/index.js').then((module) => {
    window.LessonScene = module;
    return module;
});

const isHeroBackgroundHover = (eventTarget) => {
    const hero = document.querySelector('#home');

    if (!hero) {
        return false;
    }

    const element = eventTarget instanceof Element ? eventTarget : null;

    if (!element || !hero.contains(element)) {
        return false;
    }

    return !element.closest('.hero-copy, .hero-cta, [data-portal-signup], header, nav, a, button, input, textarea, select, label');
};

const setupLandingCursor = () => {
    const cursor = document.querySelector('.landing-cursor');

    if (!cursor || !window.matchMedia('(pointer: fine)').matches) {
        return;
    }

    const xTo = gsap.quickTo(cursor, 'left', { duration: 0.12, ease: 'power3.out' });
    const yTo = gsap.quickTo(cursor, 'top', { duration: 0.12, ease: 'power3.out' });
    const scaleTo = gsap.quickTo(cursor, 'scale', { duration: 0.2, ease: 'power2.out' });
    let isMouseDown = false;
    let isBackgroundHover = false;

    const syncCursorState = () => {
        const idleScale = isBackgroundHover ? 1.26 : 1;
        const activeScale = isBackgroundHover ? 1.58 : 1.45;
        scaleTo(isMouseDown ? activeScale : idleScale);
        cursor.classList.toggle('landing-cursor--portal-bg', isBackgroundHover);
    };

    gsap.set(cursor, {
        left: window.innerWidth / 2,
        top: window.innerHeight / 2,
        autoAlpha: 0.9,
    });

    const moveCursor = (x, y, target) => {
        isBackgroundHover = isHeroBackgroundHover(target);
        xTo(x);
        yTo(y);
        syncCursorState();
        gsap.to(cursor, { autoAlpha: 1, duration: 0.08, ease: 'none', overwrite: 'auto' });
    };

    window.addEventListener('pointermove', (event) => {
        moveCursor(event.clientX, event.clientY, event.target);
    }, { passive: true });

    window.addEventListener('mouseleave', () => {
        isBackgroundHover = false;
        cursor.classList.remove('landing-cursor--portal-bg');
        scaleTo(0.7);
        gsap.to(cursor, { autoAlpha: 0.9, duration: 0.18, ease: 'sine.out', overwrite: 'auto' });
    });

    window.addEventListener('mousedown', () => {
        isMouseDown = true;
        syncCursorState();
    });

    window.addEventListener('mouseup', () => {
        isMouseDown = false;
        syncCursorState();
    });
};

const setupWheelMotion = () => {
    const wheel = document.querySelector('.wheel');

    if (!wheel) {
        return;
    }

    gsap.set(wheel, {
        transformOrigin: '50% 50%',
        opacity: 0.56,
        willChange: 'transform',
    });

    let breathingTween = null;
    const finePointer = window.matchMedia('(pointer: fine)').matches;

    const getBreathingConfig = () => {
        const width = window.innerWidth;

        if (width >= 1440) {
            return { scale: 1.08, duration: 12 };
        }

        if (width >= 1024) {
            return { scale: 1.06, duration: 9 };
        }

        if (width >= 640) {
            return { scale: 1.045, duration: 7.5 };
        }

        return { scale: 1.03, duration: 6.5 };
    };

    const startBreathing = () => {
        const { scale, duration } = getBreathingConfig();

        if (breathingTween) {
            breathingTween.kill();
        }

        breathingTween = gsap.to(wheel, {
            scale,
            duration,
            ease: 'sine.inOut',
            repeat: -1,
            yoyo: true,
            overwrite: true,
        });
    };

    startBreathing();

    const wheelX = gsap.quickTo(wheel, 'x', { duration: 0.9, ease: 'power3.out' });
    const wheelY = gsap.quickTo(wheel, 'y', { duration: 0.9, ease: 'power3.out' });
    const wheelRotation = gsap.quickTo(wheel, 'rotation', { duration: 1.1, ease: 'power3.out' });
    const wheelOpacity = gsap.quickTo(wheel, 'opacity', { duration: 0.3, ease: 'sine.out' });

    const moveWheel = (clientX, clientY, target) => {
        if (!finePointer || document.documentElement.classList.contains('portal-exiting')) {
            return;
        }

        const width = window.innerWidth;
        const height = window.innerHeight;
        const xRatio = (clientX / width) - 0.5;
        const yRatio = (clientY / height) - 0.5;

        const maxX = width >= 1440 ? 28 : width >= 1024 ? 22 : 16;
        const maxY = width >= 1440 ? 18 : width >= 1024 ? 14 : 10;
        const maxRotation = width >= 1440 ? 8 : width >= 1024 ? 6 : 4;

        wheelX(xRatio * maxX);
        wheelY(yRatio * maxY);
        wheelRotation((xRatio * maxRotation) + (yRatio * 1.5));
        wheelOpacity(isHeroBackgroundHover(target) ? 0.92 : 0.56);
    };

    window.addEventListener('pointermove', (event) => {
        moveWheel(event.clientX, event.clientY, event.target);
    }, { passive: true });

    window.addEventListener('pointerleave', () => {
        wheelX(0);
        wheelY(0);
        wheelRotation(0);
        wheelOpacity(0.56);
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(startBreathing, 150);
    }, { passive: true });
};

const setupHeroParallax = () => {
    const hero = document.querySelector('#home');
    const glow = document.querySelector('.hero-glow');
    const spotlight = document.querySelector('.hero-spotlight');
    const orb = document.querySelector('.hero-orb');
    const copy = document.querySelector('.hero-copy');
    const cta = document.querySelector('.hero-cta');

    if (!hero || !glow || !spotlight || !orb || !copy || !cta) {
        return;
    }

    const finePointer = window.matchMedia('(pointer: fine)').matches;

    gsap.set([glow, spotlight, orb, copy, cta], { willChange: 'transform' });

    const glowX = gsap.quickTo(glow, 'x', { duration: 1.4, ease: 'power3.out' });
    const glowY = gsap.quickTo(glow, 'y', { duration: 1.4, ease: 'power3.out' });
    const spotlightX = gsap.quickTo(spotlight, 'x', { duration: 1.6, ease: 'power3.out' });
    const spotlightY = gsap.quickTo(spotlight, 'y', { duration: 1.6, ease: 'power3.out' });
    const orbX = gsap.quickTo(orb, 'x', { duration: 1.8, ease: 'power3.out' });
    const orbY = gsap.quickTo(orb, 'y', { duration: 1.8, ease: 'power3.out' });
    const copyX = gsap.quickTo(copy, 'x', { duration: 1.0, ease: 'power3.out' });
    const copyY = gsap.quickTo(copy, 'y', { duration: 1.0, ease: 'power3.out' });
    const ctaX = gsap.quickTo(cta, 'x', { duration: 0.9, ease: 'power3.out' });
    const ctaY = gsap.quickTo(cta, 'y', { duration: 0.9, ease: 'power3.out' });

    const moveHero = (clientX, clientY) => {
        if (!finePointer || document.documentElement.classList.contains('portal-exiting')) {
            return;
        }

        const width = window.innerWidth;
        const height = window.innerHeight;
        const xRatio = (clientX / width) - 0.5;
        const yRatio = (clientY / height) - 0.5;
        const intensity = width >= 1440 ? 1.25 : width >= 1024 ? 1 : 0.75;

        glowX(xRatio * 28 * intensity);
        glowY(yRatio * 18 * intensity);
        spotlightX(xRatio * 18 * intensity);
        spotlightY(yRatio * 12 * intensity);
        orbX(xRatio * -42 * intensity);
        orbY(yRatio * -26 * intensity);
        copyX(xRatio * 10 * intensity);
        copyY(yRatio * 8 * intensity);
        ctaX(xRatio * 14 * intensity);
        ctaY(yRatio * 10 * intensity);
    };

    window.addEventListener('pointermove', (event) => {
        moveHero(event.clientX, event.clientY);
    }, { passive: true });

    window.addEventListener('pointerleave', () => {
        glowX(0);
        glowY(0);
        spotlightX(0);
        spotlightY(0);
        orbX(0);
        orbY(0);
        copyX(0);
        copyY(0);
        ctaX(0);
        ctaY(0);
    });
};

const setupPortalExitAnimation = () => {
    const hero = document.querySelector('#home');
    const trigger = hero?.querySelector('[data-portal-launch]');

    if (!hero || !trigger) {
        return;
    }

    const images = JSON.parse(hero.dataset.portalImages || '[]');

    if (!Array.isArray(images) || images.length === 0) {
        return;
    }

    const wheel = hero.querySelector('.wheel');
    const glow = hero.querySelector('.hero-glow');
    const spotlight = hero.querySelector('.hero-spotlight');
    const orb = hero.querySelector('.hero-orb');
    const copy = hero.querySelector('.hero-copy');
    const cta = hero.querySelector('.hero-cta');
    const signup = hero.querySelector('[data-portal-signup]');

    let isRunning = false;

    trigger.addEventListener('click', async (event) => {
        if (isRunning) {
            event.preventDefault();
            return;
        }

        isRunning = true;
        event.preventDefault();

        document.documentElement.classList.add('portal-exiting');
        document.body.classList.add('portal-exiting');
        document.body.style.overflow = 'hidden';

        try {
            const THREE = await import('three');

            const currentWheelRotation = Number(gsap.getProperty(wheel, 'rotation')) || 0;
            const rect = wheel.getBoundingClientRect();
            const centerX = rect.left + rect.width / 2;
            const centerY = rect.top + rect.height / 2;
            const cardCount = Math.min(images.length, 10);
            const selectedImages = gsap.utils.shuffle(images.slice()).slice(0, cardCount);

            const scene = document.createElement('div');
            scene.className = 'hero-portal-scene';
            hero.appendChild(scene);

            const heroRect = hero.getBoundingClientRect();
            const width = Math.max(1, Math.round(heroRect.width));
            const height = Math.max(1, Math.round(heroRect.height));

            const renderer = new THREE.WebGLRenderer({
                alpha: true,
                antialias: true,
                powerPreference: 'high-performance',
            });
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
            renderer.setSize(width, height, false);
            renderer.domElement.className = 'hero-portal-canvas';
            scene.appendChild(renderer.domElement);

            const scene3d = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(52, width / height, 1, 9000);
            camera.position.set(0, 0, 1200);
            scene3d.add(new THREE.AmbientLight(0xffffff, 1));

            const toWorldAtZ = (screenX, screenY, targetZ = 0) => {
                const ndcX = ((screenX - heroRect.left) / width) * 2 - 1;
                const ndcY = -(((screenY - heroRect.top) / height) * 2 - 1);
                const vector = new THREE.Vector3(ndcX, ndcY, 0.5).unproject(camera);
                const dir = vector.sub(camera.position).normalize();
                const distance = (targetZ - camera.position.z) / dir.z;
                return camera.position.clone().add(dir.multiplyScalar(distance));
            };

            const origin = toWorldAtZ(centerX, centerY, 0);
            const loader = new THREE.TextureLoader();
            const cardAspect = 1.38;
            const baseWidth = Math.max(120, Math.min(220, width * 0.11));
            const baseHeight = baseWidth * cardAspect;
            const geometry = new THREE.PlaneGeometry(baseWidth, baseHeight, 1, 1);
            const cards = [];

            const createRoundedAlphaMap = () => {
                const maskWidth = 512;
                const maskHeight = Math.round(maskWidth * cardAspect);
                const radius = Math.round(maskWidth * 0.075);
                const canvas = document.createElement('canvas');
                canvas.width = maskWidth;
                canvas.height = maskHeight;
                const context = canvas.getContext('2d');

                if (!context) {
                    return null;
                }

                context.clearRect(0, 0, maskWidth, maskHeight);
                context.fillStyle = '#fff';
                context.beginPath();
                context.moveTo(radius, 0);
                context.lineTo(maskWidth - radius, 0);
                context.quadraticCurveTo(maskWidth, 0, maskWidth, radius);
                context.lineTo(maskWidth, maskHeight - radius);
                context.quadraticCurveTo(maskWidth, maskHeight, maskWidth - radius, maskHeight);
                context.lineTo(radius, maskHeight);
                context.quadraticCurveTo(0, maskHeight, 0, maskHeight - radius);
                context.lineTo(0, radius);
                context.quadraticCurveTo(0, 0, radius, 0);
                context.closePath();
                context.fill();

                const texture = new THREE.CanvasTexture(canvas);
                texture.minFilter = THREE.LinearFilter;
                texture.magFilter = THREE.LinearFilter;
                texture.wrapS = THREE.ClampToEdgeWrapping;
                texture.wrapT = THREE.ClampToEdgeWrapping;
                texture.needsUpdate = true;
                return texture;
            };

            const roundedAlphaMap = createRoundedAlphaMap();

            selectedImages.forEach((src) => {
                const texture = loader.load(src);
                texture.colorSpace = THREE.SRGBColorSpace;
                texture.minFilter = THREE.LinearMipmapLinearFilter;
                texture.magFilter = THREE.LinearFilter;
                texture.generateMipmaps = true;

                const material = new THREE.MeshBasicMaterial({
                    map: texture,
                    alphaMap: roundedAlphaMap || null,
                    transparent: true,
                    opacity: 0,
                    depthWrite: false,
                    alphaTest: 0.02,
                    side: THREE.DoubleSide,
                });
                const mesh = new THREE.Mesh(geometry, material);
                mesh.position.copy(origin);
                mesh.position.z = -2600;
                mesh.scale.setScalar(0.02);
                mesh.rotation.set(
                    gsap.utils.random(-0.12, 0.12),
                    gsap.utils.random(-0.14, 0.14),
                    gsap.utils.random(-0.18, 0.18),
                );
                scene3d.add(mesh);
                cards.push({ mesh, material, texture });
            });

            gsap.set(scene, { opacity: 0 });
            if (signup) {
                gsap.set(signup, { y: 18, opacity: 0, pointerEvents: 'none' });
            }

            const render = () => {
                renderer.render(scene3d, camera);
            };

            gsap.ticker.add(render);

            const onResize = () => {
                const nextRect = hero.getBoundingClientRect();
                const nextWidth = Math.max(1, Math.round(nextRect.width));
                const nextHeight = Math.max(1, Math.round(nextRect.height));
                renderer.setSize(nextWidth, nextHeight, false);
                camera.aspect = nextWidth / nextHeight;
                camera.updateProjectionMatrix();
            };

            window.addEventListener('resize', onResize, { passive: true });

            const cleanup = () => {
                window.removeEventListener('resize', onResize);
                gsap.ticker.remove(render);
                document.documentElement.classList.remove('portal-exiting');
                document.body.classList.remove('portal-exiting');
                document.body.style.overflow = '';
                isRunning = false;
            };

            const tl = gsap.timeline({
                defaults: { ease: 'power4.out' },
                onComplete: cleanup,
            });

            tl.to(scene, { opacity: 1, duration: 0.04 }, 0)
                .to([glow, spotlight, orb], { opacity: 0.8, duration: 0.18 }, 0)
                .to(wheel, {
                    scale: 5.5,
                    opacity: 0.08,
                    rotation: currentWheelRotation + 24,
                    duration: 0.18,
                    ease: 'expo.in',
                }, 0)
                .to(copy, { opacity: 1, duration: 0.01 }, 0)
                .to(cta, { opacity: 1, duration: 0.01 }, 0);

            cards.forEach(({ mesh, material }) => {
            const baseAngle = gsap.utils.random(0, Math.PI * 2);
            const baseRadius = gsap.utils.random(340, 780);
            const driftX = Math.cos(baseAngle) * baseRadius;
            const driftY = Math.sin(baseAngle) * baseRadius * gsap.utils.random(0.62, 0.88);
            const driftZ = gsap.utils.random(-360, 240);
            const arcAngle = baseAngle + gsap.utils.random(-0.8, 0.8);
            const arcRadius = baseRadius * gsap.utils.random(0.8, 1.25);
            const arcX = Math.cos(arcAngle) * arcRadius * 0.2;
            const arcY = Math.sin(arcAngle) * arcRadius * 0.2;
            const spinX = gsap.utils.random(-22, 22);
            const spinY = gsap.utils.random(-34, 34);
            const spinZ = gsap.utils.random(-26, 26);
            const nearScale = gsap.utils.random(0.9, 1.28);
            const burstDelay = gsap.utils.random(0.01, 0.22);
            const startZ = gsap.utils.random(-3200, -2200);
            const nearX = origin.x + (driftX * 0.86);
            const nearY = origin.y + (driftY * 0.86);

                const burst = gsap.timeline();

                burst
                    .set(mesh.position, {
                        x: origin.x,
                        y: origin.y,
                        z: startZ,
                    }, 0)
                    .set(mesh.scale, { x: 0.02, y: 0.02, z: 0.02 }, 0)
                    .set(material, { opacity: 0 }, 0)
                    .to(material, {
                        opacity: 0.98,
                        duration: 0.28,
                        ease: 'sine.out',
                    }, 0)
                    .to(mesh.position, {
                        x: nearX,
                        y: nearY,
                        z: driftZ,
                        duration: 1.05,
                        ease: 'expo.out',
                    }, 0)
                    .to(mesh.scale, {
                        x: nearScale,
                        y: nearScale,
                        z: nearScale,
                        duration: 1.05,
                        ease: 'expo.out',
                    }, 0)
                    .to(mesh.rotation, {
                        x: (spinX * Math.PI) / 180,
                        y: (spinY * Math.PI) / 180,
                        z: (spinZ * Math.PI) / 180,
                        duration: 1.05,
                        ease: 'expo.out',
                    }, 0)
                    .to(material, {
                        opacity: 0.92,
                        duration: 0.32,
                        ease: 'sine.out',
                    }, 0.24)
                    .to(mesh.position, {
                        x: nearX + arcX,
                        y: nearY + arcY,
                        z: driftZ + gsap.utils.random(-40, 120),
                        duration: 0.8,
                        ease: 'sine.inOut',
                    }, 1.06);

                tl.add(burst, burstDelay);
            });

            tl.to([copy, cta], {
                opacity: 0,
                y: -18,
                duration: 0.45,
                ease: 'power2.out',
            }, 1.15);

            if (signup) {
                tl.set(signup, { pointerEvents: 'auto' }, 1.22)
                    .call(() => { signup.inert = false; }, null, 1.22)
                    .to(signup, {
                        opacity: 1,
                        y: 0,
                        duration: 0.5,
                        ease: 'power2.out',
                    }, 1.22);
            }
        } catch (error) {
            document.documentElement.classList.remove('portal-exiting');
            document.body.classList.remove('portal-exiting');
            document.body.style.overflow = '';
            isRunning = false;
            throw error;
        }
    });
};

const setupLandingCarousels = () => {
    document.querySelectorAll('.js-disney-carousel').forEach((element) => {
        if (Flickity.data(element)) {
            return;
        }

        new Flickity(element, {
            cellAlign: 'left',
            contain: false,
            pageDots: false,
            prevNextButtons: true,
            wrapAround: false,
            freeScroll: false,
            selectedAttraction: 0.12,
            friction: 0.78,
            groupCells: false,
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        setupLandingCursor();
        setupWheelMotion();
        setupHeroParallax();
        setupPortalExitAnimation();
        setupLandingCarousels();
    }, { once: true });
} else {
    setupLandingCursor();
    setupWheelMotion();
    setupHeroParallax();
    setupPortalExitAnimation();
    setupLandingCarousels();
}
