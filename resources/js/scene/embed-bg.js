/**
 * embed-bg.js — a Sketchfab 3D model or a video (YouTube / Vimeo / pasted iframe) as the
 * full-bleed background of a scene.
 *
 * Shared by the student player (#background-layer) and the wizard canvas, so a video
 * background frames identically in both — what the teacher sets up is what the class sees.
 */

const VIDEO_ASPECT = 16 / 9

/**
 * Mount an embed background inside `host` (which must be a positioned box).
 *
 * @param {HTMLElement} host
 * @param {{src: string, kind?: string, fit?: string}} embed
 * @param {{zIndex?: number}} [opts]
 * @returns {{el: HTMLElement, destroy: () => void} | null}
 */
export function mountEmbedBg(host, embed, opts = {}) {
    if (!host || !embed || !embed.src) return null

    const wrap = document.createElement('div')
    wrap.className = 'lesson-embed-bg'
    wrap.style.cssText = `position:absolute;inset:0;overflow:hidden;background:#000;z-index:${opts.zIndex ?? 1};`

    const iframe = document.createElement('iframe')
    iframe.src = embed.src
    iframe.title = embed.kind === 'sketchfab' ? '3D background' : 'Video background'
    iframe.setAttribute('allow', 'autoplay; fullscreen; xr-spatial-tracking; encrypted-media; picture-in-picture')
    iframe.setAttribute('allowfullscreen', 'true')
    iframe.style.border = '0'
    iframe.style.position = 'absolute'

    let ro = null
    const coverVideo = embed.kind === 'video' && (embed.fit || 'cover') === 'cover'
    if (!coverVideo) {
        // Sketchfab fills; a 'fit' video letterboxes inside a full-bleed iframe.
        iframe.style.inset = '0'; iframe.style.width = '100%'; iframe.style.height = '100%'
    } else {
        // Cover a 16:9 video: overflow the shorter axis + centre; keep sized on resize.
        iframe.style.left = '50%'; iframe.style.top = '50%'; iframe.style.transform = 'translate(-50%,-50%)'
        const fit = () => {
            const cw = wrap.clientWidth || 1, ch = wrap.clientHeight || 1
            if (cw / ch > VIDEO_ASPECT) { iframe.style.width = cw + 'px'; iframe.style.height = (cw / VIDEO_ASPECT) + 'px' }
            else { iframe.style.height = ch + 'px'; iframe.style.width = (ch * VIDEO_ASPECT) + 'px' }
        }
        fit()
        try { ro = new ResizeObserver(fit); ro.observe(wrap) } catch (_) { /* no RO */ }
    }

    wrap.appendChild(iframe)
    host.appendChild(wrap)

    return {
        el: wrap,
        destroy() {
            if (ro) { try { ro.disconnect() } catch (_) { /* gone */ } ro = null }
            wrap.remove()
        },
    }
}

/**
 * Identity of what is on stage. The wizard's 3s status poll re-fires scene:load unchanged, and
 * rebuilding the iframe on each of those restarts the video every three seconds — so the caller
 * compares this and only remounts on a real change.
 *
 * @param {{src?: string, kind?: string, fit?: string}} embed
 * @returns {string}
 */
export function embedBgSignature(embed) {
    return JSON.stringify([embed?.src ?? null, embed?.kind ?? null, embed?.fit ?? null])
}
