# Plan A — Backend Foundation: migrations, models, legacy data migration

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `scenes` and `lesson_sources` tables, new columns on `lessons`, new `LessonStatus` enum values, and a one-shot migration that converts `slideshow_images` + `intel_drop_*` data into Scene rows.

**Architecture:** Pure Laravel migration + Eloquent work. No new services or jobs. Models follow existing patterns (`strict_types=1`, `protected $fillable`, `casts()` method, soft deletes where appropriate). All foreign keys use `->constrained()->cascadeOnDelete()`. Tests run on Pest + SQLite in-memory (`composer test`).

**Tech Stack:** Laravel 12, Eloquent, Pest 3.

**Spec:** `docs/superpowers/specs/2026-05-16-lesson-creation-rebuild-design.md` (§4 Data model).

---

## File Structure

```
database/migrations/
  2026_05_17_000001_create_scenes_table.php                                 NEW
  2026_05_17_000002_create_lesson_sources_table.php                          NEW
  2026_05_17_000003_add_wizard_columns_to_lessons_table.php                  NEW
  2026_05_17_000004_migrate_slideshow_and_intel_drop_to_scenes.php           NEW (data migration)
app/Models/
  Scene.php                                                                  NEW
  LessonSource.php                                                           NEW
  Lesson.php                                                                 MODIFY (relations, fillable, casts)
app/Enums/
  LessonStatus.php                                                           MODIFY (add 7 cases)
tests/Unit/
  SceneModelTest.php                                                         NEW
  LessonSourceModelTest.php                                                  NEW
tests/Feature/
  SlideshowToScenesMigrationTest.php                                         NEW
```

---

## Task 1: Add new LessonStatus cases

**Files:**
- Modify: `app/Enums/LessonStatus.php`
- Test: `tests/Unit/Enums/LessonStatusTest.php` (create if missing)

- [ ] **Step 1: Read current enum to confirm shape**

```bash
cat app/Enums/LessonStatus.php
```
Expected: a PHP enum with cases like `Pending`, `Generating`, `Ready`, `Published`, `Failed`.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Enums/LessonStatusTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\LessonStatus;

it('exposes the wizard states alongside legacy states', function () {
    $names = array_map(fn (LessonStatus $c) => $c->value, LessonStatus::cases());

    expect($names)->toContain(
        'pending', 'generating', 'ready', 'published', 'failed',
        'draft', 'source_ready', 'outlining', 'scenes_generating',
        'scenes_ready', 'configuring', 'previewable',
    );
});
```

- [ ] **Step 3: Run the test, confirm it fails**

```bash
./vendor/bin/pest tests/Unit/Enums/LessonStatusTest.php
```
Expected: FAIL — missing cases.

- [ ] **Step 4: Add the cases**

Open `app/Enums/LessonStatus.php` and add (preserving existing cases):

```php
case Draft             = 'draft';
case SourceReady       = 'source_ready';
case Outlining         = 'outlining';
case ScenesGenerating  = 'scenes_generating';
case ScenesReady       = 'scenes_ready';
case Configuring       = 'configuring';
case Previewable       = 'previewable';
```

- [ ] **Step 5: Run the test, confirm pass**

```bash
./vendor/bin/pest tests/Unit/Enums/LessonStatusTest.php
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/LessonStatus.php tests/Unit/Enums/LessonStatusTest.php
git commit -m "feat(lesson): add wizard LessonStatus cases"
```

---

## Task 2: Create `scenes` table migration

**Files:**
- Create: `database/migrations/2026_05_17_000001_create_scenes_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->enum('kind', ['narration', 'game'])->default('narration');

            $table->string('year')->nullable();
            $table->string('location')->nullable();
            $table->text('script_segment')->nullable();

            $table->text('image_prompt')->nullable();
            $table->string('image_path')->nullable();
            $table->enum('image_style', [
                'realistic', 'sketched', 'painted', 'cinematic', 'comic', 'animation',
            ])->nullable();

            $table->foreignId('animation_clip_id')
                ->nullable()
                ->constrained('animation_clips')
                ->nullOnDelete();

            $table->string('audio_path')->nullable();
            $table->json('audio_alignment')->nullable();
            $table->string('audio_script_hash', 64)->nullable();

            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedTinyInteger('game_segment_index')->nullable();

            $table->enum('status', ['pending', 'generating', 'ready', 'failed'])
                ->default('pending');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['lesson_id', 'order']);
            $table->index(['lesson_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```
Expected: `Migrated: 2026_05_17_000001_create_scenes_table`.

- [ ] **Step 3: Verify schema in tinker**

```bash
php artisan tinker --execute="dump(Schema::getColumnListing('scenes'));"
```
Expected: array containing all listed columns.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_05_17_000001_create_scenes_table.php
git commit -m "feat(scenes): create scenes table"
```

---

## Task 3: Create `lesson_sources` table migration

**Files:**
- Create: `database/migrations/2026_05_17_000002_create_lesson_sources_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->enum('kind', ['pdf', 'docx', 'wikipedia', 'both']);
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->longText('extracted_text');
            $table->string('wikipedia_topic')->nullable();
            $table->timestamps();

            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sources');
    }
};
```

- [ ] **Step 2: Run migration + verify**

```bash
php artisan migrate
php artisan tinker --execute="dump(Schema::getColumnListing('lesson_sources'));"
```
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_17_000002_create_lesson_sources_table.php
git commit -m "feat(scenes): create lesson_sources table"
```

---

## Task 4: Add wizard columns to `lessons` table

**Files:**
- Create: `database/migrations/2026_05_17_000003_add_wizard_columns_to_lessons_table.php`

- [ ] **Step 1: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->enum('image_style', [
                'realistic', 'sketched', 'painted', 'cinematic', 'comic', 'animation',
            ])->default('realistic')->after('details');

            $table->enum('source_mode', ['wikipedia', 'upload', 'both'])
                ->default('wikipedia')
                ->after('image_style');

            $table->unsignedTinyInteger('team_count')->nullable()->after('strategy_game_id');
            $table->unsignedTinyInteger('game_split_count')->default(1)->after('team_count');

            $table->json('outline')->nullable()->after('script');
            $table->unsignedTinyInteger('wizard_step')->default(1)->after('outline');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn([
                'image_style', 'source_mode', 'team_count',
                'game_split_count', 'outline', 'wizard_step',
            ]);
        });
    }
};
```

- [ ] **Step 2: Run migration + verify**

```bash
php artisan migrate
php artisan tinker --execute="dump(Schema::getColumnListing('lessons'));"
```
Expected: array contains the new columns.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_17_000003_add_wizard_columns_to_lessons_table.php
git commit -m "feat(lessons): add wizard columns"
```

---

## Task 5: Create `Scene` model

**Files:**
- Create: `app/Models/Scene.php`
- Test: `tests/Unit/SceneModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->lesson  = Lesson::create([
        'teacher_id'  => $this->teacher->id,
        'topic'       => 'Napoleonic Campaigns',
        'subject'     => 'history',
        'grade_level' => '9th grade',
    ]);
});

it('belongs to a lesson', function (): void {
    $scene = Scene::create([
        'lesson_id' => $this->lesson->id,
        'order'     => 1,
        'kind'      => 'narration',
    ]);

    expect($scene->lesson)->toBeInstanceOf(Lesson::class)
        ->and($scene->lesson->id)->toBe($this->lesson->id);
});

it('casts audio_alignment to array', function (): void {
    $scene = Scene::create([
        'lesson_id'       => $this->lesson->id,
        'order'           => 1,
        'kind'            => 'narration',
        'audio_alignment' => ['characters' => ['H', 'i']],
    ]);

    expect($scene->fresh()->audio_alignment)->toBeArray()
        ->and($scene->fresh()->audio_alignment['characters'])->toBe(['H', 'i']);
});

it('orders scenes by `order` via the scope', function (): void {
    Scene::create(['lesson_id' => $this->lesson->id, 'order' => 3, 'kind' => 'narration']);
    Scene::create(['lesson_id' => $this->lesson->id, 'order' => 1, 'kind' => 'narration']);
    Scene::create(['lesson_id' => $this->lesson->id, 'order' => 2, 'kind' => 'game']);

    expect(Scene::ordered()->pluck('order')->all())->toBe([1, 2, 3]);
});
```

- [ ] **Step 2: Run and verify it fails**

```bash
./vendor/bin/pest tests/Unit/SceneModelTest.php
```
Expected: FAIL — `Scene` class not found.

- [ ] **Step 3: Implement the model**

Create `app/Models/Scene.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scene extends Model
{
    protected $fillable = [
        'lesson_id', 'order', 'kind',
        'year', 'location', 'script_segment',
        'image_prompt', 'image_path', 'image_style',
        'animation_clip_id',
        'audio_path', 'audio_alignment', 'audio_script_hash',
        'duration_seconds', 'game_segment_index',
        'status', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'order'              => 'integer',
            'audio_alignment'    => 'array',
            'duration_seconds'   => 'integer',
            'game_segment_index' => 'integer',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function animationClip(): BelongsTo
    {
        return $this->belongsTo(AnimationClip::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function hasFreshAudio(): bool
    {
        return $this->audio_path !== null
            && $this->audio_script_hash === sha1((string) $this->script_segment);
    }
}
```

- [ ] **Step 4: Run tests, confirm pass**

```bash
./vendor/bin/pest tests/Unit/SceneModelTest.php
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Scene.php tests/Unit/SceneModelTest.php
git commit -m "feat(scenes): add Scene model"
```

---

## Task 6: Create `LessonSource` model

**Files:**
- Create: `app/Models/LessonSource.php`
- Test: `tests/Unit/LessonSourceModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('belongs to a lesson and stores extracted text', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id'  => $teacher->id,
        'topic'       => 'Roman Empire',
        'subject'     => 'history',
        'grade_level' => '7th grade',
    ]);

    $source = LessonSource::create([
        'lesson_id'      => $lesson->id,
        'kind'           => 'pdf',
        'file_path'      => "lessons/{$lesson->id}/source.pdf",
        'extracted_text' => 'In 27 BC, Augustus founded the Roman Empire.',
    ]);

    expect($source->lesson)->toBeInstanceOf(Lesson::class)
        ->and($source->extracted_text)->toContain('Augustus');
});
```

- [ ] **Step 2: Run, confirm fail**

```bash
./vendor/bin/pest tests/Unit/LessonSourceModelTest.php
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

Create `app/Models/LessonSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonSource extends Model
{
    protected $fillable = [
        'lesson_id', 'kind', 'original_filename',
        'file_path', 'extracted_text', 'wikipedia_topic',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
```

- [ ] **Step 4: Pass + commit**

```bash
./vendor/bin/pest tests/Unit/LessonSourceModelTest.php
git add app/Models/LessonSource.php tests/Unit/LessonSourceModelTest.php
git commit -m "feat(scenes): add LessonSource model"
```

---

## Task 7: Wire relationships + fillable on `Lesson`

**Files:**
- Modify: `app/Models/Lesson.php`
- Test: `tests/Unit/LessonModelRelationsTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\LessonSource;
use App\Models\Scene;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('has scenes ordered by `order`', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id'  => $teacher->id,
        'topic'       => 'WWII',
        'subject'     => 'history',
        'grade_level' => '10th grade',
    ]);

    Scene::create(['lesson_id' => $lesson->id, 'order' => 2, 'kind' => 'narration']);
    Scene::create(['lesson_id' => $lesson->id, 'order' => 1, 'kind' => 'narration']);

    expect($lesson->scenes()->pluck('order')->all())->toBe([1, 2]);
});

it('has a lesson source', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id'  => $teacher->id,
        'topic'       => 'WWII',
        'subject'     => 'history',
        'grade_level' => '10th grade',
    ]);

    LessonSource::create([
        'lesson_id'      => $lesson->id,
        'kind'           => 'wikipedia',
        'extracted_text' => 'WWII source text',
        'wikipedia_topic' => 'World_War_II',
    ]);

    expect($lesson->source)->toBeInstanceOf(LessonSource::class);
});

it('accepts the new wizard fillable fields', function (): void {
    $teacher = User::factory()->create();
    $lesson  = Lesson::create([
        'teacher_id'       => $teacher->id,
        'topic'            => 'Test',
        'subject'          => 'history',
        'grade_level'      => '8th',
        'image_style'      => 'painted',
        'source_mode'      => 'both',
        'team_count'       => 3,
        'game_split_count' => 2,
        'wizard_step'      => 3,
        'outline'          => ['title' => 'X', 'scene_briefs' => []],
    ]);

    expect($lesson->image_style)->toBe('painted')
        ->and($lesson->source_mode)->toBe('both')
        ->and($lesson->team_count)->toBe(3)
        ->and($lesson->game_split_count)->toBe(2)
        ->and($lesson->wizard_step)->toBe(3)
        ->and($lesson->outline)->toBeArray();
});
```

- [ ] **Step 2: Run, confirm fail**

```bash
./vendor/bin/pest tests/Unit/LessonModelRelationsTest.php
```

- [ ] **Step 3: Add fillable entries, casts, and relationships to `Lesson.php`**

In `app/Models/Lesson.php`:

Append to `$fillable`:
```php
'image_style', 'source_mode',
'team_count', 'game_split_count',
'outline', 'wizard_step',
```

Append to the `casts()` return array:
```php
'outline'          => 'array',
'team_count'       => 'integer',
'game_split_count' => 'integer',
'wizard_step'      => 'integer',
```

Add relationships in the relationships region:
```php
public function scenes(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\App\Models\Scene::class)->orderBy('order');
}

public function source(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\LessonSource::class)->latestOfMany();
}
```

- [ ] **Step 4: Pass + commit**

```bash
./vendor/bin/pest tests/Unit/LessonModelRelationsTest.php
git add app/Models/Lesson.php tests/Unit/LessonModelRelationsTest.php
git commit -m "feat(lessons): wire scenes + source relations + wizard fillables"
```

---

## Task 8: Data migration — slideshow_images + intel_drop_* → scenes

**Files:**
- Create: `database/migrations/2026_05_17_000004_migrate_slideshow_and_intel_drop_to_scenes.php`
- Test: `tests/Feature/SlideshowToScenesMigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Lesson;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('converts slideshow_images into narration scenes with order preserved', function (): void {
    $teacher = User::factory()->create();

    // Seed a legacy lesson directly via DB (bypass fillable for legacy columns)
    $lessonId = DB::table('lessons')->insertGetId([
        'teacher_id'       => $teacher->id,
        'topic'            => 'Legacy',
        'subject'          => 'history',
        'grade_level'      => '9th',
        'slideshow_images' => json_encode([
            ['url' => '/img1.jpg', 'title' => 'Scene 1'],
            ['url' => '/img2.jpg', 'title' => 'Scene 2'],
        ]),
        'status'           => 'ready',
        'created_at'       => now(),
        'updated_at'       => now(),
    ]);

    // Re-run the data migration explicitly
    (new \Database\Migrations\MigrateSlideshowAndIntelDropToScenes())->backfill();

    $scenes = Scene::where('lesson_id', $lessonId)->orderBy('order')->get();

    expect($scenes)->toHaveCount(2)
        ->and($scenes[0]->kind)->toBe('narration')
        ->and($scenes[0]->image_path)->toBe('/img1.jpg')
        ->and($scenes[1]->order)->toBe(2);
});

it('expands intel_drop into a game-split of 2 with the intel script seeded', function (): void {
    $teacher = User::factory()->create();
    $lessonId = DB::table('lessons')->insertGetId([
        'teacher_id'             => $teacher->id,
        'topic'                  => 'Intel test',
        'subject'                => 'history',
        'grade_level'            => '9th',
        'intel_drop_enabled'     => 1,
        'intel_drop_at_minutes'  => 5,
        'intel_drop_script'      => 'New intel: enemy is regrouping.',
        'status'                 => 'ready',
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);

    (new \Database\Migrations\MigrateSlideshowAndIntelDropToScenes())->backfill();

    $lesson = Lesson::find($lessonId);
    expect($lesson->game_split_count)->toBe(2);

    $sceneScripts = Scene::where('lesson_id', $lessonId)->pluck('script_segment')->all();
    expect($sceneScripts)->toContain('New intel: enemy is regrouping.');
});
```

- [ ] **Step 2: Run, confirm fail**

```bash
./vendor/bin/pest tests/Feature/SlideshowToScenesMigrationTest.php
```
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the migration**

```php
<?php

declare(strict_types=1);

namespace Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MigrateSlideshowAndIntelDropToScenes extends Migration
{
    public function up(): void
    {
        $this->backfill();
    }

    public function down(): void
    {
        // Irreversible — Scene rows would need to be regenerated from source. No-op.
    }

    /**
     * Idempotent backfill so it can be unit-tested in isolation.
     */
    public function backfill(): void
    {
        DB::transaction(function (): void {
            $lessons = DB::table('lessons')
                ->whereNotNull('slideshow_images')
                ->orWhere('intel_drop_enabled', 1)
                ->get();

            foreach ($lessons as $lesson) {
                $alreadyHasScenes = DB::table('scenes')
                    ->where('lesson_id', $lesson->id)
                    ->exists();
                if ($alreadyHasScenes) {
                    continue;
                }

                $order = 1;

                $slideshow = json_decode((string) $lesson->slideshow_images, true) ?: [];
                foreach ($slideshow as $entry) {
                    DB::table('scenes')->insert([
                        'lesson_id'   => $lesson->id,
                        'order'       => $order++,
                        'kind'        => 'narration',
                        'image_path'  => $entry['url'] ?? null,
                        'image_style' => 'realistic',
                        'status'      => 'ready',
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }

                if (! empty($lesson->intel_drop_enabled)) {
                    DB::table('lessons')
                        ->where('id', $lesson->id)
                        ->update(['game_split_count' => 2]);

                    DB::table('scenes')->insert([
                        'lesson_id'      => $lesson->id,
                        'order'          => $order++,
                        'kind'           => 'narration',
                        'script_segment' => $lesson->intel_drop_script ?? '',
                        'status'         => 'ready',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }
            }
        });
    }
}

return new MigrateSlideshowAndIntelDropToScenes();
```

Save to `database/migrations/2026_05_17_000004_migrate_slideshow_and_intel_drop_to_scenes.php`.

- [ ] **Step 4: Run tests, confirm pass + run migration on dev DB**

```bash
./vendor/bin/pest tests/Feature/SlideshowToScenesMigrationTest.php
php artisan migrate
```
Expected: PASS + migration listed in `migrate:status`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_17_000004_migrate_slideshow_and_intel_drop_to_scenes.php \
        tests/Feature/SlideshowToScenesMigrationTest.php
git commit -m "feat(scenes): backfill legacy slideshow_images and intel_drop into Scenes"
```

---

## Task 9: Run the full test suite

- [ ] **Step 1:** `composer test`
- [ ] **Step 2:** confirm no regressions in pre-existing tests (the new columns / relations must not break `LessonShow`, `CreateLesson`, etc., even though those will be replaced later).
- [ ] **Step 3:** if any pre-existing test fails because it asserts on `slideshow_images` shape, leave it alone — Plan G/H will replace those views. Only fix failures caused by model casts/fillable typos.

Done.
