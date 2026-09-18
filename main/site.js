async function loadHome(){
  const root=document.getElementById("site-root");
  try{
    const response=await fetch("/main/home.html",{cache:"no-cache"});
    if(!response.ok) throw new Error("HTTP "+response.status);
    root.classList.remove("site-loading");
    root.innerHTML=await response.text();

    const now=new Date();
    const today=document.getElementById("today-label");
    const year=document.getElementById("year-label");
    if(today) today.textContent=new Intl.DateTimeFormat("tr-TR",{day:"2-digit",month:"long",year:"numeric"}).format(now);
    if(year) year.textContent=now.getFullYear();
  }catch(error){
    console.error("Ana sayfa yüklenemedi:",error);
    root.innerHTML='<div style="padding:40px;text-align:center">Ana sayfa yüklenemedi.</div>';
  }
}
loadHome();