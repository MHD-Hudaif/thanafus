const backgrounds =
document.querySelectorAll(".bg-image");

let currentBg = 0;

setInterval(() => {

backgrounds[currentBg]
.classList.remove("active");

currentBg =
(currentBg + 1) % backgrounds.length;

backgrounds[currentBg]
.classList.add("active");

}, 15000);

const scene = new THREE.Scene();

const camera = new THREE.PerspectiveCamera(
75,
window.innerWidth / window.innerHeight,
0.1,
1000
);

camera.position.z = 5;

const renderer = new THREE.WebGLRenderer({
canvas:document.getElementById("bgCanvas"),
alpha:true,
antialias:true
});

renderer.setSize(
window.innerWidth,
window.innerHeight
);

const particlesGeometry =
new THREE.BufferGeometry();

const particlesCount = 3500;

const posArray =
new Float32Array(particlesCount * 3);

for(let i = 0; i < particlesCount * 3; i++){

posArray[i] =
(Math.random() - 0.5) * 25;

}

particlesGeometry.setAttribute(
'position',
new THREE.BufferAttribute(posArray,3)
);

const particlesMaterial =
new THREE.PointsMaterial({

size:0.012,
color:"#10b981",
transparent:true,
opacity:0

});

const particlesMesh =
new THREE.Points(
particlesGeometry,
particlesMaterial
);

scene.add(particlesMesh);

function animate(){

requestAnimationFrame(animate);

particlesMesh.rotation.y += 0.00025;
particlesMesh.rotation.x += 0.00008;

renderer.render(scene,camera);

}

animate();

gsap.to("#skipBtn",{
opacity:1,
duration:1,
delay:1
});

const tl = gsap.timeline();

tl.to("#kauzariyyaLogo",{
opacity:1,
scale:1,
duration:2.5,
ease:"power3.out"
});

tl.to({},{
duration:1
});

tl.to("#kauzariyyaLogo",{
opacity:0,
scale:1.12,
duration:1.8,
ease:"power2.inOut"
});

tl.to(particlesMaterial,{
opacity:0.9,
duration:1.5
},"-=0.5");

tl.to("#thanafusWrapper",{
opacity:1,
scale:1,
duration:0.5
});

tl.to("#logoGlow",{
opacity:1,
duration:1
},"-=0.5");

tl.to("#logoLight",{
opacity:1,
left:"140%",
duration:2.2,
ease:"power2.inOut"
},"-=0.4");

tl.to("#thanafusLogo",{
opacity:1,
duration:0.4
},"-=1.8");

tl.to("#logoLight",{
opacity:0,
duration:0.6
},"-=0.5");

tl.to({},{
duration:1
});

tl.to("#home",{
opacity:1,
duration:2
});

tl.to("#header",{
opacity:1,
duration:1.4
},"-=1.8");

tl.to("#thanafusWrapper",{

width:"145px",
top:"69px",
left:"48px",
x:0,
y:0,
scale:1,
duration:2.3,
ease:"power3.inOut"

},"-=1.9");

tl.to("#headerLogo",{
opacity:1,
scale:1,
duration:0.25
},"-=0.25");

tl.to("#thanafusWrapper",{
opacity:0,
duration:0.2
});

tl.to("#enterBtn",{
opacity:1,
y:0,
pointerEvents:"auto",
duration:1.2,
ease:"power3.out"
},"-=0.3");

tl.to("#enterSub",{
opacity:1,
y:0,
duration:1
},"-=0.9");

tl.to("#intro",{
opacity:0,
pointerEvents:"none",
duration:0.8
},"-=2");

tl.to("#skipBtn",{
opacity:0,
pointerEvents:"none",
duration:0.5
},"-=1.8");

let skipped = false;

function skipIntro(){

if(skipped) return;

skipped = true;

gsap.globalTimeline.clear();

gsap.set("#home",{
opacity:1
});

gsap.set("#header",{
opacity:1
});

gsap.set("#intro",{
opacity:0,
pointerEvents:"none"
});

gsap.set("#skipBtn",{
opacity:0,
pointerEvents:"none"
});

gsap.set("#headerLogo",{
opacity:1,
scale:1
});

gsap.set("#enterBtn",{
opacity:1,
y:0,
pointerEvents:"auto"
});

gsap.set("#enterSub",{
opacity:1,
y:0
});

gsap.set(particlesMaterial,{
opacity:0.9
});

}

document
.getElementById("skipBtn")
.addEventListener("click", skipIntro);
