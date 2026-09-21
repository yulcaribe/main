(() => {
  const canvas = document.getElementById('runway');
  const ctx = canvas.getContext('2d');
  const boot = document.getElementById('boot');
  const percent = document.getElementById('bootPercent');

  let w=0,h=0,dpr=1,t=0,mx=.5,my=.5;
  function resize(){
    dpr=Math.min(devicePixelRatio||1,2);
    w=innerWidth;h=innerHeight;
    canvas.width=w*dpr;canvas.height=h*dpr;
    canvas.style.width=w+'px';canvas.style.height=h+'px';
    ctx.setTransform(dpr,0,0,dpr,0,0);
  }
  resize();
  addEventListener('resize',resize,{passive:true});
  addEventListener('pointermove',e=>{mx=e.clientX/w;my=e.clientY/h},{passive:true});

  function line(x1,y1,x2,y2,a=.2,width=1){
    ctx.strokeStyle=`rgba(24,224,255,${a})`;
    ctx.lineWidth=width;
    ctx.beginPath();ctx.moveTo(x1,y1);ctx.lineTo(x2,y2);ctx.stroke();
  }

  function draw(){
    t+=.008;
    ctx.clearRect(0,0,w,h);
    const horizon=h*.43 + (my-.5)*18;
    const center=w*.5 + (mx-.5)*60;

    const g=ctx.createRadialGradient(center,horizon,10,center,horizon,w*.45);
    g.addColorStop(0,'rgba(24,224,255,.16)');
    g.addColorStop(.34,'rgba(24,224,255,.035)');
    g.addColorStop(1,'rgba(0,0,0,0)');
    ctx.fillStyle=g;ctx.fillRect(0,0,w,h);

    line(0,horizon,w,horizon,.18,1);

    const lanes=12;
    for(let i=-lanes;i<=lanes;i++){
      const bx=center+i*(w/lanes*.95);
      const tx=center+i*3.5;
      line(tx,horizon,bx,h,.11,1);
    }

    for(let i=0;i<22;i++){
      let p=(i/22 + (t%1))%1;
      p=p*p;
      const y=horizon+(h-horizon)*p;
      const spread=(y-horizon)/(h-horizon);
      const left=center-(w*.58*spread);
      const right=center+(w*.58*spread);
      line(left,y,right,y,.05+.16*p,1);
    }

    for(let i=0;i<9;i++){
      const p=((i/9)+(t*.55%1))%1;
      const y=horizon+(h-horizon)*(p*p);
      const size=2+8*p;
      ctx.fillStyle=`rgba(24,224,255,${.18+.6*p})`;
      ctx.fillRect(center-size/2,y,size,size*2.4);
    }

    requestAnimationFrame(draw);
  }
  draw();

  let n=0;
  const bootTimer=setInterval(()=>{
    n=Math.min(100,n+Math.ceil(Math.random()*9));
    percent.textContent=String(n).padStart(2,'0');
    if(n>=100)clearInterval(bootTimer);
  },55);

  function randomChar(){const s='ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';return s[Math.floor(Math.random()*s.length)]}
  document.querySelectorAll('.flap span').forEach((el,i)=>{
    const target=el.dataset.char;
    let k=0;
    const iv=setInterval(()=>{
      el.textContent=randomChar();
      if(++k>5+i){clearInterval(iv);el.textContent=target}
    },45);
  });

  function entrance(){
    if(window.anime){
      anime.timeline({easing:'easeOutExpo'})
        .add({targets:'.boot-track i',translateX:['-100%','0%'],duration:700})
        .add({duration:300,complete:()=>boot.classList.add('done')})
        .add({targets:'.hero h1 span',translateY:[70,0],opacity:[0,1],duration:900,delay:anime.stagger(120)},'-=100')
        .add({targets:'.micro,.hero-row,.hud',translateY:[20,0],opacity:[0,1],duration:600,delay:anime.stagger(70)},'-=650')
        .add({targets:'.aircraft-shell',scale:[.8,1],opacity:[0,.75],duration:1200},'-=1000');
    }else setTimeout(()=>boot.classList.add('done'),900);
  }
  addEventListener('load',entrance,{once:true});
  setTimeout(()=>boot.classList.add('done'),3500);

  const plane=document.querySelector('.aircraft-shell');
  addEventListener('scroll',()=>{
    const y=scrollY;
    if(plane) plane.style.transform=`translate3d(0,${Math.min(y*.11,140)}px,0) scale(${1+Math.min(y*.00012,.08)})`;
  },{passive:true});

  const boardObs=new IntersectionObserver(entries=>{
    entries.forEach(e=>{
      if(!e.isIntersecting)return;
      document.querySelectorAll('.splitword').forEach((el,idx)=>{
        const target=el.dataset.value;
        setTimeout(()=>{
          let step=0;
          const iv=setInterval(()=>{
            el.textContent=[...target].map((ch,i)=>i<step?ch:randomChar()).join('');
            step++;
            if(step>target.length){clearInterval(iv);el.textContent=target}
          },70);
        },idx*180);
      });
      boardObs.disconnect();
    });
  },{threshold:.25});
  const board=document.querySelector('.board'); if(board) boardObs.observe(board);

  document.querySelectorAll('a[href^="#"]').forEach(a=>a.addEventListener('click',e=>{
    const target=document.querySelector(a.getAttribute('href'));
    if(!target)return;
    e.preventDefault();target.scrollIntoView({behavior:'smooth'});
  }));
})();