# Avatar Upload & Morph Transfer Pipeline — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "+" upload card to the AvatarLab avatar grid that accepts a GLB file, creates an avatar record immediately, runs Blender morph transfer in the background, and opens a modal to fill in name, gender, age, and voice while it processes.

**Architecture:** A Livewire file-upload method creates the Avatar DB record and directory, queues `TransferAvatarMorphs` (which shells out to Blender), then dispatches a browser event on completion to refresh the card. A DaisyUI modal with two tabs (Info / Voice) opens alongside; each field saves immediately on change. The voice tab reuses the existing ElevenLabs voice strip filtered by gender.

**Tech Stack:** Laravel 12, Livewire 3, Alpine.js, DaisyUI 5, Tailwind CSS v4, SQLite (queue: database), Blender 4.x CLI.

---

## File Map

| Action | File |
|--------|------|
| Create | `database/migrations/2026_05_11_add_morph_status_to_avatars_table.php` |
| Create | `app/Jobs/TransferAvatarMorphs.php` |
| Create | `scripts/transfer_morphs_single.py` |
| Modify | `app/Models/Avatar.php` — add `morph_status` to `$fillable` and `casts()` |
| Modify | `app/Livewire/Admin/AvatarLab.php` — add upload method, modal state, metadata save methods |
| Modify | `resources/views/livewire/admin/avatar-lab.blade.php` — add "+" card, modal markup |

---

### Task 1: Migration — add `morph_status` to avatars

**Files:**
- Create: `database/migrations/2026_05_11_add_morph_status_to_avatars_table.php`

The avatar needs a `morph_status` column so the UI can show "processing" / "ready" / "failed" states independently of the existing `sprite_status` column.

- [ ] **Step 1: Create the migration file**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            // 'pending' = not yet uploaded, 'processing' = Blender running,
            // 'ready' = morphs transferred, 'failed' = Blender error
            $table->string('morph_status')->default('ready')->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('avatars', function (Blueprint $table): void {
            $table->dropColumn('morph_status');
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
cd /Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us
php artisan migrate
```

Expected: `Migrating: 2026_05_11_add_morph_status_to_avatars_table` then `Migrated`.

- [ ] **Step 3: Update Avatar model**

In `app/Models/Avatar.php`, add `'morph_status'` to `$fillable`:

```php
protected $fillable = [
    'name',
    'short_name',
    'avatar_title',
    'slug',
    'description',
    'portrait_path',
    'subject',
    'voice_provider',
    'voice_id',
    'voice_speed',
    'voice_pitch',
    'voice_settings',
    'is_active',
    'sort_order',
    'portrait_original_path',
    'landmarks_json',
    'sprite_status',
    'presentation_mode',
    'age',
    'gender',
    'emotion_style',
    'expressiveness',
    'speaking_speed',
    'frame_background',
    'skin_tone',
    'body_type',
    'source_id',
    'morph_status',   // 'pending' | 'processing' | 'ready' | 'failed'
];
```

No change to `casts()` — `morph_status` stays a plain string.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_11_add_morph_status_to_avatars_table.php app/Models/Avatar.php
git commit -m "feat: add morph_status column to avatars table"
```

---

### Task 2: Blender single-avatar morph transfer script

**Files:**
- Create: `scripts/transfer_morphs_single.py`

This is the same Surface Deform logic as `transfer_morphs_skin.py` but processes one avatar by ID and auto-detects the head node name.

- [ ] **Step 1: Create the script**

```python
"""
transfer_morphs_single.py — transfer viseme morphs from donor GLB to one avatar

Usage:
  /Applications/Blender.app/Contents/MacOS/Blender --background --python \
    transfer_morphs_single.py -- <avatar_id> <avatar_dir>

Args (after --):
  avatar_id   — integer, used only for logging
  avatar_dir  — absolute path to the avatar folder (contains character.glb)
"""

import bpy
import os
import sys
import shutil

DONOR_GLB = "/Users/bartslot/Downloads/Avatars/ready-player-me-avatar/source/617b091cfb622cf1cd9cc537.glb"
DONOR_HEAD_NAME = "Wolf3D_Head"

# Blender passes script args after "--"
argv = sys.argv
try:
    sep = argv.index("--")
    args = argv[sep + 1:]
except ValueError:
    args = []

if len(args) < 2:
    print("[ERROR] Usage: blender --background --python transfer_morphs_single.py -- <avatar_id> <avatar_dir>")
    sys.exit(1)

AVATAR_ID  = args[0]
AVATAR_DIR = args[1]


def clear_scene():
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete()
    for block in bpy.data.meshes:
        if block.users == 0:
            bpy.data.meshes.remove(block)
    for block in bpy.data.materials:
        if block.users == 0:
            bpy.data.materials.remove(block)
    for block in bpy.data.images:
        if block.users == 0:
            bpy.data.images.remove(block)
    for block in bpy.data.armatures:
        if block.users == 0:
            bpy.data.armatures.remove(block)


def import_glb(path):
    bpy.ops.import_scene.gltf(filepath=path)


def find_head(skip_prefix="__donor"):
    """
    Find the head mesh in the scene. Checks for nodes named Wolf3D_Head or Wolf3D_Skin,
    skipping donor objects. Uses vertex count 2000-2300 as a heuristic if name unclear.
    """
    candidates = []
    for obj in bpy.data.objects:
        if obj.type != 'MESH':
            continue
        if obj.name.startswith(skip_prefix):
            continue
        base = obj.name.split(".")[0]
        if base in ("Wolf3D_Head", "Wolf3D_Skin"):
            return obj
        # fallback: face-ish vertex count
        vcount = len(obj.data.vertices)
        if 2000 <= vcount <= 2500:
            candidates.append(obj)

    return candidates[0] if len(candidates) == 1 else None


def load_donor():
    import_glb(DONOR_GLB)
    donor_head = None
    for obj in list(bpy.data.objects):
        if obj.name == DONOR_HEAD_NAME or obj.name.startswith(DONOR_HEAD_NAME + "."):
            donor_head = obj
        safe = f"__donor_{obj.name}__"
        obj.name = safe
        if obj.data:
            obj.data.name = safe
    if not donor_head:
        raise RuntimeError(f"Donor head '{DONOR_HEAD_NAME}' not found in {DONOR_GLB}")
    print(f"[DONOR] {len(donor_head.data.vertices)}v, "
          f"{len(donor_head.data.shape_keys.key_blocks) - 1} shape keys")
    return donor_head


def transfer_shape_keys(donor_obj, target_obj):
    shape_keys = donor_obj.data.shape_keys
    if not shape_keys:
        raise RuntimeError("Donor has no shape keys")

    key_blocks  = shape_keys.key_blocks
    morph_names = [kb.name for kb in key_blocks[1:]]
    print(f"[TRANSFER] {len(morph_names)} shape keys → '{target_obj.name}'")

    if not target_obj.data.shape_keys:
        target_obj.shape_key_add(name="Basis", from_mix=False)

    bpy.ops.object.select_all(action='DESELECT')
    bpy.context.view_layer.objects.active = target_obj
    target_obj.select_set(True)

    mod = target_obj.modifiers.new(name="SurfaceDeformTransfer", type='SURFACE_DEFORM')
    mod.target = donor_obj
    mod.falloff = 4.0

    bpy.ops.object.surfacedeform_bind(modifier=mod.name)
    if not mod.is_bound:
        raise RuntimeError(
            f"Surface Deform failed to bind '{target_obj.name}' "
            f"({len(target_obj.data.vertices)}v) → '{donor_obj.name}' "
            f"({len(donor_obj.data.vertices)}v)"
        )

    print("[BIND] Bound successfully")

    for name in morph_names:
        for kb in key_blocks:
            kb.value = 1.0 if kb.name == name else 0.0
        new_sk = target_obj.shape_key_add(name=name, from_mix=True)
        new_sk.value = 0.0
        print(f"  baked: {name}")

    for kb in key_blocks:
        kb.value = 0.0

    target_obj.modifiers.remove(mod)
    print(f"[TRANSFER] Done")


def main():
    glb_path    = os.path.join(AVATAR_DIR, "character.glb")
    backup_path = os.path.join(AVATAR_DIR, "character.original.glb")

    if not os.path.exists(glb_path):
        print(f"[ERROR] GLB not found: {glb_path}")
        sys.exit(1)

    if not os.path.exists(backup_path):
        shutil.copy2(glb_path, backup_path)
        print(f"[BACKUP] {glb_path} → {backup_path}")

    print(f"\n{'='*60}")
    print(f"[AVATAR {AVATAR_ID}] Processing {glb_path}")

    clear_scene()
    donor_head = load_donor()
    import_glb(glb_path)

    meshes = [o for o in bpy.data.objects if o.type == 'MESH' and not o.name.startswith("__donor")]
    print(f"[DEBUG] Meshes: {[o.name for o in meshes]}")

    target_head = find_head()
    if not target_head:
        print(f"[ERROR] Could not find head mesh. Available: {[o.name for o in meshes]}")
        sys.exit(1)

    print(f"[TARGET] '{target_head.name}' — {len(target_head.data.vertices)}v")

    transfer_shape_keys(donor_head, target_head)

    # Zero all shape keys on non-donor meshes before export
    for obj in bpy.data.objects:
        if obj.name.startswith("__donor"):
            obj.hide_set(True)
            continue
        if obj.type == 'MESH' and obj.data.shape_keys:
            for kb in obj.data.shape_keys.key_blocks:
                kb.value = 0.0

    sk_count = len(target_head.data.shape_keys.key_blocks) - 1 if target_head.data.shape_keys else 0
    print(f"[VERIFY] {sk_count} shape keys on head before export")

    bpy.ops.export_scene.gltf(
        filepath=glb_path,
        export_format='GLB',
        use_visible=True,
        export_apply=False,
        export_morph=True,
        export_morph_normal=False,
        export_morph_tangent=False,
        export_yup=True,
    )

    print(f"[DONE] Avatar {AVATAR_ID}: exported to {glb_path}")
    sys.exit(0)


main()
```

- [ ] **Step 2: Commit**

```bash
git add scripts/transfer_morphs_single.py
git commit -m "feat: blender single-avatar morph transfer script"
```

---

### Task 3: `TransferAvatarMorphs` Job

**Files:**
- Create: `app/Jobs/TransferAvatarMorphs.php`

The job shells out to Blender, waits for it, then sets `morph_status` and dispatches a Livewire browser event.

- [ ] **Step 1: Create the job**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Avatar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class TransferAvatarMorphs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // Blender can take a while
    public int $tries   = 1;

    public function __construct(
        private readonly int $avatarId
    ) {}

    public function handle(): void
    {
        $avatar = Avatar::find($this->avatarId);
        if (! $avatar) {
            Log::error("TransferAvatarMorphs: avatar {$this->avatarId} not found");
            return;
        }

        $avatarDir = public_path("avatars/{$this->avatarId}");
        $script    = base_path('scripts/transfer_morphs_single.py');
        $blender   = '/Applications/Blender.app/Contents/MacOS/Blender';

        $cmd = escapeshellcmd($blender)
            . ' --background --python ' . escapeshellarg($script)
            . ' -- ' . escapeshellarg((string) $this->avatarId)
            . ' ' . escapeshellarg($avatarDir)
            . ' 2>&1';

        Log::info("TransferAvatarMorphs: starting Blender for avatar {$this->avatarId}");
        exec($cmd, $output, $exitCode);

        $logOutput = implode("\n", $output);
        Log::info("TransferAvatarMorphs: Blender exit={$exitCode}", ['output' => $logOutput]);

        if ($exitCode === 0) {
            $avatar->update(['morph_status' => 'ready']);
            // Notify the browser — AvatarLab listens for this event
            \Livewire\Livewire::dispatch('avatar-morph-ready', avatarId: $this->avatarId);
        } else {
            $avatar->update(['morph_status' => 'failed']);
            \Livewire\Livewire::dispatch('avatar-morph-ready', avatarId: $this->avatarId);
            Log::error("TransferAvatarMorphs: Blender failed for avatar {$this->avatarId}", ['output' => $logOutput]);
        }
    }
}
```

- [ ] **Step 2: Verify queue config accepts 10-minute jobs**

```bash
grep -E "QUEUE_CONNECTION|retry_after" /Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us/config/queue.php | head -10
```

If `retry_after` is less than 600 for the database driver, increase it to 660:

```php
// config/queue.php  — database driver block
'database' => [
    'driver'       => 'database',
    'connection'   => env('DB_QUEUE_CONNECTION'),
    'table'        => env('DB_QUEUE_TABLE', 'jobs'),
    'queue'        => env('DB_QUEUE', 'default'),
    'retry_after'  => 660,
    'after_commit' => false,
],
```

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/TransferAvatarMorphs.php config/queue.php
git commit -m "feat: TransferAvatarMorphs job — shells out to Blender, sets morph_status on completion"
```

---

### Task 4: AvatarLab — upload method and modal state

**Files:**
- Modify: `app/Livewire/Admin/AvatarLab.php`

Add the GLB upload method that creates the avatar record + queues the job, plus properties for the "new avatar" modal.

- [ ] **Step 1: Add new public properties at the top of the class**

After the existing `public Collection $avatars;` line, add:

```php
// ── New avatar upload modal state ─────────────────────────────────────────
public ?int    $newAvatarId          = null;   // set after upload, drives modal open
public string  $newAvatarName        = '';
public string  $newAvatarGender      = 'male';
public int     $newAvatarAge         = 30;
public string  $newAvatarVoiceId     = '';
public string  $newAvatarMorphStatus = 'processing';
public string  $newAvatarModalTab    = 'info'; // 'info' | 'voice'
public $newAvatarGlbFile;                      // Livewire temp upload
```

- [ ] **Step 2: Add `updatedNewAvatarGlbFile()` hook**

This fires automatically when Livewire finishes uploading the file:

```php
public function updatedNewAvatarGlbFile(): void
{
    if (! $this->newAvatarGlbFile) {
        return;
    }

    if (strtolower($this->newAvatarGlbFile->getClientOriginalExtension()) !== 'glb') {
        session()->flash('error', 'Only .glb files are allowed.');
        $this->reset('newAvatarGlbFile');
        return;
    }

    if ($this->newAvatarGlbFile->getSize() > 50 * 1024 * 1024) {
        session()->flash('error', 'GLB file must be under 50 MB.');
        $this->reset('newAvatarGlbFile');
        return;
    }

    // Create avatar record immediately with a placeholder slug
    $avatar = Avatar::create([
        'name'          => 'New Avatar',
        'slug'          => 'new-avatar-' . uniqid(),
        'gender'        => 'male',
        'age'           => 30,
        'voice_provider'=> 'elevenlabs',
        'voice_id'      => '',
        'is_active'     => false,   // hidden from students until metadata is filled
        'sort_order'    => Avatar::max('sort_order') + 1,
        'morph_status'  => 'processing',
    ]);

    // Create the avatar directory and move GLB into place
    $dir = public_path("avatars/{$avatar->id}");
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $this->newAvatarGlbFile->move($dir, 'character.glb');

    // Refresh avatar list to include new entry
    $this->avatars = Avatar::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order', 'morph_status', 'is_active']);

    // Open modal
    $this->newAvatarId          = $avatar->id;
    $this->newAvatarName        = '';
    $this->newAvatarGender      = 'male';
    $this->newAvatarAge         = 30;
    $this->newAvatarVoiceId     = '';
    $this->newAvatarMorphStatus = 'processing';
    $this->newAvatarModalTab    = 'info';

    // Queue Blender morph transfer
    \App\Jobs\TransferAvatarMorphs::dispatch($avatar->id);

    $this->reset('newAvatarGlbFile');
}
```

- [ ] **Step 3: Add metadata save methods**

```php
public function saveNewAvatarMeta(): void
{
    if (! $this->newAvatarId) {
        return;
    }

    $slug = \Illuminate\Support\Str::slug($this->newAvatarName ?: 'avatar') . '-' . $this->newAvatarId;

    Avatar::where('id', $this->newAvatarId)->update([
        'name'      => $this->newAvatarName ?: 'New Avatar',
        'slug'      => $slug,
        'gender'    => $this->newAvatarGender,
        'age'       => $this->newAvatarAge,
        'is_active' => (bool) $this->newAvatarName, // activate once named
    ]);

    // Refresh list
    $this->avatars = Avatar::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order', 'morph_status', 'is_active']);
}

public function saveNewAvatarVoice(string $voiceId): void
{
    if (! $this->newAvatarId) {
        return;
    }

    $this->newAvatarVoiceId = $voiceId;

    Avatar::where('id', $this->newAvatarId)->update([
        'voice_provider' => 'elevenlabs',
        'voice_id'       => $voiceId,
    ]);
}

public function closeNewAvatarModal(): void
{
    // Delete the avatar record if still unnamed
    if ($this->newAvatarId) {
        $avatar = Avatar::find($this->newAvatarId);
        if ($avatar && ! $avatar->name || $avatar?->name === 'New Avatar') {
            $dir = public_path("avatars/{$this->newAvatarId}");
            if (is_dir($dir)) {
                \Illuminate\Support\Facades\File::deleteDirectory($dir);
            }
            $avatar?->forceDelete();
        }
    }
    $this->reset('newAvatarId', 'newAvatarName', 'newAvatarGender', 'newAvatarAge', 'newAvatarVoiceId', 'newAvatarMorphStatus', 'newAvatarModalTab');
    $this->avatars = Avatar::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order', 'morph_status', 'is_active']);
}
```

- [ ] **Step 4: Add morph-ready listener method**

When the job finishes it dispatches a browser event. The Livewire component refreshes the card state:

```php
#[\Livewire\Attributes\On('avatar-morph-ready')]
public function onMorphReady(int $avatarId): void
{
    if ($this->newAvatarId === $avatarId) {
        $avatar = Avatar::find($avatarId);
        $this->newAvatarMorphStatus = $avatar?->morph_status ?? 'failed';
    }
    // Refresh avatar list so card shows new status
    $this->avatars = Avatar::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order', 'morph_status', 'is_active']);
}
```

- [ ] **Step 5: Update `mount()` to load morph_status and is_active**

Change the `$this->avatars` query in `mount()`:

```php
$this->avatars = Avatar::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'sort_order', 'morph_status', 'is_active']);
```

Also update the same query in `selectAvatar()` — it doesn't reload the list, so no change needed there. But all four places that reload `$this->avatars` should include the new columns. Search for `Avatar::` in the component and add `'morph_status', 'is_active'` to every `->get([...])` call.

- [ ] **Step 6: Add `#[Computed]` voices list for the new avatar modal**

The existing `voices()` computed filters by `$this->gender`. Add a second computed for the modal that filters by `$this->newAvatarGender`:

```php
#[Computed]
public function newAvatarVoices(): array
{
    $all      = app(ElevenLabsService::class)->getVoices();
    $filtered = array_values(array_filter($all, fn ($v) => $v['gender'] === $this->newAvatarGender));
    return count($filtered) >= 2 ? $filtered : $all;
}
```

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Admin/AvatarLab.php
git commit -m "feat: AvatarLab GLB upload, modal state, morph-ready listener"
```

---

### Task 5: Blade — "+" upload card and processing card state

**Files:**
- Modify: `resources/views/livewire/admin/avatar-lab.blade.php`

Add the upload card at the end of the avatar grid, and a "processing" overlay for new avatars.

- [ ] **Step 1: Update the avatar list query in mount to include `is_active=false` new uploads**

The grid currently only shows `is_active=true`. New avatars start as `is_active=false`. We need them to appear in the grid while processing. Update the `mount()` query in `AvatarLab.php` (already done in Task 4 Step 5) and the grid loop in the blade to show both active and newly-uploaded-but-processing avatars.

In the blade, find the `@foreach($avatars as $avatar)` loop (around line 116). The `$avatars` collection now includes `is_active` and `morph_status`. No blade change needed here — the controller already handles it.

- [ ] **Step 2: Add processing state overlay to existing avatar card**

In the avatar card button (around line 125 in the blade), add a processing overlay that shows when `morph_status === 'processing'`:

```blade
<button
    wire:click="selectAvatar({{ $avatar->id }})"
    title="{{ $avatar->name }}"
    class="group relative rounded-xl overflow-hidden aspect-square transition-all
        {{ $isSelected
            ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900'
            : 'ring-1 ring-slate-700/50 hover:ring-slate-500 opacity-60 hover:opacity-100' }}"
>
    @if($thumbUrl)
        <img src="{{ $thumbUrl }}" alt="{{ $avatar->name }}" class="w-full h-full object-cover object-top" />
    @else
        <div class="w-full h-full bg-slate-800 flex items-center justify-center">
            <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
        </div>
    @endif

    {{-- Processing overlay --}}
    @if(($avatar->morph_status ?? 'ready') === 'processing')
        <div class="absolute inset-0 bg-slate-900/75 flex flex-col items-center justify-center gap-1">
            <span class="loading loading-spinner loading-xs text-amber-400"></span>
            <span class="text-[8px] text-amber-300 font-medium">Processing</span>
        </div>
    @elseif(($avatar->morph_status ?? 'ready') === 'failed')
        <div class="absolute inset-0 bg-red-900/60 flex items-center justify-center">
            <span class="text-[8px] text-red-300 font-medium">Failed</span>
        </div>
    @endif

    {{-- Name tooltip on hover --}}
    <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/80 to-transparent px-1 py-1
        opacity-0 group-hover:opacity-100 {{ $isSelected ? 'opacity-100' : '' }} transition-opacity">
        <p class="text-[9px] text-white leading-tight truncate">{{ $avatar->name }}</p>
    </div>
</button>
```

- [ ] **Step 3: Add the "+" upload card after the avatar loop**

After `@endforeach` (end of the avatar grid loop), add:

```blade
{{-- Upload new avatar card --}}
<label
    class="group relative rounded-xl overflow-hidden aspect-square cursor-pointer
           ring-1 ring-slate-700/50 hover:ring-slate-500 bg-slate-800/60 hover:bg-slate-800
           flex items-center justify-center transition-all"
    title="Upload new avatar GLB"
>
    <input
        type="file"
        accept=".glb"
        class="sr-only"
        wire:model="newAvatarGlbFile"
    >
    <svg class="w-8 h-8 text-slate-600 group-hover:text-slate-400 transition-colors"
         fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
    </svg>
    {{-- Spinner while file is uploading to server --}}
    <div wire:loading wire:target="newAvatarGlbFile"
         class="absolute inset-0 bg-slate-900/80 flex items-center justify-center">
        <span class="loading loading-spinner loading-sm text-amber-400"></span>
    </div>
</label>
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/admin/avatar-lab.blade.php
git commit -m "feat: avatar grid upload card with processing state overlay"
```

---

### Task 6: Blade — new avatar modal (Info + Voice tabs)

**Files:**
- Modify: `resources/views/livewire/admin/avatar-lab.blade.php`

Add the DaisyUI modal that opens when `$newAvatarId` is set.

- [ ] **Step 1: Add modal markup at the bottom of the root div (before closing `</div>`)**

```blade
{{-- ── New Avatar Modal ──────────────────────────────────────────────────── --}}
@if($newAvatarId)
<dialog class="modal modal-open">
    <div class="modal-box bg-slate-900 border border-slate-700/60 max-w-md w-full">

        {{-- Header --}}
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-base font-semibold text-slate-100">New Avatar</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    @if($newAvatarMorphStatus === 'processing')
                        <span class="inline-flex items-center gap-1">
                            <span class="loading loading-spinner loading-xs text-amber-400"></span>
                            <span class="text-amber-400">Transferring morph targets…</span>
                        </span>
                    @elseif($newAvatarMorphStatus === 'ready')
                        <span class="text-emerald-400">✓ Morph transfer complete</span>
                    @else
                        <span class="text-red-400">⚠ Morph transfer failed — avatar may lack lip sync</span>
                    @endif
                </p>
            </div>
            <button wire:click="closeNewAvatarModal" class="btn btn-ghost btn-xs btn-circle text-slate-400">✕</button>
        </div>

        {{-- Tabs --}}
        <div role="tablist" class="tabs tabs-border mb-4">
            <button
                role="tab"
                wire:click="$set('newAvatarModalTab', 'info')"
                class="tab {{ $newAvatarModalTab === 'info' ? 'tab-active text-amber-400' : 'text-slate-400' }}"
            >Info</button>
            <button
                role="tab"
                wire:click="$set('newAvatarModalTab', 'voice')"
                class="tab {{ $newAvatarModalTab === 'voice' ? 'tab-active text-amber-400' : 'text-slate-400' }}"
            >Voice</button>
        </div>

        {{-- Info tab --}}
        @if($newAvatarModalTab === 'info')
        <div class="space-y-4">
            <label class="form-control">
                <span class="label-text text-slate-400 text-xs mb-1 block">Name</span>
                <input
                    type="text"
                    wire:model.live.debounce.600ms="newAvatarName"
                    wire:change="saveNewAvatarMeta"
                    placeholder="e.g. Cleopatra"
                    class="input input-sm bg-slate-800 border-slate-700 text-slate-100 w-full"
                    autofocus
                >
            </label>

            <label class="form-control">
                <span class="label-text text-slate-400 text-xs mb-1 block">Gender</span>
                <select
                    wire:model.live="newAvatarGender"
                    wire:change="saveNewAvatarMeta"
                    class="select select-sm bg-slate-800 border-slate-700 text-slate-100 w-full"
                >
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
            </label>

            <label class="form-control">
                <span class="label-text text-slate-400 text-xs mb-1 block">Age</span>
                <input
                    type="number"
                    min="1"
                    max="120"
                    wire:model.live="newAvatarAge"
                    wire:change="saveNewAvatarMeta"
                    class="input input-sm bg-slate-800 border-slate-700 text-slate-100 w-32"
                >
            </label>
        </div>
        @endif

        {{-- Voice tab --}}
        @if($newAvatarModalTab === 'voice')
        <div>
            <p class="text-xs text-slate-500 mb-3">
                Showing {{ $newAvatarGender }} voices.
                @if(!$newAvatarGender)
                    Set gender in the Info tab to filter voices.
                @endif
            </p>
            {{-- Voice strip — same structure as main narration strip --}}
            <div class="flex gap-2 overflow-x-auto pb-2 snap-x snap-mandatory"
                 x-data
                 style="scroll-snap-type: x mandatory">
                @foreach($this->newAvatarVoices as $voice)
                @php
                    $isSelected = $newAvatarVoiceId === $voice['id'];
                @endphp
                <button
                    wire:click="saveNewAvatarVoice('{{ $voice['id'] }}')"
                    title="{{ $voice['label'] }}"
                    class="shrink-0 snap-start w-[72px] h-[72px] rounded-xl border transition-all relative overflow-hidden
                        {{ $isSelected
                            ? 'border-amber-400 ring-2 ring-amber-400/50'
                            : 'border-slate-700/60 hover:border-slate-500' }}
                        {{ $voice['gradient_class'] ?? 'vg-base' }}"
                >
                    <div class="absolute inset-0 flex flex-col items-center justify-center px-1 text-center">
                        <span class="text-[9px] font-semibold text-white/90 leading-tight line-clamp-3">
                            {{ $voice['label'] }}
                        </span>
                    </div>
                    {{-- Play preview button --}}
                    @if(!empty($voice['preview_url']))
                    <span
                        role="button"
                        tabindex="0"
                        x-on:click.stop="
                            let a = new Audio('{{ $voice['preview_url'] }}');
                            a.play();
                        "
                        class="absolute top-1 right-1 text-white/60 hover:text-white text-xs cursor-pointer"
                    >▶</span>
                    @endif
                </button>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Footer --}}
        <div class="modal-action mt-6">
            <button
                wire:click="saveNewAvatarMeta"
                class="btn btn-primary btn-sm"
                @disabled(!$newAvatarName)
            >Save &amp; Close</button>
        </div>

    </div>
    {{-- Click outside to close --}}
    <form method="dialog" class="modal-backdrop">
        <button wire:click="closeNewAvatarModal">close</button>
    </form>
</dialog>
@endif
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/admin/avatar-lab.blade.php
git commit -m "feat: new avatar modal with Info and Voice tabs"
```

---

### Task 7: Wire up `avatar-morph-ready` browser event from queue job

The job dispatches `\Livewire\Livewire::dispatch(...)` but this only works for in-process Livewire. For a queued job, we need to use a database event or polling. The simplest approach: add a Livewire polling directive on the card that stops once `morph_status` changes from `processing`.

- [ ] **Step 1: Add polling to the processing avatar card**

In the avatar grid (Task 5 Step 2), add a `wire:poll` on the processing overlay so the component re-renders every 3 seconds while the job runs:

```blade
{{-- Processing overlay with poll --}}
@if(($avatar->morph_status ?? 'ready') === 'processing')
    <div
        class="absolute inset-0 bg-slate-900/75 flex flex-col items-center justify-center gap-1"
        wire:poll.3000ms="refreshAvatarList"
    >
        <span class="loading loading-spinner loading-xs text-amber-400"></span>
        <span class="text-[8px] text-amber-300 font-medium">Processing</span>
    </div>
```

- [ ] **Step 2: Add `refreshAvatarList()` to AvatarLab.php**

```php
public function refreshAvatarList(): void
{
    $this->avatars = Avatar::orderBy('sort_order')->orderBy('name')
        ->get(['id', 'name', 'sort_order', 'morph_status', 'is_active']);

    // Update modal morph status if open
    if ($this->newAvatarId) {
        $avatar = Avatar::find($this->newAvatarId);
        $this->newAvatarMorphStatus = $avatar?->morph_status ?? 'failed';
    }
}
```

- [ ] **Step 3: Remove the now-unnecessary `\Livewire\Livewire::dispatch` from the job**

In `app/Jobs/TransferAvatarMorphs.php`, remove these two lines (polling handles the update):

```php
// Remove these:
\Livewire\Livewire::dispatch('avatar-morph-ready', avatarId: $this->avatarId);
```

Also remove the `#[On('avatar-morph-ready')]` listener from `AvatarLab.php` if added in Task 4.

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/Admin/AvatarLab.php app/Jobs/TransferAvatarMorphs.php resources/views/livewire/admin/avatar-lab.blade.php
git commit -m "feat: poll avatar morph_status every 3s while processing, stop when ready"
```

---

### Task 8: Smoke test end-to-end

No automated test for this flow (it requires Blender). Manual verification checklist:

- [ ] **Step 1: Start the queue worker**

```bash
cd /Users/bartslot/BartsAutomation/BartsDev/apps/thelearningportal.us
php artisan queue:work --timeout=660
```

- [ ] **Step 2: Open AvatarLab in the browser**

Navigate to the Avatars section. Confirm the "+" card appears at the end of the avatar grid.

- [ ] **Step 3: Upload a GLB**

Click the "+" card, select a GLB file (e.g. copy avatar 3's `character.glb` to test). Confirm:
- A new card appears immediately with "Processing" overlay and spinner
- The modal opens with Info and Voice tabs
- Filling in Name activates the Save button

- [ ] **Step 4: Fill in metadata**

- Enter a name (e.g. "Test Avatar")
- Set gender to Male
- Set age to 25
- Switch to Voice tab, confirm only male voices appear
- Click a voice card to select it
- Click "Save & Close"

- [ ] **Step 5: Watch the card update**

After the queue worker finishes Blender (~2-5 min), confirm:
- "Processing" overlay disappears from the card
- Card is now selectable and loads the GLB in the viewport
- Console shows `[Avatar3D] X mesh(es), Y/55 Azure shapes, 15/15 Oculus visemes mapped`

- [ ] **Step 6: Commit any fixes found during testing**

```bash
git add -p
git commit -m "fix: avatar upload smoke test fixes"
```
