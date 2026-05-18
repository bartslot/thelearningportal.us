# Avatar Lab — Mixamo Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the broken CMU mocap animation system with a simple Mixamo FBX upload-per-category workflow and a redesigned 3-column Avatar Lab UI.

**Architecture:** RPM GLB character (body skeleton + ARKit blend shapes) + Mixamo FBX animation clips uploaded per category (idle / presenting / greeting). Three.js `FBXLoader` loads clips at runtime and applies them to the RPM skeleton — same bone names, zero retargeting. Facial animation (blend shapes) is unchanged.

**Tech Stack:** Laravel 12, Livewire 3, Alpine.js, Tailwind CSS v4, Three.js v0.183.2 (`FBXLoader` + `GLTFLoader`), Flickity

---

## File Map

### Delete
| Path | Reason |
|---|---|
| `public/avatars/animations/walking_talking/` | 55 CMU FBX + 8 partial GLBs |
| `public/avatars/animations/bone_maps/` | CMU→Rigify bone map |
| `app/Models/AnimationClip.php` | Replaced |
| `app/Services/FbxConversionService.php` | No conversion pipeline |
| `app/Jobs/ConvertAnimationClip.php` | No conversion pipeline |
| `scripts/strip-root-motion.mjs` | Mixamo In Place has no root motion |
| `database/migrations/2026_04_09_000001_create_animation_clips_table.php` | Replaced |
| `database/migrations/2026_04_09_000002_create_avatar_animation_controllers_table.php` | Replaced |
| `database/seeders/AnimationClipSeeder.php` | Replaced |
| `database/factories/AnimationClipFactory.php` | Replaced |
| `tests/Feature/AvatarLabMovementTest.php` | Replaced |
| `tests/Feature/ConvertAnimationClipJobTest.php` | Replaced |
| `tests/Unit/AnimationClipModelTest.php` | Replaced |
| `tests/Unit/AvatarAnimationControllerModelTest.php` | Replaced |
| `tests/Unit/FbxConversionServiceTest.php` | Replaced |
| `resources/views/livewire/admin/avatar-lab/movement-tab.blade.php` | Replaced by new layout |
| `resources/views/livewire/admin/avatar-lab/movement-right-panel.blade.php` | Replaced by new layout |
| `docs/superpowers/specs/2026-04-09-avatar-lab-movement-design.md` | Superseded |

### Create
| Path | Purpose |
|---|---|
| `database/migrations/2026_04_10_000001_create_animation_clips_table.php` | Simple new schema |
| `database/migrations/2026_04_10_000002_create_avatar_animation_controllers_table.php` | Recreate with same schema |
| `app/Models/AnimationClip.php` | New simple model |
| `database/factories/AnimationClipFactory.php` | New factory for tests |
| `resources/views/components/animation-icon.blade.php` | Stick figure SVG component |
| `tests/Unit/AnimationClipModelTest.php` | Model unit tests |
| `tests/Feature/AvatarLabUploadTest.php` | Upload feature tests |
| `tests/Feature/AvatarLabControllerTest.php` | Assign/remove feature tests |

### Modify
| Path | Change |
|---|---|
| `app/Models/AvatarAnimationController.php` | Simplify `defaultControllerData()` |
| `app/Livewire/Admin/AvatarLab.php` | Full rewrite |
| `resources/views/livewire/admin/avatar-lab.blade.php` | Full rewrite (3-column layout) |
| `resources/js/avatar-3d.js` | Add `FBXLoader` + `loadBodyAnimation()` |
| `resources/js/animation-controller.js` | Simplify: FBX clips, flat JSON, remove CMU slots |
| `app/Http/Controllers/Api/AnimationControllerApi.php` | Return FBX URLs from new schema |
| `database/seeders/DatabaseSeeder.php` | Remove `AnimationClipSeeder` call |

---

## Task 1: Roll back migrations and delete CMU system

**Files:** All files listed in the Delete section above.

- [ ] **Step 1: Roll back the two CMU migrations**

  The migration files still exist, so rollback can find them:
  ```bash
  cd /Users/bartslot/Projects/thelearningportal.us
  php artisan migrate:rollback --step=2
  ```
  Expected output:
  ```
  Rolling back: 2026_04_09_000002_create_avatar_animation_controllers_table
  Rolled back:  2026_04_09_000002_create_avatar_animation_controllers_table
  Rolling back: 2026_04_09_000001_create_animation_clips_table
  Rolled back:  2026_04_09_000001_create_animation_clips_table
  ```

- [ ] **Step 2: Delete CMU asset files**

  ```bash
  rm -rf public/avatars/animations/walking_talking
  rm -rf public/avatars/animations/bone_maps
  ```

- [ ] **Step 3: Delete PHP source files**

  ```bash
  rm app/Models/AnimationClip.php
  rm app/Services/FbxConversionService.php
  rm app/Jobs/ConvertAnimationClip.php
  rm scripts/strip-root-motion.mjs
  rm database/migrations/2026_04_09_000001_create_animation_clips_table.php
  rm database/migrations/2026_04_09_000002_create_avatar_animation_controllers_table.php
  rm database/seeders/AnimationClipSeeder.php
  rm database/factories/AnimationClipFactory.php
  ```

- [ ] **Step 4: Delete old test files**

  ```bash
  rm tests/Feature/AvatarLabMovementTest.php
  rm tests/Feature/ConvertAnimationClipJobTest.php
  rm tests/Unit/AnimationClipModelTest.php
  rm tests/Unit/AvatarAnimationControllerModelTest.php
  rm tests/Unit/FbxConversionServiceTest.php
  ```

- [ ] **Step 5: Delete old blade views**

  ```bash
  rm resources/views/livewire/admin/avatar-lab/movement-tab.blade.php
  rm resources/views/livewire/admin/avatar-lab/movement-right-panel.blade.php
  rm docs/superpowers/specs/2026-04-09-avatar-lab-movement-design.md
  ```

- [ ] **Step 6: Remove AnimationClipSeeder from DatabaseSeeder**

  Edit `database/seeders/DatabaseSeeder.php` — remove this block:
  ```php
  // ── Animation Clips ───────────────────────────────────────────────────────
  $this->call(AnimationClipSeeder::class);
  ```

- [ ] **Step 7: Verify no broken references remain**

  ```bash
  grep -r "AnimationClip\|FbxConversion\|ConvertAnimation\|cmu_to_rigify\|strip-root-motion\|AnimationClipSeeder" \
    app/ database/ resources/ tests/ --include="*.php" --include="*.js" --include="*.blade.php" -l
  ```
  Expected: no output (all references gone).

- [ ] **Step 8: Commit**

  ```bash
  git add -A
  git commit -m "chore: delete CMU mocap system — FBX pipeline, bone maps, tests, migrations"
  ```

---

## Task 2: New `animation_clips` table and `AnimationClip` model (TDD)

**Files:**
- Create: `database/migrations/2026_04_10_000001_create_animation_clips_table.php`
- Create: `app/Models/AnimationClip.php`
- Create: `database/factories/AnimationClipFactory.php`
- Create: `tests/Unit/AnimationClipModelTest.php`

- [ ] **Step 1: Write the failing test**

  Create `tests/Unit/AnimationClipModelTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  use App\Models\AnimationClip;

  test('fillable fields are set correctly', function () {
      $clip = new AnimationClip([
          'name'       => 'Idle Wave',
          'category'   => 'idle',
          'fbx_path'   => 'avatars/animations/idle/1.fbx',
          'sort_order' => 3,
      ]);

      expect($clip->name)->toBe('Idle Wave');
      expect($clip->category)->toBe('idle');
      expect($clip->fbx_path)->toBe('avatars/animations/idle/1.fbx');
      expect($clip->sort_order)->toBe(3);
  });

  test('fbxUrl returns the correct asset URL', function () {
      $clip = AnimationClip::factory()->create([
          'fbx_path' => 'avatars/animations/idle/42.fbx',
      ]);

      expect($clip->fbxUrl())->toContain('avatars/animations/idle/42.fbx');
  });

  test('category must be one of idle presenting greeting', function () {
      expect(fn () => AnimationClip::factory()->create(['category' => 'invalid']))
          ->toThrow(\Illuminate\Database\QueryException::class);
  });
  ```

- [ ] **Step 2: Run the test — verify it fails**

  ```bash
  php artisan test tests/Unit/AnimationClipModelTest.php
  ```
  Expected: FAIL — class `App\Models\AnimationClip` not found.

- [ ] **Step 3: Create the migration**

  Create `database/migrations/2026_04_10_000001_create_animation_clips_table.php`:
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
          Schema::create('animation_clips', function (Blueprint $table) {
              $table->id();
              $table->string('name');
              $table->enum('category', ['idle', 'presenting', 'greeting']);
              $table->string('fbx_path');
              $table->smallInteger('sort_order')->default(0);
              $table->timestamps();
          });
      }

      public function down(): void
      {
          Schema::dropIfExists('animation_clips');
      }
  };
  ```

- [ ] **Step 4: Create the model**

  Create `app/Models/AnimationClip.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Models;

  use Illuminate\Database\Eloquent\Factories\HasFactory;
  use Illuminate\Database\Eloquent\Model;

  class AnimationClip extends Model
  {
      use HasFactory;

      protected $fillable = ['name', 'category', 'fbx_path', 'sort_order'];

      /** Returns the public URL for this clip's FBX file. */
      public function fbxUrl(): string
      {
          return asset($this->fbx_path);
      }
  }
  ```

- [ ] **Step 5: Create the factory**

  Create `database/factories/AnimationClipFactory.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace Database\Factories;

  use Illuminate\Database\Eloquent\Factories\Factory;

  class AnimationClipFactory extends Factory
  {
      public function definition(): array
      {
          $category = $this->faker->randomElement(['idle', 'presenting', 'greeting']);

          return [
              'name'       => $this->faker->words(2, true),
              'category'   => $category,
              'fbx_path'   => "avatars/animations/{$category}/test.fbx",
              'sort_order' => 0,
          ];
      }

      public function idle(): static
      {
          return $this->state(['category' => 'idle']);
      }

      public function presenting(): static
      {
          return $this->state(['category' => 'presenting']);
      }

      public function greeting(): static
      {
          return $this->state(['category' => 'greeting']);
      }
  }
  ```

- [ ] **Step 6: Run migrations**

  ```bash
  php artisan migrate
  ```
  Expected:
  ```
  Running migrations.
  2026_04_10_000001_create_animation_clips_table ............... DONE
  ```

- [ ] **Step 7: Run tests — verify they pass**

  ```bash
  php artisan test tests/Unit/AnimationClipModelTest.php
  ```
  Expected: 3 tests PASS.

- [ ] **Step 8: Commit**

  ```bash
  git add database/migrations/2026_04_10_000001_create_animation_clips_table.php \
    app/Models/AnimationClip.php \
    database/factories/AnimationClipFactory.php \
    tests/Unit/AnimationClipModelTest.php
  git commit -m "feat: add AnimationClip model and migration (idle/presenting/greeting)"
  ```

---

## Task 3: Recreate `avatar_animation_controllers` table + update model

**Files:**
- Create: `database/migrations/2026_04_10_000002_create_avatar_animation_controllers_table.php`
- Modify: `app/Models/AvatarAnimationController.php`

- [ ] **Step 1: Write the failing test**

  Add to a new file `tests/Unit/AvatarAnimationControllerModelTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  use App\Models\AvatarAnimationController;

  test('defaultControllerData returns flat category arrays', function () {
      $data = AvatarAnimationController::defaultControllerData();

      expect($data)->toHaveKeys(['idle', 'presenting', 'greeting']);
      expect($data['idle'])->toBeArray()->toBeEmpty();
      expect($data['presenting'])->toBeArray()->toBeEmpty();
      expect($data['greeting'])->toBeArray()->toBeEmpty();
  });

  test('controller is cast to array', function () {
      $avatar = \App\Models\Avatar::factory()->create();

      $controller = AvatarAnimationController::create([
          'avatar_id'  => $avatar->id,
          'controller' => ['idle' => ['1', '2'], 'presenting' => [], 'greeting' => []],
      ]);

      expect($controller->fresh()->controller)->toBeArray();
      expect($controller->fresh()->controller['idle'])->toBe(['1', '2']);
  });
  ```

- [ ] **Step 2: Run the test — verify it fails**

  ```bash
  php artisan test tests/Unit/AvatarAnimationControllerModelTest.php
  ```
  Expected: FAIL — table `avatar_animation_controllers` doesn't exist.

- [ ] **Step 3: Create the migration**

  Create `database/migrations/2026_04_10_000002_create_avatar_animation_controllers_table.php`:
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
          Schema::create('avatar_animation_controllers', function (Blueprint $table) {
              $table->id();
              $table->foreignId('avatar_id')->constrained()->cascadeOnDelete();
              $table->json('controller');
              $table->timestamps();
              $table->unique('avatar_id');
          });
      }

      public function down(): void
      {
          Schema::dropIfExists('avatar_animation_controllers');
      }
  };
  ```

- [ ] **Step 4: Update `AvatarAnimationController::defaultControllerData()`**

  In `app/Models/AvatarAnimationController.php`, replace the entire `defaultControllerData()` method:
  ```php
  /** Default controller structure — flat arrays of clip IDs per category. */
  public static function defaultControllerData(): array
  {
      return [
          'idle'       => [],
          'presenting' => [],
          'greeting'   => [],
      ];
  }
  ```

  Also remove `booted()` static hook (the cascade-on-delete is now handled by `cascadeOnDelete()` in the migration FK). The final model:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Models;

  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  class AvatarAnimationController extends Model
  {
      protected $fillable = ['avatar_id', 'controller'];

      protected $casts = ['controller' => 'array'];

      public function avatar(): BelongsTo
      {
          return $this->belongsTo(Avatar::class);
      }

      public static function defaultControllerData(): array
      {
          return [
              'idle'       => [],
              'presenting' => [],
              'greeting'   => [],
          ];
      }
  }
  ```

- [ ] **Step 5: Run migration**

  ```bash
  php artisan migrate
  ```
  Expected:
  ```
  2026_04_10_000002_create_avatar_animation_controllers_table .... DONE
  ```

- [ ] **Step 6: Run tests — verify they pass**

  ```bash
  php artisan test tests/Unit/AvatarAnimationControllerModelTest.php
  ```
  Expected: 2 tests PASS.

- [ ] **Step 7: Commit**

  ```bash
  git add database/migrations/2026_04_10_000002_create_avatar_animation_controllers_table.php \
    app/Models/AvatarAnimationController.php \
    tests/Unit/AvatarAnimationControllerModelTest.php
  git commit -m "feat: recreate animation controllers table, simplify controller JSON schema"
  ```

---

## Task 4: Rewrite `AvatarLab` Livewire component

**Files:**
- Modify: `app/Livewire/Admin/AvatarLab.php`

- [ ] **Step 1: Rewrite the component**

  Replace the entire `app/Livewire/Admin/AvatarLab.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Livewire\Admin;

  use App\Models\AnimationClip;
  use App\Models\Avatar;
  use App\Models\AvatarAnimationController;
  use Illuminate\Support\Collection;
  use Illuminate\View\View;
  use Livewire\Component;
  use Livewire\WithFileUploads;

  class AvatarLab extends Component
  {
      use WithFileUploads;

      // Sidebar state
      public ?int   $selectedAvatarId = null;
      public string $activeSection    = 'animation-groups'; // 'animation-groups' | 'controller'

      // Viewport state
      public ?int    $previewClipId   = null;
      public ?string $previewClipName = null;

      // Settings (under Settings sidebar section)
      public string $gender = 'male';
      public int    $age    = 35;
      public string $name   = '';

      // Audio section
      public string $testScript = '';

      // Per-category file upload properties
      public $idleFile;
      public $presentingFile;
      public $greetingFile;

      public Collection $avatars;

      public function mount(): void
      {
          $this->avatars = Avatar::where('is_active', true)->orderBy('name')->get(['id', 'name']);

          if ($this->avatars->count() === 1) {
              $this->selectAvatar($this->avatars->first()->id);
          }
      }

      public function selectAvatar(int|string $id): void
      {
          $avatar = Avatar::findOrFail((int) $id);

          $this->selectedAvatarId = $avatar->id;
          $this->gender           = $avatar->gender           ?? 'male';
          $this->age              = $avatar->age              ?? 35;
          $this->name             = $avatar->name;
          $this->previewClipId    = null;
          $this->previewClipName  = null;
      }

      // ── File upload lifecycle hooks ────────────────────────────────────────

      public function updatedIdleFile(): void
      {
          $this->processUpload('idle', $this->idleFile);
      }

      public function updatedPresentingFile(): void
      {
          $this->processUpload('presenting', $this->presentingFile);
      }

      public function updatedGreetingFile(): void
      {
          $this->processUpload('greeting', $this->greetingFile);
      }

      private function processUpload(string $category, mixed $file): void
      {
          if (! $file) {
              return;
          }

          if (strtolower($file->getClientOriginalExtension()) !== 'fbx') {
              session()->flash('error', 'Only .fbx files are allowed.');
              return;
          }

          if ($file->getSize() > 20 * 1024 * 1024) {
              session()->flash('error', 'File size must be under 20 MB.');
              return;
          }

          $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
          $clip = AnimationClip::create([
              'name'       => $name,
              'category'   => $category,
              'fbx_path'   => '',
              'sort_order' => AnimationClip::where('category', $category)->max('sort_order') + 1,
          ]);

          $dir = public_path("avatars/animations/{$category}");
          if (! is_dir($dir)) {
              mkdir($dir, 0755, true);
          }

          $file->move($dir, "{$clip->id}.fbx");
          $clip->update(['fbx_path' => "avatars/animations/{$category}/{$clip->id}.fbx"]);

          $this->dispatch('clip-uploaded', category: $category, clipId: $clip->id);
      }

      // ── Clip actions ──────────────────────────────────────────────────────

      public function deleteClip(int $id): void
      {
          $clip = AnimationClip::findOrFail($id);

          // Remove from all controllers
          AvatarAnimationController::all()->each(function (AvatarAnimationController $ctrl) use ($clip): void {
              $data = $ctrl->controller;
              foreach (['idle', 'presenting', 'greeting'] as $cat) {
                  if (isset($data[$cat])) {
                      $data[$cat] = array_values(
                          array_filter($data[$cat], fn ($cid) => $cid !== (string) $clip->id)
                      );
                  }
              }
              $ctrl->update(['controller' => $data]);
          });

          $abs = public_path($clip->fbx_path);
          if (file_exists($abs)) {
              unlink($abs);
          }

          if ($this->previewClipId === $id) {
              $this->previewClipId  = null;
              $this->previewClipName = null;
          }

          $clip->delete();
      }

      public function reorder(string $category, array $orderedIds): void
      {
          foreach ($orderedIds as $index => $id) {
              AnimationClip::where('id', (int) $id)
                  ->where('category', $category)
                  ->update(['sort_order' => $index]);
          }
      }

      public function loadPreview(int $clipId): void
      {
          $clip = AnimationClip::findOrFail($clipId);

          $this->previewClipId   = $clipId;
          $this->previewClipName = $clip->name;

          $this->dispatch('preview-clip',
              clipId:   $clipId,
              clipName: $clip->name,
              category: $clip->category,
              fbxUrl:   $clip->fbxUrl(),
          );
      }

      public function assignToController(int $avatarId, string $category, int $clipId): void
      {
          $controller = AvatarAnimationController::firstOrCreate(
              ['avatar_id' => $avatarId],
              ['controller' => AvatarAnimationController::defaultControllerData()],
          );

          $data = $controller->controller;

          if (! isset($data[$category])) {
              $data[$category] = [];
          }

          if (! in_array((string) $clipId, $data[$category], true)) {
              $data[$category][] = (string) $clipId;
          }

          $controller->update(['controller' => $data]);
          $this->dispatch('clip-assigned', clipId: $clipId, category: $category);
      }

      public function removeFromController(int $avatarId, string $category, int $clipId): void
      {
          $controller = AvatarAnimationController::where('avatar_id', $avatarId)->first();
          if (! $controller) {
              return;
          }

          $data = $controller->controller;

          if (isset($data[$category])) {
              $data[$category] = array_values(
                  array_filter($data[$category], fn ($id) => $id !== (string) $clipId)
              );
          }

          $controller->update(['controller' => $data]);
          $this->dispatch('clip-removed', clipId: $clipId, category: $category);
      }

      public function useClip(): void
      {
          if (! $this->previewClipId || ! $this->selectedAvatarId) {
              return;
          }

          $clip = AnimationClip::findOrFail($this->previewClipId);
          $this->assignToController($this->selectedAvatarId, $clip->category, $this->previewClipId);
      }

      // ── Render ────────────────────────────────────────────────────────────

      public function render(): View
      {
          $clipsByCategory = AnimationClip::orderBy('sort_order')->orderBy('name')
              ->get()
              ->groupBy('category');

          $controller = $this->selectedAvatarId
              ? AvatarAnimationController::where('avatar_id', $this->selectedAvatarId)->first()
              : null;

          $controllerData = $controller?->controller ?? AvatarAnimationController::defaultControllerData();

          $assignedClipIds = collect($controllerData)
              ->flatten()
              ->map(fn ($id) => (int) $id)
              ->unique()
              ->values();

          return view('livewire.admin.avatar-lab', compact(
              'clipsByCategory', 'controllerData', 'assignedClipIds',
          ))->layout('components.layouts.app', ['title' => 'Avatar Lab']);
      }
  }
  ```

- [ ] **Step 2: Verify the app boots without errors**

  ```bash
  php artisan route:list | grep avatar-lab
  ```
  Expected: shows `GET admin/avatar-lab` route.

- [ ] **Step 3: Commit**

  ```bash
  git add app/Livewire/Admin/AvatarLab.php
  git commit -m "feat: rewrite AvatarLab Livewire component — upload/delete/preview/assign"
  ```

---

## Task 5: `animation-icon` Blade component (stick figures)

**Files:**
- Create: `resources/views/components/animation-icon.blade.php`

- [ ] **Step 1: Create the component**

  Create `resources/views/components/animation-icon.blade.php`:
  ```blade
  @props(['type' => 'standing'])

  @php
  $svgs = [
      'standing' => '
          <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
          <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="28" x2="12" y2="38" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="28" x2="38" y2="38" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
      ',
      'walking' => '
          <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
          <line x1="25" y1="17" x2="23" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="23" y1="28" x2="10" y2="22" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="23" y1="28" x2="37" y2="35" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="23" y1="46" x2="13" y2="65" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="23" y1="46" x2="35" y2="62" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
      ',
      'pointing' => '
          <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
          <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="28" x2="12" y2="36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="24" x2="44" y2="17" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
      ',
      'explaining' => '
          <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
          <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="27" x2="10" y2="32" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="27" x2="40" y2="32" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
      ',
      'waving' => '
          <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
          <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="28" x2="12" y2="36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="24" x2="40" y2="12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
      ',
      'waving-expressive' => '
          <circle cx="25" cy="10" r="7" stroke="currentColor" stroke-width="3"/>
          <line x1="25" y1="17" x2="25" y2="46" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="28" x2="12" y2="36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="24" x2="40" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="40" y1="13" x2="47" y2="5"  stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="17" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
          <line x1="25" y1="46" x2="33" y2="68" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
      ',
  ];
  $inner = $svgs[$type] ?? $svgs['standing'];
  @endphp

  <svg {{ $attributes->merge(['viewBox' => '0 0 50 80', 'fill' => 'none', 'xmlns' => 'http://www.w3.org/2000/svg']) }}>
      {!! $inner !!}
  </svg>
  ```

- [ ] **Step 2: Commit**

  ```bash
  git add resources/views/components/animation-icon.blade.php
  git commit -m "feat: add animation-icon Blade component (stick figure SVGs)"
  ```

---

## Task 6: Rewrite `avatar-lab.blade.php` (3-column layout)

**Files:**
- Modify: `resources/views/livewire/admin/avatar-lab.blade.php`

- [ ] **Step 1: Replace the blade view**

  Replace the entire contents of `resources/views/livewire/admin/avatar-lab.blade.php`:

  ```blade
  <div
      class="flex overflow-hidden"
      style="height: calc(100vh - 64px)"
      x-data="{
          openSection: 'animation',
          flickities: {},
          initFlickity() {
              ['idle','presenting','greeting'].forEach(cat => {
                  this.flickities[cat]?.destroy();
                  const el = this.$el.querySelector('[data-flickity-cat=' + cat + ']');
                  if (el && el.children.length) {
                      this.flickities[cat] = new Flickity(el, {
                          freeScroll: true,
                          contain: true,
                          prevNextButtons: false,
                          pageDots: false,
                          cellAlign: 'left',
                      });
                  }
              });
          }
      }"
      x-init="
          $nextTick(() => initFlickity());
          $wire.on('clip-uploaded', () => $nextTick(() => initFlickity()));
      "
  >

      {{-- ── LEFT SIDEBAR ──────────────────────────────────────────────────── --}}
      <aside class="w-[220px] shrink-0 border-r border-slate-700/50 flex flex-col overflow-y-auto bg-slate-900/50">

          {{-- Avatar selector --}}
          <div class="p-4 border-b border-slate-700/50">
              <p class="text-[10px] uppercase tracking-widest text-slate-500 mb-2">Avatar</p>
              <select
                  wire:change="selectAvatar($event.target.value)"
                  class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-sm rounded-lg px-3 py-2 focus:outline-none focus:border-indigo-500"
              >
                  <option value="">— select —</option>
                  @foreach($avatars as $avatar)
                      <option value="{{ $avatar->id }}" @selected($selectedAvatarId === $avatar->id)>
                          {{ $avatar->name }}
                      </option>
                  @endforeach
              </select>
          </div>

          {{-- Navigation sections --}}
          <nav class="flex-1 p-3 flex flex-col gap-1 text-sm">

              {{-- Animation --}}
              <div>
                  <button
                      @click="openSection = openSection === 'animation' ? null : 'animation'"
                      class="w-full flex items-center justify-between px-2 py-2 rounded-lg text-slate-200 font-medium hover:bg-slate-800/50 transition-colors"
                  >
                      Animation
                      <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openSection === 'animation' ? 'rotate-90' : ''" viewBox="0 0 6 10" fill="currentColor"><path d="M1 1l4 4-4 4"/></svg>
                  </button>
                  <div x-show="openSection === 'animation'" class="ml-3 mt-1 flex flex-col gap-0.5">
                      <button
                          wire:click="$set('activeSection', 'animation-groups')"
                          class="text-left px-2 py-1.5 rounded-md text-xs transition-colors {{ $activeSection === 'animation-groups' ? 'text-indigo-400 bg-indigo-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' }}"
                      >Animation Groups</button>
                      <button
                          wire:click="$set('activeSection', 'controller')"
                          class="text-left px-2 py-1.5 rounded-md text-xs transition-colors {{ $activeSection === 'controller' ? 'text-indigo-400 bg-indigo-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50' }}"
                      >Controller</button>
                  </div>
              </div>

              {{-- Narration & Audio --}}
              <div>
                  <button
                      @click="openSection = openSection === 'narration' ? null : 'narration'"
                      class="w-full flex items-center justify-between px-2 py-2 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800/50 transition-colors"
                  >
                      Narration &amp; Audio
                      <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openSection === 'narration' ? 'rotate-90' : ''" viewBox="0 0 6 10" fill="currentColor"><path d="M1 1l4 4-4 4"/></svg>
                  </button>
              </div>

              {{-- Audio --}}
              <div>
                  <button
                      @click="openSection = openSection === 'audio' ? null : 'audio'"
                      class="w-full flex items-center justify-between px-2 py-2 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800/50 transition-colors"
                  >
                      Audio
                      <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openSection === 'audio' ? 'rotate-90' : ''" viewBox="0 0 6 10" fill="currentColor"><path d="M1 1l4 4-4 4"/></svg>
                  </button>
                  <div x-show="openSection === 'audio'" class="px-2 pt-2 pb-1">
                      <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-1 block">Test Script</label>
                      <textarea
                          wire:model="testScript"
                          rows="4"
                          placeholder="Friends, Romans..."
                          class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-lg px-3 py-2 resize-none focus:outline-none focus:border-indigo-500"
                      ></textarea>
                  </div>
              </div>

              {{-- Settings --}}
              <div>
                  <button
                      @click="openSection = openSection === 'settings' ? null : 'settings'"
                      class="w-full flex items-center justify-between px-2 py-2 rounded-lg text-slate-400 hover:text-slate-200 hover:bg-slate-800/50 transition-colors"
                  >
                      Settings
                      <svg class="w-3 h-3 text-slate-500 transition-transform" :class="openSection === 'settings' ? 'rotate-90' : ''" viewBox="0 0 6 10" fill="currentColor"><path d="M1 1l4 4-4 4"/></svg>
                  </button>
                  <div x-show="openSection === 'settings'" class="px-2 pt-2 pb-1 flex flex-col gap-4">

                      {{-- Name --}}
                      <div>
                          <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-1 block">Name</label>
                          <input
                              type="text"
                              wire:model="name"
                              class="w-full bg-slate-800 border border-slate-700 text-slate-200 text-xs rounded-lg px-3 py-2 focus:outline-none focus:border-indigo-500"
                          />
                      </div>

                      {{-- Gender --}}
                      <div>
                          <label class="text-[10px] uppercase tracking-widest text-slate-500 mb-2 block">Gender</label>
                          <div class="flex gap-2">
                              <button
                                  wire:click="$set('gender', 'male')"
                                  class="flex-1 py-1.5 rounded-lg text-xs font-medium transition-colors {{ $gender === 'male' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:border-slate-500' }}"
                              >♂ Male</button>
                              <button
                                  wire:click="$set('gender', 'female')"
                                  class="flex-1 py-1.5 rounded-lg text-xs font-medium transition-colors {{ $gender === 'female' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 border border-slate-700 hover:border-slate-500' }}"
                              >♀ Female</button>
                          </div>
                      </div>

                      {{-- Age --}}
                      <div>
                          <div class="flex items-center justify-between mb-2">
                              <label class="text-[10px] uppercase tracking-widest text-slate-500">Age</label>
                              <span class="text-indigo-400 text-xs font-semibold">{{ $age }}</span>
                          </div>
                          <input
                              type="range"
                              wire:model.live="age"
                              min="8"
                              max="80"
                              class="w-full accent-indigo-500"
                          />
                          <div class="flex justify-between text-[10px] text-slate-600 mt-1">
                              <span>Child</span><span>Teen</span><span>Adult</span><span>Elder</span>
                          </div>
                      </div>

                  </div>
              </div>

          </nav>
      </aside>

      {{-- ── MIDDLE PANEL ──────────────────────────────────────────────────── --}}
      <div class="w-[420px] shrink-0 border-r border-slate-700/50 overflow-y-auto p-6 bg-slate-900/30">

          @if($activeSection === 'animation-groups')

              <h2 class="text-lg font-semibold text-slate-100 mb-6">Animation</h2>

              @foreach(['idle' => 'Idle animations', 'presenting' => 'Presenting', 'greeting' => 'Greeting'] as $cat => $label)
                  <div class="mb-8">
                      <div class="flex items-center justify-between mb-3">
                          <h3 class="text-sm font-semibold text-slate-200">{{ $label }}</h3>

                          <label class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-teal-700/30 border border-teal-600/30 text-teal-300 text-xs font-medium hover:bg-teal-700/50 transition-colors">
                              Add +
                              <input
                                  type="file"
                                  wire:model="{{ $cat }}File"
                                  accept=".fbx"
                                  class="hidden"
                              />
                          </label>
                      </div>

                      @php $clips = $clipsByCategory->get($cat, collect()) @endphp

                      @if($clips->isEmpty())
                          <p class="text-slate-600 text-xs py-4 italic">No clips yet — upload a Mixamo FBX with "In Place" checked.</p>
                      @else
                          <div data-flickity-cat="{{ $cat }}" class="flex gap-3 overflow-hidden">
                              @foreach($clips as $clip)
                                  @php
                                      $icons = [
                                          'idle'       => 'standing',
                                          'presenting' => 'walking',
                                          'greeting'   => 'waving',
                                      ];
                                      $icon = $icons[$cat] ?? 'standing';
                                  @endphp
                                  <div class="shrink-0">
                                      <button
                                          wire:click="loadPreview({{ $clip->id }})"
                                          class="w-36 rounded-xl pt-3 px-3 pb-0 flex flex-col items-center transition-all border
                                              {{ $previewClipId === $clip->id
                                                  ? 'border-amber-400 bg-slate-700/80 shadow-lg shadow-amber-500/10'
                                                  : 'border-slate-700 bg-slate-800/80 hover:border-indigo-500/50 hover:bg-slate-800' }}"
                                      >
                                          <div class="w-full flex justify-end mb-1">
                                              <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0
                                                  {{ $assignedClipIds->contains($clip->id)
                                                      ? 'bg-indigo-500 border-indigo-400 text-white'
                                                      : 'border-slate-500 bg-transparent' }}">
                                                  @if($assignedClipIds->contains($clip->id))
                                                      <svg class="w-2.5 h-2.5" viewBox="0 0 10 8" fill="none"><path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                  @endif
                                              </div>
                                          </div>

                                          <x-animation-icon :type="$icon" class="w-16 h-16 text-indigo-400" />

                                          <span class="text-xs text-slate-300 text-center leading-tight mt-2 pb-3 line-clamp-2">
                                              {{ $clip->name }}
                                          </span>
                                      </button>
                                  </div>
                              @endforeach
                          </div>
                      @endif
                  </div>
              @endforeach

          @elseif($activeSection === 'controller')

              <h2 class="text-lg font-semibold text-slate-100 mb-6">Controller</h2>

              @if(! $selectedAvatarId)
                  <p class="text-slate-500 text-sm">Select an avatar to view its controller.</p>
              @else
                  @foreach(['idle' => 'Idle', 'presenting' => 'Presenting', 'greeting' => 'Greeting'] as $cat => $label)
                      <div class="mb-6 bg-slate-800/50 border border-slate-700/50 rounded-xl p-4">
                          <h3 class="text-sm font-semibold text-slate-200 mb-3">{{ $label }}</h3>

                          @php $assignedIds = $controllerData[$cat] ?? [] @endphp

                          @if(empty($assignedIds))
                              <p class="text-slate-600 text-xs italic">No clips assigned</p>
                          @else
                              <div class="flex flex-col gap-2">
                                  @foreach($assignedIds as $clipId)
                                      @php $clip = $clipsByCategory->flatten()->firstWhere('id', (int) $clipId) @endphp
                                      @if($clip)
                                          <div class="flex items-center justify-between bg-slate-900/50 rounded-lg px-3 py-2">
                                              <span class="text-xs text-slate-300">{{ $clip->name }}</span>
                                              <button
                                                  wire:click="removeFromController({{ $selectedAvatarId }}, '{{ $cat }}', {{ $clip->id }})"
                                                  class="text-slate-600 hover:text-red-400 text-xs transition-colors"
                                              >✕</button>
                                          </div>
                                      @endif
                                  @endforeach
                              </div>
                          @endif
                      </div>
                  @endforeach
              @endif

          @endif

      </div>

      {{-- ── RIGHT VIEWPORT ────────────────────────────────────────────────── --}}
      <div class="flex-1 relative overflow-hidden bg-slate-950">

          {{-- Top overlay: animation name + Use button --}}
          @if($previewClipId)
              <div class="absolute top-4 right-4 z-10 flex items-center gap-3">
                  <span class="text-slate-300 text-sm font-medium drop-shadow">{{ $previewClipName }}</span>
                  <button
                      wire:click="useClip"
                      class="flex items-center gap-2 px-4 py-2 bg-slate-800/90 border border-slate-600 rounded-xl text-sm text-slate-200 hover:border-indigo-500 transition-colors backdrop-blur-sm"
                  >
                      Use
                      <span class="w-5 h-5 bg-indigo-600 rounded-full flex items-center justify-center">
                          <svg class="w-2.5 h-2.5 text-white" viewBox="0 0 10 8" fill="none"><path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                      </span>
                  </button>
              </div>
          @endif

          @if(! $selectedAvatarId)
              <div class="absolute inset-0 flex items-center justify-center text-slate-600 text-sm">
                  ← Select an avatar to load the 3D viewport
              </div>
          @else
              <canvas
                  id="avatar-lab-canvas"
                  class="w-full h-full block"
                  x-init="
                      const canvas = $el;
                      const characterUrl = '/avatars/{{ $selectedAvatarId }}/character.glb';
                      if (window.Avatar3DPlayer) {
                          window._avatar3d?.destroy();
                          window._avatar3d = new Avatar3DPlayer(canvas, { characterUrl });
                          window._avatar3d.init().catch(console.error);
                      }
                  "
              ></canvas>
          @endif

      </div>

  </div>

  @vite('resources/js/avatar-3d.js')
  ```

- [ ] **Step 2: Verify the page loads without 500 errors**

  ```bash
  php artisan serve
  ```
  Open `http://localhost:8000/admin/avatar-lab` in a browser (logged in as admin).
  Expected: 3-column layout renders, no console errors.

- [ ] **Step 3: Commit**

  ```bash
  git add resources/views/livewire/admin/avatar-lab.blade.php
  git commit -m "feat: 3-column Avatar Lab layout — sidebar, animation groups, 3D viewport"
  ```

---

## Task 7: Add `FBXLoader` and body animation to `avatar-3d.js`

**Files:**
- Modify: `resources/js/avatar-3d.js`

- [ ] **Step 1: Add FBXLoader import**

  At the top of `resources/js/avatar-3d.js`, add one import after the existing imports:
  ```js
  import { FBXLoader }     from 'three/examples/jsm/loaders/FBXLoader.js'
  ```
  The import block should now read:
  ```js
  import * as THREE from 'three'
  import { GLTFLoader }    from 'three/examples/jsm/loaders/GLTFLoader.js'
  import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
  import { FBXLoader }     from 'three/examples/jsm/loaders/FBXLoader.js'
  ```

- [ ] **Step 2: Add `_fbxLoader` and `_bodyAction` in the constructor**

  Locate the constructor of `Avatar3DPlayer`. Add these two lines after the existing property initialisations (find any line like `this._animMixer = null` and add after it):
  ```js
  this._fbxLoader  = new FBXLoader()
  this._bodyAction = null   // current body animation action
  ```

- [ ] **Step 3: Add the `loadBodyAnimation` method**

  Add this method to the `Avatar3DPlayer` class, just before the `destroy()` method:
  ```js
  /**
   * Load a Mixamo FBX animation and apply it to the currently loaded RPM character.
   * The FBX must be downloaded from Mixamo with "In Place" checked (no root motion).
   *
   * @param {string} fbxUrl — public URL to the .fbx file
   */
  async loadBodyAnimation (fbxUrl) {
    if (!this._model || !this._animMixer) return

    const fbx  = await new Promise((res, rej) => this._fbxLoader.load(fbxUrl, res, undefined, rej))
    const clip = fbx.animations?.[0]
    if (!clip) { console.warn('[Avatar3D] FBX has no animation clips:', fbxUrl); return }

    // Fade out the previous body action
    if (this._bodyAction) {
      this._bodyAction.fadeOut(0.3)
    }

    // Apply to the RPM character — bone names match Mixamo rig exactly
    const action = this._animMixer.clipAction(clip, this._model)
    action.reset().fadeIn(0.3).play()
    this._bodyAction = action
  }
  ```

- [ ] **Step 4: Add the `preview-clip` event listener**

  At the bottom of `avatar-3d.js`, in the "Livewire event" section (near `avatar3d:previewReady`), add:
  ```js
  // ── Body animation preview (Avatar Lab) ──────────────────────────────────
  window.addEventListener('preview-clip', (ev) => {
    window._avatar3d?.loadBodyAnimation(ev.detail.fbxUrl)
      .catch(err => console.error('[Avatar3D] Failed to load FBX:', err))
  })
  ```

- [ ] **Step 5: Build and verify no compile errors**

  ```bash
  npm run build 2>&1 | tail -20
  ```
  Expected: build succeeds with no errors. FBXLoader will bundle `fflate` automatically (it's a transitive dep of three).

- [ ] **Step 6: Commit**

  ```bash
  git add resources/js/avatar-3d.js
  git commit -m "feat: add FBXLoader to Avatar3DPlayer — loadBodyAnimation from Mixamo FBX"
  ```

---

## Task 8: Simplify `animation-controller.js` + update `AnimationControllerApi`

**Files:**
- Modify: `resources/js/animation-controller.js`
- Modify: `app/Http/Controllers/Api/AnimationControllerApi.php`

- [ ] **Step 1: Rewrite `animation-controller.js`**

  Replace the entire file `resources/js/animation-controller.js`:
  ```js
  /**
   * animation-controller.js
   *
   * Manages avatar body animation using Three.js AnimationMixer.
   * Reads the controller JSON from /api/v1/avatars/{id}/controller:
   *   { "idle": ["1","2"], "presenting": ["3"], "greeting": ["4"] }
   *
   * Usage:
   *   const ac = new AnimationController(mixer, controllerJson);
   *   await ac.loadClips(clipUrls);  // { '1': '/path/1.fbx', ... }
   *   ac.trigger('presenting');       // start loop
   *   ac.trigger('greeting');         // play once, auto-return to presenting
   *   ac.update(deltaTime);           // call every frame
   */

  import * as THREE  from 'three'
  import { FBXLoader } from 'three/examples/jsm/loaders/FBXLoader.js'

  export class AnimationController {
    #mixer
    #clips        = {}    // { clip_id: THREE.AnimationClip }
    #controller          // { idle: ['1'], presenting: ['2'], greeting: ['3'] }
    #currentCat   = 'idle'
    #currentAction= null
    #lastClip     = {}    // { category: clip_id } — anti-repeat

    /**
     * @param {THREE.AnimationMixer} mixer
     * @param {object} controllerJson — flat { category: [clip_id, ...] }
     */
    constructor (mixer, controllerJson) {
      this.#mixer      = mixer
      this.#controller = controllerJson ?? {}
    }

    /**
     * Load all FBX animation files.
     * @param {Object.<string, string>} clipUrls — { clip_id: fbx_url }
     */
    async loadClips (clipUrls) {
      const loader = new FBXLoader()

      await Promise.all(
        Object.entries(clipUrls).map(async ([id, url]) => {
          try {
            const fbx = await loader.loadAsync(url)
            if (fbx.animations.length > 0) {
              this.#clips[id] = fbx.animations[0]
            } else {
              console.warn(`[AnimationController] No animations in FBX "${id}"`)
            }
          } catch (err) {
            console.warn(`[AnimationController] Failed to load clip "${id}":`, err.message)
          }
        })
      )
    }

    /**
     * Trigger a category. Loops for idle/presenting, plays once for greeting.
     * Gracefully no-ops if the category is empty or the clip failed to load.
     *
     * @param {string} category — 'idle' | 'presenting' | 'greeting'
     * @param {number} fadeIn   — crossfade duration in seconds
     */
    trigger (category, fadeIn = 0.3) {
      const pool = this.#controller[category] ?? []
      if (pool.length === 0) return

      const id   = this.#pick(category, pool)
      const clip = this.#clips[id]
      if (!clip) return

      const isLooping = category === 'idle' || category === 'presenting'
      const action    = this.#mixer.clipAction(clip)

      action.setLoop(isLooping ? THREE.LoopRepeat : THREE.LoopOnce, isLooping ? Infinity : 1)
      action.clampWhenFinished = !isLooping
      action.reset()

      if (this.#currentAction && this.#currentAction !== action) {
        this.#currentAction.crossFadeTo(action, fadeIn, false)
      }

      action.play()

      const prevCat       = this.#currentCat
      this.#currentAction = action
      this.#currentCat    = category

      // One-shot (greeting): auto-return to previous category when finished
      if (!isLooping) {
        const onFinished = (ev) => {
          if (ev.action !== action) return
          this.#mixer.removeEventListener('finished', onFinished)
          this.trigger(prevCat === category ? 'idle' : prevCat, 0.3)
        }
        this.#mixer.addEventListener('finished', onFinished)
      }
    }

    /** Crossfade back to idle. */
    stop (fadeOut = 0.3) {
      this.trigger('idle', fadeOut)
    }

    /** Call every frame with the delta time in seconds. */
    update (delta) {
      this.#mixer.update(delta)
    }

    // ── Private ─────────────────────────────────────────────────────────────

    #pick (category, pool) {
      if (pool.length === 1) { this.#lastClip[category] = pool[0]; return pool[0] }
      const last      = this.#lastClip[category]
      const available = pool.filter(id => id !== last)
      const source    = available.length > 0 ? available : pool
      const id        = source[Math.floor(Math.random() * source.length)]
      this.#lastClip[category] = id
      return id
    }
  }
  ```

- [ ] **Step 2: Update `AnimationControllerApi`**

  Replace the entire `app/Http/Controllers/Api/AnimationControllerApi.php`:
  ```php
  <?php

  declare(strict_types=1);

  namespace App\Http\Controllers\Api;

  use App\Models\AnimationClip;
  use App\Models\Avatar;
  use App\Models\AvatarAnimationController;
  use Illuminate\Http\JsonResponse;
  use Illuminate\Http\Request;

  class AnimationControllerApi
  {
      public function __invoke(Request $request, Avatar $avatar): JsonResponse
      {
          $record     = AvatarAnimationController::where('avatar_id', $avatar->id)->first();
          $controller = $record?->controller ?? AvatarAnimationController::defaultControllerData();

          // Collect all clip IDs referenced across all categories
          $allClipIds = collect($controller)
              ->flatten()
              ->map(fn ($id) => (int) $id)
              ->unique()
              ->values();

          // Build FBX URL map: { clip_id => public_url }
          $clipUrls = AnimationClip::whereIn('id', $allClipIds)
              ->get()
              ->mapWithKeys(fn ($clip) => [(string) $clip->id => $clip->fbxUrl()])
              ->all();

          return response()->json([
              'controller' => $controller,
              'clip_urls'  => $clipUrls,
          ]);
      }
  }
  ```

- [ ] **Step 3: Build and verify**

  ```bash
  npm run build 2>&1 | tail -10
  php artisan route:list | grep controller
  ```
  Expected: build succeeds, API route for controller is listed.

- [ ] **Step 4: Commit**

  ```bash
  git add resources/js/animation-controller.js \
    app/Http/Controllers/Api/AnimationControllerApi.php
  git commit -m "feat: simplify AnimationController to FBX + flat JSON, update API to return FBX URLs"
  ```

---

## Task 9: Feature tests

**Files:**
- Create: `tests/Feature/AvatarLabUploadTest.php`
- Create: `tests/Feature/AvatarLabControllerTest.php`

- [ ] **Step 1: Write upload + delete feature tests**

  Create `tests/Feature/AvatarLabUploadTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  use App\Livewire\Admin\AvatarLab;
  use App\Models\AnimationClip;
  use App\Models\User;
  use Illuminate\Http\UploadedFile;
  use Livewire\Livewire;

  beforeEach(function () {
      $this->admin = User::factory()->create(['role' => 'admin']);
  });

  test('admin can upload an idle FBX clip', function () {
      $file = UploadedFile::fake()->create('idle_wave.fbx', 512, 'application/octet-stream');

      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->set('idleFile', $file);

      expect(AnimationClip::where('category', 'idle')->where('name', 'idle_wave')->exists())->toBeTrue();
      $clip = AnimationClip::where('category', 'idle')->first();
      expect($clip->fbx_path)->toContain('avatars/animations/idle/');
  });

  test('admin can upload a presenting FBX clip', function () {
      $file = UploadedFile::fake()->create('walking_talk.fbx', 512, 'application/octet-stream');

      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->set('presentingFile', $file);

      expect(AnimationClip::where('category', 'presenting')->exists())->toBeTrue();
  });

  test('admin can upload a greeting FBX clip', function () {
      $file = UploadedFile::fake()->create('waving.fbx', 512, 'application/octet-stream');

      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->set('greetingFile', $file);

      expect(AnimationClip::where('category', 'greeting')->exists())->toBeTrue();
  });

  test('non-fbx files are rejected', function () {
      $file = UploadedFile::fake()->create('animation.glb', 512, 'application/octet-stream');

      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->set('idleFile', $file);

      expect(AnimationClip::count())->toBe(0);
  });

  test('admin can delete a clip', function () {
      $clip = AnimationClip::factory()->idle()->create();

      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->call('deleteClip', $clip->id);

      expect(AnimationClip::find($clip->id))->toBeNull();
  });

  test('deleting a clip removes it from all controllers', function () {
      $avatar = \App\Models\Avatar::factory()->create();
      $clip   = AnimationClip::factory()->idle()->create();

      $ctrl = \App\Models\AvatarAnimationController::create([
          'avatar_id'  => $avatar->id,
          'controller' => ['idle' => [(string) $clip->id], 'presenting' => [], 'greeting' => []],
      ]);

      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->call('deleteClip', $clip->id);

      expect($ctrl->fresh()->controller['idle'])->toBeEmpty();
  });
  ```

- [ ] **Step 2: Run upload tests — verify they fail**

  ```bash
  php artisan test tests/Feature/AvatarLabUploadTest.php
  ```
  Expected: Tests fail because Livewire file upload in tests needs Livewire's test disk. That's fine — this confirms they are wired up correctly. If they all pass immediately, also good.

  > Note: Livewire file uploads in `->set()` bypass the actual file upload mechanism in tests. If tests fail with a file-type error, use `Livewire::withQueryParams([])` or check that `WithFileUploads` is on the component.

- [ ] **Step 3: Write controller assign/remove tests**

  Create `tests/Feature/AvatarLabControllerTest.php`:
  ```php
  <?php

  declare(strict_types=1);

  use App\Livewire\Admin\AvatarLab;
  use App\Models\AnimationClip;
  use App\Models\Avatar;
  use App\Models\AvatarAnimationController;
  use App\Models\User;
  use Livewire\Livewire;

  beforeEach(function () {
      $this->admin  = User::factory()->create(['role' => 'admin']);
      $this->avatar = Avatar::factory()->create();
      $this->clip   = AnimationClip::factory()->idle()->create();
  });

  test('assign clip to controller creates controller record if none exists', function () {
      expect(AvatarAnimationController::where('avatar_id', $this->avatar->id)->exists())->toBeFalse();

      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->call('assignToController', $this->avatar->id, 'idle', $this->clip->id);

      $ctrl = AvatarAnimationController::where('avatar_id', $this->avatar->id)->first();
      expect($ctrl)->not->toBeNull();
      expect($ctrl->controller['idle'])->toContain((string) $this->clip->id);
  });

  test('assigning the same clip twice does not duplicate it', function () {
      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->call('assignToController', $this->avatar->id, 'idle', $this->clip->id)
          ->call('assignToController', $this->avatar->id, 'idle', $this->clip->id);

      $ctrl = AvatarAnimationController::where('avatar_id', $this->avatar->id)->first();
      expect(count($ctrl->controller['idle']))->toBe(1);
  });

  test('remove clip from controller removes it from the category array', function () {
      AvatarAnimationController::create([
          'avatar_id'  => $this->avatar->id,
          'controller' => ['idle' => [(string) $this->clip->id], 'presenting' => [], 'greeting' => []],
      ]);

      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->call('removeFromController', $this->avatar->id, 'idle', $this->clip->id);

      $ctrl = AvatarAnimationController::where('avatar_id', $this->avatar->id)->first();
      expect($ctrl->controller['idle'])->toBeEmpty();
  });

  test('useClip assigns previewed clip to its category', function () {
      Livewire::actingAs($this->admin)
          ->test(AvatarLab::class)
          ->set('selectedAvatarId', $this->avatar->id)
          ->set('previewClipId', $this->clip->id)
          ->call('useClip');

      $ctrl = AvatarAnimationController::where('avatar_id', $this->avatar->id)->first();
      expect($ctrl->controller['idle'])->toContain((string) $this->clip->id);
  });
  ```

- [ ] **Step 4: Run all tests**

  ```bash
  php artisan test tests/Feature/AvatarLabUploadTest.php tests/Feature/AvatarLabControllerTest.php
  ```
  Expected: all tests pass.

- [ ] **Step 5: Run the full test suite**

  ```bash
  php artisan test
  ```
  Expected: all tests pass, no regressions.

- [ ] **Step 6: Commit**

  ```bash
  git add tests/Feature/AvatarLabUploadTest.php tests/Feature/AvatarLabControllerTest.php
  git commit -m "test: Avatar Lab upload, delete, assign, remove controller tests"
  ```

---

## Task 10: Final cleanup and public directories

**Files:**
- Verify public animation directories exist

- [ ] **Step 1: Create required public directories**

  ```bash
  mkdir -p public/avatars/animations/idle
  mkdir -p public/avatars/animations/presenting
  mkdir -p public/avatars/animations/greeting
  touch public/avatars/animations/idle/.gitkeep
  touch public/avatars/animations/presenting/.gitkeep
  touch public/avatars/animations/greeting/.gitkeep
  ```

- [ ] **Step 2: Verify no stale references**

  ```bash
  grep -r "ConvertAnimationClip\|FbxConversionService\|AnimationClipSeeder\|cmu_to_rigify\|glb_path\|clip_id.*138" \
    app/ database/ resources/ tests/ --include="*.php" --include="*.js" --include="*.blade.php"
  ```
  Expected: no matches.

- [ ] **Step 3: Final build + test**

  ```bash
  npm run build && php artisan test
  ```
  Expected: build succeeds, all tests pass.

- [ ] **Step 4: Final commit**

  ```bash
  git add public/avatars/animations/idle/.gitkeep \
    public/avatars/animations/presenting/.gitkeep \
    public/avatars/animations/greeting/.gitkeep
  git commit -m "chore: create Mixamo FBX upload directories"
  ```

---

## Self-Review Checklist

- [x] **Spec coverage:** Deletion list ✓, new migration ✓, new model ✓, Livewire actions ✓, blade layout ✓, FBXLoader ✓, animation-controller ✓, API ✓, tests ✓
- [x] **No placeholders:** Every step has complete code
- [x] **Type consistency:** `clip.id` (int) stored as `(string) $clip->id` in controller JSON; `AnimationControllerApi` reads and maps back consistently; `assignedClipIds` uses `(int)` cast for comparison
- [x] **FBXLoader path:** Uses `three/examples/jsm/loaders/FBXLoader.js` — matching existing `GLTFLoader` and `OrbitControls` import pattern in avatar-3d.js
- [x] **Mixamo "In Place":** Noted in spec and blade placeholder text — admin must download with In Place checked
