<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\OnboardingStatus;
use App\Models\User;
use App\Support\HelpGuide;
use App\Support\HelpMedia;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The help centre (/help) — the same explanations as the welcome tour, at full length, illustrated
 * with screenshots of the teacher's own language, and searchable.
 *
 * Content comes from HelpGuide so the help centre and the tour can never disagree.
 */
#[Title('Help')]
class Help extends Component
{
    /** Kept in the URL so a search can be linked to or reloaded. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function clearSearch(): void
    {
        $this->search = '';
    }

    /**
     * Topics, filtered by the search box. A topic survives if the query appears anywhere in it:
     * title, summary, a heading, a paragraph or a bullet. Matching sections are marked so the view
     * can show only those while searching, instead of making the teacher re-scan the whole topic.
     *
     * @return list<array<string,mixed>>
     */
    public function topics(): array
    {
        $query = trim($this->search);

        $topics = array_map(function (array $topic) {
            $topic['sections'] = array_map(function (array $section) {
                $section['image'] ??= null;
                $section['image_url'] = HelpMedia::url($section['image']);

                return $section;
            }, $topic['sections']);

            return $topic;
        }, $this->forAudience(HelpGuide::topics()));

        if ($query === '') {
            return array_map(function (array $topic) {
                $topic['matched_sections'] = null;

                return $topic;
            }, $topics);
        }

        $matches = [];

        foreach ($topics as $topic) {
            $matchedSections = array_values(array_filter(
                $topic['sections'],
                fn (array $section) => $this->matches($query, [
                    $section['heading'],
                    $section['body'] ?? '',
                    ...$section['steps'],
                ]),
            ));

            $topicMatches = $this->matches($query, [$topic['title'], $topic['summary']]);

            if (! $topicMatches && $matchedSections === []) {
                continue;
            }

            // A hit on the topic itself shows the whole topic; a hit inside one section shows that
            // section only.
            $topic['matched_sections'] = $topicMatches ? null : $matchedSections;
            $matches[] = $topic;
        }

        return $matches;
    }

    /**
     * Places in the app matching the search, so "results" offers the Results page and not only the
     * paragraph about it.
     *
     * @return list<array<string,string>>
     */
    public function shortcuts(): array
    {
        $query = trim($this->search);

        if ($query === '') {
            return [];
        }

        return array_values(array_filter(
            $this->forAudience(HelpGuide::shortcuts()),
            fn (array $shortcut) => $this->matches($query, [
                $shortcut['label'],
                $shortcut['description'],
                $shortcut['keywords'],
            ]),
        ));
    }

    /**
     * Drop anything meant for admins when the reader is not one. An admin is a teacher with extra
     * keys — appointed per school — so they see the teacher material *and* the admin material;
     * everyone else never learns that the extra pages exist.
     *
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function forAudience(array $entries): array
    {
        $user = auth()->user();
        $isAdmin = $user instanceof User && $user->isAdmin();

        return array_values(array_filter(
            $entries,
            fn (array $entry) => ($entry['audience'] ?? 'all') !== 'admin' || $isAdmin,
        ));
    }

    public function hasResults(): bool
    {
        return $this->topics() !== [] || $this->shortcuts() !== [];
    }

    /** Accent- and case-insensitive substring match across a haystack of strings. */
    private function matches(string $query, array $haystack): bool
    {
        $needle = Str::lower(Str::ascii($query));

        if ($needle === '') {
            return true;
        }

        foreach ($haystack as $text) {
            if (str_contains(Str::lower(Str::ascii((string) $text)), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** The tour only runs on the teacher dashboard, so only teachers are offered the button. */
    public function canRunTour(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isTeacher() || $user->isAdmin());
    }

    /** Closed the tour halfway? Offer to carry on rather than sit through it again. */
    public function tourResumable(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->onboardingStatus() === OnboardingStatus::Dismissed
            && ($user->onboarding_step ?? 0) > 0;
    }

    /**
     * Reopen the tour on the dashboard: from where it was left when it was closed part-way,
     * from the beginning otherwise.
     */
    public function startTutorial(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $resume = $this->tourResumable();

        $user->forceFill([
            'onboarding_status' => $resume ? OnboardingStatus::Active : OnboardingStatus::Pending,
            'onboarding_step' => $resume ? (int) $user->onboarding_step : 0,
            'onboarded_at' => null,
        ])->save();

        $this->redirectRoute('teacher.dashboard');
    }

    public function render()
    {
        return view('livewire.help');
    }
}
