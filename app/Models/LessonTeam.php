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
     * Auto-form teams by dividing the classroom roster evenly.
     *
     * The roster is the account-less classroom_members list ("Emma V."), NOT the
     * classroom_student pivot — K-12 students never get user accounts here, so
     * members are stored by display name (student_name) with a null student_id.
     *
     * @return Collection<int, LessonTeam>
     */
    public static function autoForm(Lesson $lesson, int $teamCount = 4): Collection
    {
        // Delete any existing teams for this lesson
        static::where('lesson_id', $lesson->id)->delete();

        $classrooms = $lesson->classrooms;

        $memberNames = $classrooms
            ->flatMap(fn (Classroom $classroom) => $classroom->members()->pluck('display_name'))
            ->filter(fn (?string $name) => filled($name))
            ->unique(fn (string $name) => mb_strtolower($name))
            ->shuffle()
            ->values();

        if ($memberNames->isEmpty()) {
            return collect();
        }

        $teamCount = max(1, min($teamCount, $memberNames->count()));
        $chunks    = $memberNames->chunk((int) ceil($memberNames->count() / $teamCount));

        $colors = ['amber', 'sky', 'emerald', 'rose', 'violet', 'orange'];
        $names  = ['Robespierre', 'Napoleon', 'Danton', 'Mirabeau', 'Marat', 'Lafayette'];

        $teams = collect();

        foreach ($chunks->values() as $i => $chunk) {
            $team = static::create([
                'lesson_id'    => $lesson->id,
                'classroom_id' => $classrooms->first()?->id,
                'name'         => $names[$i % count($names)],
                'color'        => $colors[$i % count($colors)],
            ]);

            foreach ($chunk as $displayName) {
                LessonTeamMember::create([
                    'team_id'      => $team->id,
                    'student_id'   => null,
                    'student_name' => $displayName,
                ]);
            }

            $teams->push($team->load('members'));
        }

        return $teams;
    }
}
