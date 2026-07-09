<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Classroom;
use App\Models\ClassroomMember;
use App\Models\Lesson;
use App\Models\LessonTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonTeamTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param list<string> $memberNames */
    private function publishedLessonWithRoster(array $memberNames): array
    {
        $teacher   = User::factory()->teacher()->create();
        $classroom = Classroom::factory()->create(['teacher_id' => $teacher->id]);

        foreach ($memberNames as $name) {
            ClassroomMember::create([
                'classroom_id' => $classroom->id,
                'display_name' => $name,
            ]);
        }

        $lesson = Lesson::factory()->published()->create(['teacher_id' => $teacher->id]);
        $classroom->lessons()->attach($lesson->id, ['assigned_at' => now()]);

        return compact('lesson', 'classroom', 'teacher');
    }

    // ── POST /api/lesson/{code}/teams ─────────────────────────────────────

    public function test_teams_auto_form_from_classroom_members_roster(): void
    {
        $roster = ['Emma V.', 'Liam K.', 'Noah P.', 'Sofia B.', 'Mia D.', 'Lucas T.'];
        ['lesson' => $lesson] = $this->publishedLessonWithRoster($roster);

        $response = $this->postJson("/api/lesson/{$lesson->lesson_code}/teams", ['team_count' => 3])
            ->assertCreated()
            ->assertJsonCount(3, 'teams');

        $allNames = collect($response->json('teams'))
            ->flatMap(fn (array $team) => collect($team['members'])->pluck('name'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect($roster)->sort()->values()->all(), $allNames);
    }

    public function test_auto_formed_members_are_roster_entries_not_user_accounts(): void
    {
        ['lesson' => $lesson] = $this->publishedLessonWithRoster(['Emma V.', 'Liam K.']);

        $this->postJson("/api/lesson/{$lesson->lesson_code}/teams", ['team_count' => 2])
            ->assertCreated();

        $members = LessonTeam::where('lesson_id', $lesson->id)
            ->with('members')
            ->get()
            ->flatMap->members;

        $this->assertCount(2, $members);
        $this->assertTrue($members->every(fn ($m) => $m->student_id === null));
        $this->assertEqualsCanonicalizing(
            ['Emma V.', 'Liam K.'],
            $members->pluck('student_name')->all(),
        );
    }

    public function test_reforming_replaces_previous_teams(): void
    {
        ['lesson' => $lesson] = $this->publishedLessonWithRoster(['Emma V.', 'Liam K.', 'Noah P.', 'Sofia B.']);

        $this->postJson("/api/lesson/{$lesson->lesson_code}/teams", ['team_count' => 2])->assertCreated();
        $this->postJson("/api/lesson/{$lesson->lesson_code}/teams", ['team_count' => 2])->assertCreated();

        $this->assertSame(2, LessonTeam::where('lesson_id', $lesson->id)->count());
    }

    public function test_empty_roster_forms_no_teams(): void
    {
        ['lesson' => $lesson] = $this->publishedLessonWithRoster([]);

        $this->postJson("/api/lesson/{$lesson->lesson_code}/teams")
            ->assertCreated()
            ->assertJsonCount(0, 'teams');
    }

    // ── GET /api/lesson/{code}/teams ──────────────────────────────────────

    public function test_index_returns_formed_teams_with_member_names(): void
    {
        ['lesson' => $lesson] = $this->publishedLessonWithRoster(['Emma V.', 'Liam K.']);

        $this->postJson("/api/lesson/{$lesson->lesson_code}/teams", ['team_count' => 2])->assertCreated();

        $response = $this->getJson("/api/lesson/{$lesson->lesson_code}/teams")
            ->assertOk()
            ->assertJsonCount(2, 'teams');

        $allNames = collect($response->json('teams'))
            ->flatMap(fn (array $team) => collect($team['members'])->pluck('name'))
            ->all();

        $this->assertEqualsCanonicalizing(['Emma V.', 'Liam K.'], $allNames);
    }

    public function test_unpublished_lesson_returns_404(): void
    {
        ['lesson' => $lesson, 'classroom' => $classroom] = $this->publishedLessonWithRoster(['Emma V.', 'Liam K.']);

        $draft = Lesson::factory()->create(['teacher_id' => $classroom->teacher_id]);
        $classroom->lessons()->attach($draft->id, ['assigned_at' => now()]);

        $this->postJson("/api/lesson/{$draft->lesson_code}/teams")->assertNotFound();
    }

    public function test_team_routes_are_throttled(): void
    {
        ['lesson' => $lesson] = $this->publishedLessonWithRoster(['Emma V.', 'Liam K.']);

        foreach (range(1, 20) as $i) {
            $this->getJson("/api/lesson/{$lesson->lesson_code}/teams")->assertOk();
        }

        $this->getJson("/api/lesson/{$lesson->lesson_code}/teams")->assertStatus(429);
    }
}
