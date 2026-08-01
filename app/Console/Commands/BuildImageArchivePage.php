<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Models\Scene;
use Illuminate\Console\Command;

/**
 * Build a browsable page of the archived images, grouped by the lesson they were made for.
 *
 * The point is reuse. An image that cannot be found again is worth nothing, and once a lesson is
 * deleted the only things left are the file path and the picture itself — so this puts every
 * surviving image on screen next to whatever identity could be recovered for it: the lesson title
 * and scene script where the lesson still exists, and the count of surviving narration recordings
 * where it does not.
 */
class BuildImageArchivePage extends Command
{
    protected $signature = 'lessons:build-image-archive
                            {--manifest=storage/app/orphan-images.json}
                            {--out=public/image-archive}';

    protected $description = 'Build a per-lesson browsable page of archived lesson images';

    public function handle(): int
    {
        $manifestPath = base_path((string) $this->option('manifest'));
        if (! is_file($manifestPath)) {
            $this->error("No manifest at {$manifestPath}. Run lessons:archive-images first.");

            return self::FAILURE;
        }

        $entries = collect(json_decode((string) file_get_contents($manifestPath), true) ?: [])
            ->filter(fn ($e) => ! empty($e['archived_path'] ?? $e['path']));

        $lessons = Lesson::pluck('title', 'id');
        $scriptsByLesson = $this->scriptsByLesson();
        $narrationCounts = $this->narrationCounts();
        $sceneOwner = Scene::pluck('lesson_id', 'id');

        // Attribute a file to a lesson ONLY when the folder and the scene agree.
        //
        // The database has been rebuilt, and both id sequences restarted: disk holds scene folders
        // up to 733 while the database stops at 220, and 231 scene folders sit under a lesson
        // folder that disagrees with the database about who owns that scene id. So neither id is
        // trustworthy alone. Grouping by folder filed Dutch parliament paintings under "The Punic
        // Wars"; grouping by scene id made the same mistake more quietly, because scene 92 today is
        // a different scene from the 92 that wrote these files. Requiring both to agree is the only
        // claim the data supports — everything else is honestly unidentified.
        $entries = $entries->map(function ($entry) use ($sceneOwner) {
            $sceneId = $this->sceneIdFromPath($entry['path']);
            $confident = $sceneId !== null && ($sceneOwner[$sceneId] ?? null) === $entry['lesson_id'];

            $entry['scene_id'] = $sceneId;
            $entry['group'] = $confident ? $entry['lesson_id'] : 'gone:'.$entry['lesson_id'];
            $entry['directory'] = $entry['lesson_id'];

            return $entry;
        });

        $groups = $entries
            ->groupBy('group')
            ->map(function ($files, $group) use ($lessons, $scriptsByLesson, $narrationCounts) {
                $alive = ! str_starts_with((string) $group, 'gone:');
                $lessonId = (int) str_replace('gone:', '', (string) $group);

                return [
                    'lesson_id' => $lessonId,
                    'title' => $alive ? ($lessons[$lessonId] ?? null) : null,
                    'alive' => $alive,
                    'directories' => $files->pluck('directory')->unique()->sort()->values(),
                    'bytes' => $files->sum(fn ($f) => $f['archived_bytes'] ?? $f['original_bytes']),
                    'original_bytes' => $files->sum('original_bytes'),
                    'narration_files' => $narrationCounts[$lessonId] ?? 0,
                    'scenes' => $scriptsByLesson[$lessonId] ?? [],
                    'images' => $files
                        ->sortByDesc('original_bytes')
                        ->map(fn ($f) => [
                            'url' => '/storage/'.($f['archived_path'] ?? $f['path']),
                            'role' => $f['role'],
                            'bytes' => $f['archived_bytes'] ?? $f['original_bytes'],
                            'was' => $f['original_bytes'],
                        ])->values(),
                ];
            })
            ->sortByDesc('bytes')
            ->values();

        $out = base_path((string) $this->option('out'));
        @mkdir($out, 0755, true);
        file_put_contents($out.'/data.json', json_encode($groups, JSON_UNESCAPED_SLASHES));
        file_put_contents($out.'/index.html', $this->page());

        $this->info(sprintf(
            'Wrote %d lessons, %d images to %s',
            $groups->count(),
            $entries->count(),
            $this->option('out'),
        ));

        return self::SUCCESS;
    }

    /** The scene id a file belongs to, from `lessons/{n}/scenes/{id}/…`; null for covers etc. */
    private function sceneIdFromPath(string $path): ?int
    {
        return preg_match('#/scenes/(\d+)/#', $path, $m) ? (int) $m[1] : null;
    }

    /** Scene scripts for lessons that still exist — the deleted ones have none to give. */
    private function scriptsByLesson(): array
    {
        return Scene::whereNotNull('script_segment')
            ->orderBy('lesson_id')->orderBy('order')
            ->get(['lesson_id', 'order', 'kind', 'chapter_name', 'location', 'script_segment'])
            ->groupBy('lesson_id')
            ->map(fn ($scenes) => $scenes->map(fn ($s) => [
                'order' => $s->order,
                'kind' => $s->kind,
                'name' => $s->chapter_name ?: $s->location,
                'script' => mb_substr((string) $s->script_segment, 0, 400),
            ])->values()->all())
            ->all();
    }

    /**
     * Surviving narration per lesson directory.
     *
     * This is the only trace of a deleted lesson's script: the words are gone from the database,
     * but they were spoken into these files and can be transcribed back out.
     */
    private function narrationCounts(): array
    {
        $counts = [];
        foreach (glob(base_path('storage/app/public/lessons/*'), GLOB_ONLYDIR) as $dir) {
            $id = (int) basename($dir);
            $found = shell_exec('find '.escapeshellarg($dir).' -name "narration.mp3" 2>/dev/null | wc -l');
            $counts[$id] = (int) trim((string) $found);
        }

        return $counts;
    }

    private function page(): string
    {
        return <<<'HTML'
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lesson image archive</title>
<style>
  :root { --ink:#e8eaf0; --dim:#8b93a7; --line:#232a3d; --bg:#0f172a; --accent:#f0b429; --dead:#f2789b; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink);
         font:15px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; }
  header { padding:28px 24px 10px; max-width:1400px; margin:0 auto; }
  h1 { font-size:22px; margin:0 0 6px; }
  p.lede { color:var(--dim); margin:0 0 14px; max-width:78ch; }
  .totals { display:flex; gap:26px; flex-wrap:wrap; color:var(--dim); font-size:13.5px;
            font-variant-numeric:tabular-nums; }
  .totals b { color:var(--accent); }
  main { max-width:1400px; margin:0 auto; padding:8px 24px 90px; }

  .filters { display:flex; gap:8px; margin:16px 0 4px; flex-wrap:wrap; }
  .filters button { border:1px solid var(--line); background:#151d31; color:var(--dim);
        padding:7px 13px; border-radius:8px; cursor:pointer; font-size:13px; }
  .filters button[aria-pressed=true] { border-color:var(--accent); color:var(--ink); }

  section { border-top:1px solid var(--line); padding:22px 0 6px; }
  .head { display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; margin-bottom:4px; }
  h2 { font-size:17px; margin:0; }
  .pill { font-size:11px; text-transform:uppercase; letter-spacing:.07em; padding:3px 9px;
          border-radius:999px; border:1px solid var(--line); color:var(--dim); }
  .pill.dead { color:var(--dead); border-color:#4a2436; }
  .pill.alive { color:#7ee0a8; border-color:#1f4436; }
  .meta { color:var(--dim); font-size:13px; font-variant-numeric:tabular-nums; }

  .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(178px,1fr)); gap:10px;
          margin:14px 0 6px; }
  figure { margin:0; background:#0b1120; border-radius:9px; overflow:hidden;
           border:1px solid var(--line); }
  figure img { display:block; width:100%; height:118px; object-fit:cover; background:#000; }
  figcaption { padding:6px 8px; font-size:11px; color:var(--dim);
               display:flex; justify-content:space-between; gap:6px; }
  figcaption b { color:var(--ink); font-weight:500; }

  details { margin:10px 0 0; }
  summary { cursor:pointer; color:var(--accent); font-size:13px; }
  .scene { border-left:2px solid var(--line); padding:6px 0 6px 12px; margin:10px 0; }
  .scene h3 { font-size:13px; margin:0 0 3px; color:var(--ink); font-weight:600; }
  .scene p { margin:0; color:var(--dim); font-size:13px; }
  .none { color:var(--dim); font-size:13px; font-style:italic; }
</style>

<header>
  <h1>Lesson image archive</h1>
  <p class="lede">Every image under <code>storage/app/public/lessons</code> that no lesson points at
    any more, re-encoded and grouped by the lesson it was made for. Nothing has been deleted.</p>
  <div class="totals" id="totals"></div>
  <div class="filters">
    <button data-f="all" aria-pressed="true">All</button>
    <button data-f="dead">Deleted lessons only</button>
    <button data-f="alive">Live lessons only</button>
  </div>
</header>

<main id="out"></main>

<script>
const kb = b => b >= 1048576 ? (b/1048576).toFixed(1)+' MB' : Math.round(b/1024)+' KB';
let groups = [], filter = 'all';

fetch('data.json').then(r => r.json()).then(d => { groups = d; totals(); render(); });

function totals() {
  const was = groups.reduce((n,g) => n + g.original_bytes, 0);
  const now = groups.reduce((n,g) => n + g.bytes, 0);
  const imgs = groups.reduce((n,g) => n + g.images.length, 0);
  const dead = groups.filter(g => !g.alive).length;
  document.getElementById('totals').innerHTML =
    `<span><b>${imgs}</b> images</span>
     <span><b>${groups.length}</b> lessons (${dead} deleted)</span>
     <span>was <b>${kb(was)}</b></span>
     <span>now <b>${kb(now)}</b></span>
     <span><b>${(was/now).toFixed(0)}x</b> smaller</span>`;
}

function render() {
  const shown = groups.filter(g => filter === 'all' || (filter === 'dead' ? !g.alive : g.alive));
  document.getElementById('out').innerHTML = shown.map(g => `
    <section>
      <div class="head">
        <h2>${g.title ? esc(g.title) : 'Deleted lesson — folder ' + g.lesson_id}</h2>
        <span class="pill ${g.alive ? 'alive' : 'dead'}">${g.alive ? 'live' : 'deleted'}</span>
        <span class="meta">${g.images.length} images ·
          ${kb(g.original_bytes)} → ${kb(g.bytes)}
          ${g.narration_files ? ' · ' + g.narration_files + ' narration recordings' : ''}
          · in folder${g.directories.length > 1 ? 's' : ''} ${g.directories.join(', ')}</span>
      </div>
      <div class="grid">
        ${g.images.map(i => `
          <figure>
            <a href="${i.url}" target="_blank"><img loading="lazy" src="${i.url}" alt="${esc(i.role)}"></a>
            <figcaption><span>${esc(i.role)}</span><b>${kb(i.bytes)}</b></figcaption>
          </figure>`).join('')}
      </div>
      ${scripts(g)}
    </section>`).join('');
}

function scripts(g) {
  if (g.scenes.length) {
    return `<details><summary>Script — ${g.scenes.length} scenes</summary>
      ${g.scenes.map(s => `<div class="scene">
        <h3>${s.order}. ${esc(s.name || s.kind)}</h3>
        <p>${esc(s.script || '')}</p></div>`).join('')}</details>`;
  }
  return `<p class="none">This lesson was deleted, so its script is gone from the database.${
    g.narration_files ? ` Its ${g.narration_files} narration recordings survive — the words were spoken into those files and can be transcribed back out.` : ''}</p>`;
}

function esc(s) { return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

document.querySelector('.filters').onclick = e => {
  if (!e.target.dataset.f) return;
  filter = e.target.dataset.f;
  document.querySelectorAll('.filters button').forEach(b =>
    b.setAttribute('aria-pressed', String(b.dataset.f === filter)));
  render();
};
</script>
HTML;
    }
}
