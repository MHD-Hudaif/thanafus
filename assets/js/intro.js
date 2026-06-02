const intro = document.getElementById("intro");
const homepage = document.getElementById("homepage");
const skipBtn = document.getElementById("skipBtn");

const tl = gsap.timeline();

gsap.to(skipBtn,{
    opacity:1,
    delay:1,
    duration:1
});

tl.to(".kauzariyya-logo",{
    opacity:1,
    scale:1,
    duration:2,
    ease:"power3.out"
})

.to(".intro-text",{
    opacity:1,
    y:-10,
    duration:1.5
},"-=1")

.to(".thanafus-logo",{
    opacity:1,
    scale:1,
    duration:1.8,
    ease:"power3.out"
},"-=0.5")

.to(".intro-content",{
    scale:1.08,
    duration:2
})

.to("#intro",{
    opacity:0,
    duration:1.5,
    delay:1,
    onComplete:showHomepage
});

function showHomepage(){

    intro.style.display="none";

    homepage.style.visibility="visible";

    gsap.to(homepage,{
        opacity:1,
        duration:1.5
    });

    document.body.style.overflow="auto";
}

skipBtn.addEventListener("click",()=>{

    gsap.killTweensOf("*");

    gsap.to("#intro",{
        opacity:0,
        duration:0.7,
        onComplete:showHomepage
    });

});