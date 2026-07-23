// Image gallery scene (kind 'gallery') — an auto-cycling slideshow with a side panel of
// title / date / story. Shared verbatim by the student player (lesson-player.js) and the
// teacher wizard preview (step3-scene-configurator.blade.php) so both render identically.
//
// cfg shape: { title, date_label, story, fit: 'cover'|'fit', images: [{ url, credit } | "url", ...] }.
// A blurred copy of each image fills the frame behind the photo and cross-fades with it, so the
// backdrop transitions between images too. `fit` = 'cover' (fill, may crop) or 'fit' (letterboxed
// on the blurred backdrop; the default).
// opts.startIndex opens the slideshow on a specific image (e.g. the thumbnail the user clicked).
// opts.editable (wizard only) makes the date/title/story contenteditable; opts.onEdit(field, value)
// fires on blur with the new text so the caller can persist it (student player leaves both off).
// Returns a handle whose destroy() stops the interval and clears the host.

const CYCLE_MS = 5000;

// Escape user text before injecting into innerHTML (title/date/story come from the DB).
const esc = (s) => String(s == null ? '' : s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

export function renderGallery(el, cfg = {}, { startIndex = 0, editable = false, onEdit = null } = {}) {
  const images = Array.isArray(cfg.images) ? cfg.images : [];
  const objFit = cfg.fit === 'cover' ? 'cover' : 'contain';   // 'fit' → contain (letterbox + blur backdrop)

  // When editable, each text field gets a data-field hook + a placeholder so empty fields are
  // still clickable. The subtle outline appears on hover/focus (see the injected <style>).
  const edit = editable
    ? (field, ph) => `contenteditable="true" data-edit="${field}" data-ph="${esc(ph)}" spellcheck="false"`
    : () => '';

  const host = document.createElement('div');
  host.style.cssText = 'position:absolute;inset:0;background:#0b1526;display:flex;align-items:stretch;';
  host.innerHTML = `
    ${editable ? `<style>
      [data-edit]{outline:1px dashed transparent;outline-offset:3px;border-radius:4px;transition:outline-color .15s,background .15s;cursor:text;}
      [data-edit]:hover{outline-color:rgba(148,163,184,.5);}
      [data-edit]:focus{outline:1px solid rgba(251,191,36,.9);background:rgba(251,191,36,.06);}
      [data-edit]:empty:before{content:attr(data-ph);color:#64748b;font-style:italic;}
    </style>` : ''}
    <div style="position:relative;flex:1;overflow:hidden;background:#0b1526;">
      <img data-bg="a" alt="" style="position:absolute;inset:-6%;width:112%;height:112%;object-fit:cover;filter:blur(28px) brightness(0.5);opacity:0;transition:opacity 1.1s ease;">
      <img data-bg="b" alt="" style="position:absolute;inset:-6%;width:112%;height:112%;object-fit:cover;filter:blur(28px) brightness(0.5);opacity:0;transition:opacity 1.1s ease;">
      <img data-slide="a" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:${objFit};opacity:0;transition:opacity 1s ease, transform 9s linear;">
      <img data-slide="b" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:${objFit};opacity:0;transition:opacity 1s ease, transform 9s linear;">
      <p data-credit style="position:absolute;left:1rem;bottom:0.75rem;margin:0;color:#cbd5e1;font-size:0.72rem;opacity:0.8;text-shadow:0 1px 3px rgba(0,0,0,.7);"></p>
    </div>
    <div style="width:22rem;max-width:38%;padding:2.2rem 1.8rem;background:rgba(9,16,30,0.92);color:#e2e8f0;display:flex;flex-direction:column;justify-content:center;">
      <p ${edit('date_label', 'Date…')} style="margin:0;font-size:0.72rem;letter-spacing:0.1em;text-transform:uppercase;color:#93a4bd;">${esc(cfg.date_label)}</p>
      <h2 ${edit('title', 'Title…')} style="margin:0.4rem 0 0;font-size:1.5rem;line-height:1.2;color:#fff;">${esc(cfg.title)}</h2>
      <p ${edit('story', 'Tell what happened at this stop…')} style="margin-top:1rem;font-size:0.95rem;line-height:1.65;white-space:pre-wrap;">${esc(cfg.story)}</p>
    </div>`;
  el.appendChild(host);

  if (editable && typeof onEdit === 'function') {
    host.querySelectorAll('[data-edit]').forEach((node) => {
      let last = node.textContent;
      node.addEventListener('blur', () => {
        const val = node.textContent.trim();
        if (val !== last) { last = val; onEdit(node.dataset.edit, val); }
      });
      // Enter commits (blur) on single-line fields; story keeps newlines.
      if (node.dataset.edit !== 'story') {
        node.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); node.blur(); } });
      }
    });
  }

  const slides = [host.querySelector('[data-slide="a"]'), host.querySelector('[data-slide="b"]')];
  const bgs = [host.querySelector('[data-bg="a"]'), host.querySelector('[data-bg="b"]')];
  const credit = host.querySelector('[data-credit]');
  let cursor = images.length ? ((Number(startIndex) || 0) % images.length + images.length) % images.length : 0;
  let front = 0;
  const show = () => {
    if (!images.length) return;
    const img = images[cursor % images.length];
    const src = img.url || img;
    const slide = slides[front];
    const bg = bgs[front];
    slide.src = src; bg.src = src;              // photo + its blurred backdrop share the source
    slide.style.transform = 'scale(1)';
    requestAnimationFrame(() => {
      slide.style.opacity = '1'; slide.style.transform = 'scale(1.06)';
      bg.style.opacity = '1';                   // backdrop cross-fades in with the photo
    });
    slides[1 - front].style.opacity = '0';
    bgs[1 - front].style.opacity = '0';
    credit.textContent = img.credit || '';
    front = 1 - front;
    cursor++;
  };
  show();
  const timer = images.length > 1 ? setInterval(show, CYCLE_MS) : null;

  return {
    destroy() {
      if (timer) clearInterval(timer);
      el.innerHTML = '';
    },
  };
}

window.renderGallery = renderGallery;
