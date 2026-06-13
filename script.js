// Footer year
const y = document.getElementById('year');
if (y) y.textContent = new Date().getFullYear();

/* ============================================================
   Background video — mobile first with a patient watchdog
   ============================================================ */
(function(){
  const v = document.getElementById('bg-video');
  if (!v) return;

  const srcDesktop = v.getAttribute('data-src-desktop');
  const srcMobile  = v.getAttribute('data-src-mobile');
  const srcUltra   = v.getAttribute('data-src-ultra');

  // Decide: 'none', 'ultra', 'mobile', 'desktop'
  function wantMobile(){
    const c = navigator.connection || navigator.webkitConnection || navigator.mozConnection;
    if (c){
      if (c.saveData) return 'none';
      const et = String(c.effectiveType || '').toLowerCase();
      const dl = typeof c.downlink === 'number' ? c.downlink : null;
      if (et.includes('slow-2g') || et.includes('2g') || (dl && dl < 0.8)) return 'ultra';
      if (et.includes('3g') || (dl && dl < 1.6)) return 'ultra';
      if (et.includes('4g') || (dl && dl < 3.0)) return 'mobile';
    }
    const shortSide = Math.min(window.innerWidth, window.innerHeight);
    if (shortSide <= 700) return 'ultra';
    if (shortSide <= 1024) return 'mobile';
    return 'desktop';
  }

  const mode = wantMobile();
  if (mode !== 'none'){
    let chosen;
    if (mode === 'ultra')       chosen = srcUltra   || srcMobile  || srcDesktop;
    else if (mode === 'mobile') chosen = srcMobile  || srcUltra   || srcDesktop;
    else                        chosen = srcDesktop || srcMobile  || srcUltra;
    // Do NOT set preload=auto here; we'll bump it after Events renders
    if (chosen) v.src = chosen;
  }

  v.muted = true;
  v.playsInline = true;
  v.setAttribute('playsinline','');
  v.setAttribute('webkit-playsinline','');

  const rm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  let reduced = !!(rm && rm.matches);
  let triedPlay = false;

  async function tryPlayOnce(){
    if (triedPlay || reduced) return;
    triedPlay = true;
    try { await v.play(); } catch {}
  }

  // Patient watchdog — keep poster, keep buffering, retry later
  let watchdog, retryTimer, attempts = 0;
  function armWatchdog(){
    clearTimeout(watchdog);
    watchdog = setTimeout(()=>{
      if (!v.classList.contains('ready')) scheduleRetry();
    }, 3500);
  }
  function scheduleRetry(){
    clearTimeout(retryTimer);
    retryTimer = setTimeout(async ()=>{
      attempts++;
      try { await v.play(); } catch {}
      if (!v.classList.contains('ready') && attempts < 3) scheduleRetry();
    }, attempts === 0 ? 8000 : 12000);
  }
  armWatchdog();

  // Reveal when playable
  function onReady(){
    clearTimeout(watchdog);
    clearTimeout(retryTimer);
    v.classList.add('ready');
    tryPlayOnce();
  }
  function init(){
    if (v.readyState >= 2) onReady();
    else {
      v.addEventListener('loadeddata', onReady, { once:true });
      v.addEventListener('canplay',     onReady, { once:true });
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once:true });
  } else {
    init();
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) tryPlayOnce(); });
  window.addEventListener('pageshow', () => { tryPlayOnce(); });
  window.addEventListener('pointerdown', function once(){ tryPlayOnce(); window.removeEventListener('pointerdown', once); }, { once:true, passive:true });

  if (rm && rm.addEventListener){
    rm.addEventListener('change', e => {
      reduced = !!e.matches;
      if (reduced) { try { v.pause(); } catch {} } else { tryPlayOnce(); }
    });
  }
})();

/* =========================================
   Smooth in-page scroll (respects R.M.)
   ========================================= */
(function(){
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;
  document.querySelectorAll('a[href^="#"]').forEach(a=>{
    a.addEventListener('click', (e)=>{
      const id = a.getAttribute('href');
      const el = document.querySelector(id);
      if (!el) return;
      e.preventDefault();
      el.scrollIntoView({ behavior: 'smooth', block: 'start', inline: 'nearest' });
    });
  });
})();

/* =========================
   Top-right menu toggle
   ========================= */
(function(){
  const btn  = document.getElementById('menuBtn');
  const menu = document.getElementById('menu');
  if (!btn || !menu) return;

  function setOpenState(isOpen){
    if (isOpen){
      menu.classList.add('open');
      btn.setAttribute('aria-expanded','true');
      btn.setAttribute('aria-label','Close menu');
    } else {
      menu.classList.remove('open');
      btn.setAttribute('aria-expanded','false');
      btn.setAttribute('aria-label','Open menu');
    }
  }

  btn.addEventListener('click', (e)=>{
    e.stopPropagation();
    setOpenState(!menu.classList.contains('open'));
  });
  document.addEventListener('click', (e)=>{
    if (!menu.contains(e.target) && e.target !== btn) setOpenState(false);
  });
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape') setOpenState(false);
  });
  menu.querySelectorAll('a[href^="#"]').forEach(link=>{
    link.addEventListener('click', ()=> setOpenState(false));
  });
})();

/* ===================================================
   Ambient audio engine (light file on slow links)
   =================================================== */
const AUDIO_SRC_FULL  = 'assets/ambient.mp3';
const AUDIO_SRC_LIGHT = 'assets/ambient_light.mp3';
let _chosenAudioSrc = null;

function wantLightAudio(){
  const c = navigator.connection || navigator.webkitConnection || navigator.mozConnection;
  if (c){
    if (c.saveData) return true;
    const et = String(c.effectiveType || '').toLowerCase();
    if (et.includes('2g') || et.includes('3g')) return true;
    if (typeof c.downlink === 'number' && c.downlink > 0 && c.downlink < 1.5) return true;
  }
  if (Math.min(window.innerWidth, window.innerHeight) <= 900) return true;
  return false;
}
function pickAudioSrc(){
  if (_chosenAudioSrc) return _chosenAudioSrc;
  _chosenAudioSrc = wantLightAudio()
    ? (AUDIO_SRC_LIGHT || AUDIO_SRC_FULL)
    : (AUDIO_SRC_FULL  || AUDIO_SRC_LIGHT);
  return _chosenAudioSrc;
}

let ctx, gain, audio, mediaSrc, isPrimed=false;

function ensureContext(){
  if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
  return ctx;
}
function ensureAudio(){
  if (!audio){
    audio = new Audio(pickAudioSrc());
    audio.loop = true;
    audio.preload = 'metadata';
  }
  return audio;
}
async function primeGraphInGesture(){
  const c = ensureContext();
  if (c.state === 'suspended') await c.resume();
  const a = ensureAudio();

  if (!mediaSrc){
    mediaSrc = c.createMediaElementSource(a);
    if (!gain){ gain = c.createGain(); gain.gain.value = 0; }
    mediaSrc.connect(gain).connect(c.destination);
  }
  if (!isPrimed){
    try{
      a.load();
      await new Promise(res=>{
        if (a.readyState >= 2) return res();
        const onReady = () => { a.removeEventListener('canplay', onReady); res(); };
        a.addEventListener('canplay', onReady, { once:true });
        setTimeout(res, 800);
      });
    }catch{}
    isPrimed = true;
  }
}
async function fadeTo(target, ms=800){
  if (!gain || !ctx) return;
  const now = ctx.currentTime;
  try{
    gain.gain.cancelScheduledValues(now);
    gain.gain.setValueAtTime(gain.gain.value, now);
    gain.gain.linearRampToValueAtTime(target, now + ms/1000);
  }catch{}
}
async function playAudio(){
  await primeGraphInGesture();
  const a = ensureAudio();
  await a.play();
  await fadeTo(0.5, 600);
}
async function stopAudio(){
  await fadeTo(0, 380);
  setTimeout(()=>{ try{ if (audio && !audio.paused) audio.pause(); } catch(_){} }, 420);
}

/* ============================================
   Sound FAB
   ============================================ */
(function(){
  const fab = document.getElementById('soundFab');
  if (!fab) return;

  const savedPref = localStorage.getItem('ambient-sound') || 'off';
  setFab(false);

  function setFab(on){ fab.setAttribute('aria-pressed', on ? 'true' : 'false'); }
  function bindAudioEvents(){
    if (!audio) return;
    audio.addEventListener('play',  () => setFab(true));
    audio.addEventListener('pause', () => setFab(false));
    audio.addEventListener('ended', () => setFab(false));
  }

  window.addEventListener('pointerdown', async function initOnce(){
    window.removeEventListener('pointerdown', initOnce);
    try { await primeGraphInGesture(); } catch{}
    bindAudioEvents();
    if (savedPref === 'on'){
      try{ await playAudio(); setFab(true); localStorage.setItem('ambient-sound','on'); }
      catch{ setFab(false); localStorage.setItem('ambient-sound','off'); }
    } else {
      setFab(false);
    }
  }, { once:true, passive:true });

  const _origPlay = playAudio;
  playAudio = async function(){ const r = await _origPlay.apply(this, arguments); bindAudioEvents(); return r; };

  let busy = false;
  fab.addEventListener('click', async (ev)=>{
    ev.stopPropagation();
    if (document.body.classList.contains('lb-active')) return;
    if (busy) return;
    busy = true;
    try{
      const isOn = fab.getAttribute('aria-pressed') === 'true';
      if (isOn){
        await stopAudio().catch(()=>{});
        setFab(false);
        localStorage.setItem('ambient-sound','off');
      }else{
        try { await primeGraphInGesture(); } catch{}
        await playAudio();
        bindAudioEvents();
        setFab(true);
        localStorage.setItem('ambient-sound','on');
      }
    }catch{
      setFab(false);
      localStorage.setItem('ambient-sound','off');
    }finally{
      busy = false;
    }
  });
})();

/* ============================================================
   Lightbox (images + video)
   ============================================================ */
(function(){
  const grid = document.getElementById('galleryGrid');
  if (!grid) return;

  const items = [];
  grid.querySelectorAll('img[data-full], .lb-trigger[data-type="video"]').forEach(el => {
    if (el.tagName === 'IMG') {
      items.push({ type:'image', src: el.getAttribute('data-full'), trigger: el });
    } else {
      items.push({ type:'video', src: el.getAttribute('data-src'), trigger: el });
    }
  });

  let idx = 0, overlay = null, bodyEl, mediaEl, prevBtn, nextBtn, closeBtn, vidEl = null;
  let ambientWasOn = false;
  let videoMutedAmbient = false;
  let savedScrollY = 0;

  function buildOverlay(){
    overlay = document.createElement('div');
    overlay.className = 'lb';
    overlay.innerHTML = `
      <button class="lb-close" aria-label="Close"></button>
      <div class="lb-body">
        <button class="lb-prev" aria-label="Previous"></button>
        <div class="lb-media" aria-live="polite"></div>
        <button class="lb-next" aria-label="Next"></button>
      </div>
    `;
    document.body.appendChild(overlay);

    bodyEl   = overlay.querySelector('.lb-body');
    mediaEl  = overlay.querySelector('.lb-media');
    prevBtn  = overlay.querySelector('.lb-prev');
    nextBtn  = overlay.querySelector('.lb-next');
    closeBtn = overlay.querySelector('.lb-close');

    [bodyEl, mediaEl, prevBtn, nextBtn, closeBtn].forEach(el => {
      el.addEventListener('click', e => e.stopPropagation());
    });

    closeBtn.addEventListener('click', close);
    prevBtn.addEventListener('click', prev);
    nextBtn.addEventListener('click', next);

    overlay.addEventListener('click', (e) => {
      if (e.target !== overlay) return;
      const rect = bodyEl.getBoundingClientRect();
      const margin = 24;
      const insideX = e.clientX >= rect.left - margin && e.clientX <= rect.right + margin;
      const insideY = e.clientY >= rect.top  - margin && e.clientY <= rect.bottom + margin;
      if (insideX && insideY) return;
      close();
    });

    document.addEventListener('keydown', onKey);

    // Swipe
    let startX=0, dx=0;
    overlay.addEventListener('touchstart', e => { startX = e.touches[0].clientX; dx = 0; }, {passive:true});
    overlay.addEventListener('touchmove',  e => { dx = e.touches[0].clientX - startX; }, {passive:true});
    overlay.addEventListener('touchend',   () => { if (Math.abs(dx) > 40) (dx>0 ? prev() : next()); });
  }

  function lockPageScroll(){
    savedScrollY = window.scrollY || window.pageYOffset;
    document.body.style.position = 'fixed';
    document.body.style.top = `-${savedScrollY}px`;
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    window.scrollTo(0, savedScrollY);
  }
  function unlockPageScroll(){
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    window.scrollTo(0, savedScrollY);
  }

  function open(i){
    if (!overlay) buildOverlay();

    if (videoMutedAmbient && ambientWasOn){
      playAudio().catch(()=>{});
      videoMutedAmbient = false;
    }

    idx = (i + items.length) % items.length;
    const it = items[idx];

    if (!overlay.classList.contains('open')){
      document.body.classList.add('lb-active');
      overlay.classList.add('open');
      lockPageScroll();
      ambientWasOn = (localStorage.getItem('ambient-sound') === 'on');
    }

    mediaEl.innerHTML = '';
    vidEl = null;

    if (it.type === 'image'){
      const img = new Image();
      img.src = it.src;
      img.alt = '';
      img.draggable = false;
      mediaEl.appendChild(img);
    } else {
      vidEl = document.createElement('video');
      vidEl.src = it.src;
      vidEl.controls = true;
      vidEl.playsInline = true;
      vidEl.autoplay = true;
      mediaEl.appendChild(vidEl);

      if (ambientWasOn){
        stopAudio().catch(()=>{});
        videoMutedAmbient = true;
        try { if (ctx && ctx.state === 'suspended') ctx.resume(); } catch(_) {}
      }
    }
  }

  function close(){
    if (videoMutedAmbient && ambientWasOn){
      playAudio().catch(()=>{});
    }
    videoMutedAmbient = false;

    overlay.classList.remove('open');
    mediaEl.innerHTML = '';
    document.body.classList.remove('lb-active');
    unlockPageScroll();
  }

  function next(){ open(idx+1); }
  function prev(){ open(idx-1); }

  function onKey(e){
    if (!overlay || !overlay.classList.contains('open')) return;
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowRight') next();
    else if (e.key === 'ArrowLeft')  prev();
  }

  items.forEach((it, i) => {
    it.trigger.addEventListener('click', e => {
      e.preventDefault();
      open(i);
    });
  });
})();

/* Events embed: inline loading row; hide only when the widget is truly present */
(function(){
  const wrap = document.getElementById('eventsWrap');
  if (!wrap) return;

  const skeleton = document.getElementById('eventsSkeleton');
  const slot = wrap.querySelector('[data-events-calendar-app]');
  const bg = document.getElementById('bg-video');

  // Hard-hide the row and mark not busy
  function finish(){
    wrap.setAttribute('aria-busy','false');
    wrap.dataset.ready = '1';               // CSS hides .events-skeleton
    if (skeleton && skeleton.parentNode) {
      skeleton.style.display = 'none';      // belt-and-braces
      skeleton.parentNode.removeChild(skeleton);
    }
    if (bg) { bg.preload = 'auto'; try { bg.play(); } catch(_) {} }
    cleanup();
  }

  // A robust "is it really there?" test
  function looksReady(){
    // A) A real iframe is present AND has real area
    const ifr = wrap.querySelector('iframe');
    if (ifr && ifr.clientWidth >= 200 && ifr.clientHeight >= 220) return true;

    // B) The slot gained actual children (non-iframe builds)
    if (slot && slot.querySelector('*')) return true;

    // C) The container height grew substantially beyond the loading row
    const skH = skeleton ? skeleton.getBoundingClientRect().height : 0;
    const wrapH = wrap.getBoundingClientRect().height;
    if (wrapH > Math.max(300, skH + 120)) return true;

    return false;
  }

  // Wire iframe load if/when one appears (most reliable on iOS/Instagram)
  function wireIframe(ifr){
    if (!ifr || ifr._wired) return;
    ifr._wired = true;
    ifr.addEventListener('load', () => {
      // tiny delay for layout settling; then re-check
      setTimeout(() => { if (looksReady()) finish(); }, 120);
    }, { once:true });
  }

  // Observe DOM changes (iframes/children arriving)
  const mo = new MutationObserver(muts => {
    for (const m of muts){
      m.addedNodes && m.addedNodes.forEach(n=>{
        if (n.tagName === 'IFRAME') wireIframe(n);
        if (slot && slot.contains(n) && looksReady()) finish();
      });
    }
  });
  mo.observe(wrap, { childList: true, subtree: true });

  // Resize observer (container height changes without child mutations)
  let ro = null;
  if ('ResizeObserver' in window){
    ro = new ResizeObserver(() => { if (looksReady()) finish(); });
    ro.observe(wrap);
    if (slot) ro.observe(slot);
  }

  // Poll fallback (covers stubborn iOS/IG cases that miss both)
  const poll = setInterval(() => {
    const ifr = wrap.querySelector('iframe');
    if (ifr) wireIframe(ifr);             // ensure we’ll catch its load
    if (looksReady()) finish();
  }, 500);

  // Don’t leave aria-busy stuck forever if the network blocks entirely
  const safety = setTimeout(() => { wrap.setAttribute('aria-busy','false'); }, 60000);

  function cleanup(){
    clearInterval(poll);
    clearTimeout(safety);
    mo.disconnect();
    if (ro) ro.disconnect();
  }
})();
