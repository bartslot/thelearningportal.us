<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class LessonTeam extends Model
{
    protected $fillable = ['lesson_id', 'classroom_id', 'name', 'color'];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(LessonTeamMember::class, 'team_id');
    }

    /**
     * Auto-form teams by dividing classroom students evenly.
     * Creates $teamCount teams (or one team per $studentsPerTeam if provided).
     *
     * @return Collection<int, LessonTeam>
     */
    public static function autoForm(Lesson $lesson, int $teamCount = 4): Collection
    {
        // Delete any existing teams for this lesson
        static::where('lesson_id', $lesson->id)->delete();

        $classrooms = $lesson->classrooms;
        $students   = collect();

        foreach ($classrooms as $classroom) {
            $students = $students->concat($classroom->students()->get());
        }

        $students   = $students->unique('id')->shuffle();
        $teamCount  = max(1, min($teamCount, $students->count()));
        $chunks     = $students->chunk((int) ceil($students->count() / $teamCount));

        $colors = ['amber', 'sky', 'emerald', 'rose', 'violet', 'orange'];
        $names  = ['Robespierre', 'Napoleon', 'Danton', 'Mirabeau', 'Marat', 'Lafayette'];

        $teams = collect();

        foreach ($chunks as $i => $chunk) {
            $team = static::create([
                'lesson_id'    => $lesson->id,
                'classroom_id' => $classrooms->first()?->id,
                'name'         => $names[$i % count($names)],
                'color'        => $colors[$i % count($colors)],
            ]);

            foreach ($chunk as $student) {
                LessonTeamMember::create([
                    'team_id'    => $team->id,
                    'student_id' => $student->id,
                ]);
            }

            $teams->push($team->load('members'));
        }

        return $teams;
    }
}
