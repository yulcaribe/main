(() => {
  const canvas = document.getElementById('sky');
  const ctx = canvas.getContext('2d');
  const loader = document.getElementById('loader');
  const count = document.getElementById('loaderCount');
  let w=0,h=0,dpr=1,stars=[];

  function resize(){
    dpr=Math.min(window.devicePixelRatio||1,2);
    w=innerWidth; h=innerHeight;
    canvas.width=w*dpr; canvas.height=h*dpr;
    canvas.style.width=w+'px'; canvas.style.height=h+'px';
    ctx.setTransform(dpr,0,0,dpr,0,0);
    stars=Array.from({length:Math.min(150,Math.floor(w/7))},()=>({
      x:Math.random()*w,y:Math.random()*h,z:Math.random()*1+.2,s:Math.random()*1.7+.3
    }));
  }

  function draw(){
    ctx.clearRect(0,0,w,h);
    ctx.fillStyle='rgba(22,220,255,.75)';
    for(const p of stars){
      p.y+=.12+p.z*.45;
      p.x+=.02*p.z;
      if(p.y>h+4){p.y=-4;p.x=Math.random()*w}
      ctx.globalAlpha=.12+p.z*.55;
      ctx.fillRect(p.x,p.y,p.s,p.s*(1+p.z*3));
    }
    ctx.globalAlpha=1;
    requestAnimationFrame(draw);
  }

  resize(); draw(); addEventListener('resize',resize,{passive:true});

  function boot(){
    let n=0;
    const timer=setInterval(()=>{
      n=Math.min(100,n+Math.ceil(Math.random()*11));
      count.textContent=String(n).padStart(3,'0');
      if(n>=100) clearInterval(timer);
    },55);

    if(window.anime){
      anime.timeline({easing:'easeOutExpo'})
        .add({targets:'.loader-word',translateY:[70,0],opacity:[0,1],duration:720})
        .add({targets:'.loader-rule span',translateX:['-100%','0%'],duration:650},'-=400')
        .add({targets:'.loader-code,.loader-count',opacity:[0,1],duration:350},'-=400')
        .add({duration:250,complete:()=>loader.classList.add('done')})
        .add({targets:'.hero h1 span',translateY:[70,0],opacity:[0,1],delay:anime.stagger(110),duration:900},'-=150')
        .add({targets:'.kicker,.hero-bottom,.hero-side',opacity:[0,1],translateY:[18,0],delay:anime.stagger(70),duration:600},'-=600');
    } else {
      setTimeout(()=>loader.classList.add('done'),900);
    }
  }
  addEventListener('load',boot,{once:true});
  setTimeout(()=>loader.classList.add('done'),3500);

  const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789/.-';
  function scramble(el){
    const target=el.dataset.text||el.textContent;
    let frame=0;
    const max=18;
    const run=()=>{
      const progress=frame/max;
      el.textContent=[...target].map((c,i)=>{
        if(c===' ') return ' ';
        if(i/target.length<progress) return c;
        return chars[Math.floor(Math.random()*chars.length)];
      }).join('');
      frame++;
      if(frame<=max) requestAnimationFrame(run); else el.textContent=target;
    };
    run();
  }

  const io=new IntersectionObserver(entries=>{
    entries.forEach(e=>{
      if(!e.isIntersecting) return;
      e.target.querySelectorAll?.('.scramble').forEach(scramble);
      e.target.classList.add('in');
      io.unobserve(e.target);
    });
  },{threshold:.22});
  document.querySelectorAll('section').forEach(s=>io.observe(s));

  document.querySelectorAll('.magnetic').forEach(el=>{
    el.addEventListener('pointermove',e=>{
      const r=el.getBoundingClientRect();
      const x=(e.clientX-r.left-r.width/2)*.18;
      const y=(e.clientY-r.top-r.height/2)*.18;
      el.style.transform=`translate(${x}px,${y}px)`;
    });
    el.addEventListener('pointerleave',()=>el.style.transform='translate(0,0)');
  });

  const plane=document.querySelector('.plane-wrap');
  addEventListener('scroll',()=>{
    if(!plane) return;
    const y=scrollY;
    plane.style.transform=`translate3d(${Math.min(y*.05,70)}px,${Math.min(y*.12,130)}px,0) rotate(${Math.min(y*.008,7)}deg)`;
  },{passive:true});

  document.querySelectorAll('a[href^="#"]').forEach(a=>a.addEventListener('click',e=>{
    const target=document.querySelector(a.getAttribute('href'));
    if(!target) return;
    e.preventDefault();
    target.scrollIntoView({behavior:'smooth'});
  }));
})();