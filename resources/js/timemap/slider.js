// A horizontal scrubbable timeline: a track with century ticks/labels and a draggable handle.
// Calls onYear(year) as the handle moves. Range is inclusive.
export function mountTimeSlider(el, { min, max, value, onYear }) {
  el.classList.add('relative', 'select-none');
  el.innerHTML = `
    <div class="tm-track relative h-10 cursor-pointer">
      <div class="tm-ticks absolute inset-x-0 bottom-0 h-full"></div>
      <div class="tm-handle absolute top-0 -ml-2 h-6 w-4 rounded bg-primary shadow" style="left:0"></div>
    </div>
    <div class="tm-readout mt-1 text-center text-sm font-semibold"></div>`;

  const track = el.querySelector('.tm-track');
  const handle = el.querySelector('.tm-handle');
  const readout = el.querySelector('.tm-readout');
  const ticksEl = el.querySelector('.tm-ticks');

  for (let y = Math.ceil(min / 100) * 100; y <= max; y += 100) {
    const pct = ((y - min) / (max - min)) * 100;
    const t = document.createElement('div');
    t.className = 'absolute bottom-0 border-l border-base-content/30 text-[10px] text-base-content/60';
    t.style.left = `${pct}%`;
    t.style.height = y % 500 === 0 ? '100%' : '50%';
    if (y % 500 === 0) t.innerHTML = `<span class="absolute -left-3 -top-4">${y < 0 ? Math.abs(y) + ' BCE' : y}</span>`;
    ticksEl.appendChild(t);
  }

  let current = value;
  const fmt = (y) => (y < 0 ? `${Math.abs(y)} BCE` : `${y} CE`);
  const render = () => {
    const pct = ((current - min) / (max - min)) * 100;
    handle.style.left = `${pct}%`;
    readout.textContent = fmt(current);
  };
  const setFromClientX = (clientX) => {
    const r = track.getBoundingClientRect();
    const pct = Math.min(1, Math.max(0, (clientX - r.left) / r.width));
    current = Math.round((min + pct * (max - min)) / 10) * 10;
    render();
    onYear(current);
  };

  let dragging = false;
  track.addEventListener('pointerdown', (e) => { dragging = true; track.setPointerCapture(e.pointerId); setFromClientX(e.clientX); });
  track.addEventListener('pointermove', (e) => { if (dragging) setFromClientX(e.clientX); });
  track.addEventListener('pointerup', () => { dragging = false; });

  render();
  return { setYear: (y) => { current = y; render(); } };
}
