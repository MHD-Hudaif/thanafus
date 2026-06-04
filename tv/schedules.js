(()=>{
const clock=document.getElementById('scheduleClock');
setInterval(()=>clock.textContent=new Date().toLocaleTimeString(),1000);
const pages=[...document.querySelectorAll('.schedule-page')];
let index=0;
function show(i){
pages.forEach(p=>{p.style.display='none';p.classList.remove('active')});
const page=pages[i]; page.style.display='table'; page.classList.add('active');
gsap.fromTo(page,{x:120,opacity:0},{x:0,opacity:1,duration:.8});
setTimeout(()=>{
gsap.to(page,{x:-120,opacity:0,duration:.8,onComplete:()=>{
index=(index+1)%pages.length; show(index);
}});
},6000);
}
if(pages.length>1) show(0);

function highlight(){
document.querySelectorAll('.schedule-row').forEach(r=>r.classList.remove('current'));
let cur=null, now=new Date();
document.querySelectorAll('.schedule-row').forEach(r=>{
const d=new Date(r.dataset.startTime);
if(d<=now) cur=r;
});
if(cur) cur.classList.add('current');
}
highlight(); setInterval(highlight,60000);
})();