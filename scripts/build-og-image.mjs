import { chromium } from 'playwright'
import { mkdirSync } from 'node:fs'

const html = `<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600&family=Inter:wght@400;600&display=block">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{width:1200px;height:630px;background:#0f172a;color:#fff;font-family:Inter,system-ui;
       position:relative;overflow:hidden}
  .glow{position:absolute;inset:-20% 40% 40% -20%;
        background:radial-gradient(circle,rgba(251,191,36,.20),transparent 62%)}
  .glow2{position:absolute;inset:35% -25% -25% 45%;
        background:radial-gradient(circle,rgba(56,189,248,.16),transparent 60%)}
  .grain{position:absolute;inset:0;opacity:.35;
        background-image:radial-gradient(rgba(255,255,255,.07) 1px,transparent 1px);
        background-size:3px 3px}
  .wrap{position:relative;height:100%;display:flex;flex-direction:column;
        justify-content:center;padding:0 86px}
  .kicker{font-size:19px;letter-spacing:.22em;text-transform:uppercase;color:#fbbf24;font-weight:600}
  h1{font-family:Cinzel,Georgia,serif;font-size:82px;line-height:1.03;font-weight:600;margin-top:22px;
     letter-spacing:-.5px}
  p{font-size:29px;line-height:1.45;color:#cbd5e1;margin-top:26px;max-width:900px}
  .foot{position:absolute;left:86px;bottom:58px;display:flex;align-items:center;gap:14px;
        font-size:21px;color:#94a3b8}
  .dot{width:9px;height:9px;border-radius:50%;background:#fbbf24}
  .langs{position:absolute;right:86px;bottom:58px;font-size:19px;color:#64748b;letter-spacing:.1em}
</style></head><body>
  <div class="glow"></div><div class="glow2"></div><div class="grain"></div>
  <div class="wrap">
    <div class="kicker">The Learning Portal</div>
    <h1>History lessons that<br>tell the story</h1>
    <p>Narrated, illustrated history for the classroom. Built in minutes, played on any device.</p>
  </div>
  <div class="foot"><span class="dot"></span>history.thelearningportal.us</div>
  <div class="langs">EN · NL · DE · FR · IT</div>
</body></html>`

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 })
await page.setContent(html, { waitUntil: 'load' })
await page.evaluate(() => document.fonts.ready)
await page.waitForTimeout(600)
mkdirSync('public/images', { recursive: true })
await page.screenshot({ path: 'public/images/og-default.png' })
await browser.close()
console.log('wrote public/images/og-default.png')
