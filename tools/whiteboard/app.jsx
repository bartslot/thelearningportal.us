import { createRoot } from 'react-dom/client'
import { Tldraw, getSnapshot, loadSnapshot } from 'tldraw'

const status = (text) => { document.getElementById('link').textContent = text }

/**
 * The bridge: board.json is the only thing the two sides share.
 *
 * Push our edits to it, pull anyone else's out of it. "Anyone else" is Bart in another tab, or
 * Claude writing the file straight from the repo — neither side has to know which.
 */
function bridge(editor) {
  // Handy from the browser console, and the only way an automated check can drive the board.
  window.editor = editor

  let ours = '0'          // the version WE last wrote, so our own save is not mistaken for an edit
  let mirror = ''         // the board as we last saw it, so applying someone else's edit does not echo
  let seen = new Set()    // shape ids the file has shown us, which is how a delete is told from a birth
  let timer = null

  const pull = async () => {
    const { version, snapshot } = await fetch('/board').then((r) => r.json()).catch(() => ({}))
    if (!snapshot || version === ours) return
    ours = version

    // MERGE the incoming records — never loadSnapshot(). Replacing the whole document drops
    // anything the other side has not seen yet, which is exactly how a batch of cards written
    // straight into board.json vanished the moment the open tab next saved.
    //
    // Only the DOCUMENT half is shared. A snapshot also carries session state — camera, selection,
    // current page — and applying someone else's would yank the viewport out from under whoever is
    // drawing. The shapes are the shared thing; where you happen to be looking is yours.
    const incoming = snapshot.store ?? {}
    const arrived = new Set(Object.keys(incoming))
    editor.store.mergeRemoteChanges(() => {
      // A local shape missing from the file was either just deleted THERE or just created HERE.
      // `seen` is the difference: the file has to have shown it to us before we treat it as gone.
      const gone = editor.store.allRecords()
        .filter((r) => r.typeName === 'shape' && seen.has(r.id) && !arrived.has(r.id))
        .map((r) => r.id)
      if (gone.length) editor.store.remove(gone)
      editor.store.put(Object.values(incoming))
    })
    seen = new Set([...arrived].filter((id) => id.startsWith('shape:')))

    // Re-read the mirror FROM THE STORE rather than keeping what arrived over the wire. Both
    // strings then come out of getSnapshot, so an untouched board serialises identically and push
    // can tell "already applied" from "a real edit" — key order alone would break a plain compare.
    //
    // Without it the sides echo forever: A saves, B applies it and immediately saves the same thing
    // back under a new mtime, which A then applies, once a second for as long as both tabs are open.
    mirror = JSON.stringify(getSnapshot(editor.store).document)
    status('pulled ' + new Date().toLocaleTimeString())
  }

  const push = async () => {
    // Take whatever landed in the file since our last poll BEFORE overwriting it. Without this the
    // 400ms save beats the 1s poll whenever someone is actively drawing, and their save ships a
    // document that never contained the other side's work.
    await pull()

    const body = JSON.stringify(getSnapshot(editor.store).document)
    if (body === mirror) return
    mirror = body
    const res = await fetch('/board', { method: 'PUT', body }).catch(() => null)
    if (!res) return status('offline — is server.mjs running?')
    ours = (await res.json()).version
    status('saved ' + new Date().toLocaleTimeString())
  }

  pull().then(() => status('board.json'))
  // Our own edits only, and only shape/page changes — camera moves would otherwise save constantly.
  editor.store.listen(
    () => { clearTimeout(timer); timer = setTimeout(push, 400) },
    { source: 'user', scope: 'document' },
  )
  setInterval(pull, 1000)
}

createRoot(document.getElementById('root')).render(<Tldraw onMount={bridge} />)
