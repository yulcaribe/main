(() => {
  const boot = document.getElementById("boot");
  const glow = document.getElementById("cursorGlow");
  const aircraft = document.querySelector(".aircraft");
  const stage = document.querySelector(".aircraft-stage");
  const cards = [...document.querySelectorAll(".system-card")];

  const finishBoot = () => {
    boot?.classList.add("is-done");
    document.body.classList.add("is-ready");
  };

  const runEntrance = () => {
    if (!window.anime) {
      document.querySelectorAll(".boot-word span,.reveal").forEach(el => {
        el.style.opacity = "1";
        el.style.transform = "none";
      });
      finishBoot();
      return;
    }

    const tl = anime.timeline({ easing: "easeOutExpo" });
    tl.add({
      targets: ".boot-mark span",
      scaleX: [0, 1],
      duration: 500,
      delay: anime.stagger(90)
    })
    .add({
      targets: ".boot-word span",
      opacity: [0, 1],
      translateY: [32, 0],
      duration: 640,
      delay: anime.stagger(52)
    }, "-=260")
    .add({
      targets: ".boot-line i",
      translateX: ["-100%", "0%"],
      duration: 620,
      easing: "easeInOutCubic"
    }, "-=320")
    .add({
      targets: ".boot-meta",
      opacity: [0, 1],
      duration: 360
    }, "-=220")
    .add({
      duration: 360,
      complete: finishBoot
    })
    .add({
      targets: ".hero-title .line",
      opacity: [0, 1],
      translateY: [54, 0],
      duration: 900,
      delay: anime.stagger(100)
    })
    .add({
      targets: ".reveal",
      opacity: [0, 1],
      translateY: [20, 0],
      duration: 700,
      delay: anime.stagger(75)
    }, "-=620")
    .add({
      targets: ".aircraft-stage",
      opacity: [0, 0.46],
      scale: [0.92, 1],
      duration: 1100
    }, "-=900");
  };

  window.addEventListener("load", runEntrance, { once: true });
  setTimeout(() => {
    if (!boot?.classList.contains("is-done")) finishBoot();
  }, 4200);

  window.addEventListener("pointermove", event => {
    if (!glow) return;
    glow.style.left = event.clientX + "px";
    glow.style.top = event.clientY + "px";
  }, { passive: true });

  let ticking = false;
  const updateMotion = () => {
    const y = window.scrollY;
    if (stage) {
      stage.style.transform = `translate3d(0,${Math.min(y * .12, 90)}px,0) rotate(${Math.min(y * .006, 3)}deg)`;
    }
    if (aircraft) {
      aircraft.style.transform = `rotate(${24 + Math.min(y * .012, 12)}deg) translateY(${Math.min(y * -.012, -18)}px)`;
    }
    ticking = false;
  };

  window.addEventListener("scroll", () => {
    if (!ticking) {
      ticking = true;
      requestAnimationFrame(updateMotion);
    }
  }, { passive: true });

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el = entry.target;
      if (window.anime) {
        anime({
          targets: el,
          opacity: [0, 1],
          translateY: [36, 0],
          duration: 760,
          easing: "easeOutCubic"
        });
      } else {
        el.style.opacity = "1";
        el.style.transform = "none";
      }
      observer.unobserve(el);
    });
  }, { threshold: .12 });

  cards.forEach(card => {
    card.style.opacity = "0";
    card.style.transform = "translateY(36px)";
    observer.observe(card);
  });

  cards.forEach(card => {
    card.addEventListener("pointermove", event => {
      const rect = card.getBoundingClientRect();
      const px = ((event.clientX - rect.left) / rect.width) * 100;
      const py = ((event.clientY - rect.top) / rect.height) * 100;
      card.style.background = `radial-gradient(circle at ${px}% ${py}%, rgba(32,227,255,.09), rgba(7,14,18,.28) 42%, rgba(255,255,255,.012))`;
    });
    card.addEventListener("pointerleave", () => {
      card.style.background = "";
    });
  });

  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener("click", event => {
      const target = document.querySelector(link.getAttribute("href"));
      if (!target) return;
      event.preventDefault();
      target.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  });
})();