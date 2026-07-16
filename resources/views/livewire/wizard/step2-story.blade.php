@php use App\Enums\NarrativeFramework; @endphp
<div class="space-y-4 pt-6">

    {{-- ═══════════════════════════════════ STORY ARC ═══════════════════════════════════ --}}
    <div class="bg-base-300 rounded-2xl p-6 space-y-4">
        <span class="label-text text-xs uppercase tracking-wider text-slate-400">
            Choose your story arc
        </span>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
            @foreach ($this->frameworks as $fw)
                <button type="button"
                        wire:click="selectFramework('{{ $fw->value }}')"
                        @class([
                            'flex flex-col items-start gap-1.5 rounded-xl border-2 p-3.5 text-left transition-all',
                            'border-amber-400 bg-amber-500/10' => $narrative_framework === $fw->value,
                            'border-slate-600 hover:border-slate-400' => $narrative_framework !== $fw->value,
                        ])>
                    <i class="ti {{ $fw->icon() }} text-xl @if($narrative_framework === $fw->value) text-amber-300 @else text-slate-300 @endif"></i>
                    <span class="text-sm font-medium text-white">{{ $fw->label() }}</span>
                    <span class="text-xs text-slate-400 leading-snug">{{ $fw->description() }}</span>
                    @if ($fw === NarrativeFramework::default())
                        <span class="mt-0.5 text-[10px] uppercase tracking-wide text-amber-300/70">default</span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    {{-- ═══════════════════════════════════ HERO PICKER ═════════════════════════════════ --}}
    @if ($this->framework()->needsHero())
        <div class="bg-base-300 rounded-2xl p-6 space-y-3">
            <div class="flex items-baseline justify-between">
                <span class="label-text text-xs uppercase tracking-wider text-slate-400">Choose your hero</span>
                <span class="text-xs text-slate-500">from the corpus · rulers first</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                @forelse ($this->heroes as $hero)
                    <button type="button"
                            wire:click="selectHero('{{ $hero->qid }}')"
                            wire:key="hero-{{ $hero->qid }}"
                            @class([
                                'flex items-center gap-3 rounded-lg border-2 p-2.5 text-left transition-all',
                                'border-amber-400 bg-amber-500/10' => $protagonist_qid === $hero->qid,
                                'border-slate-600 hover:border-slate-400' => $protagonist_qid !== $hero->qid,
                            ])>
                        @if ($hero->image_url)
                            <img src="{{ $hero->image_url }}?width=96" alt=""
                                 class="h-11 w-11 shrink-0 rounded-full object-cover bg-slate-700"
                                 loading="lazy"
                                 onerror="this.style.display='none'" />
                        @else
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-slate-700">
                                <i class="ti ti-user text-slate-400"></i>
                            </span>
                        @endif
                        <span class="flex flex-col min-w-0">
                            <span class="text-sm font-medium text-white truncate">{{ $hero->name }}</span>
                            <span class="text-xs text-slate-400">
                                @if ($hero->figure_kind === 'ruler')<span class="text-amber-300/80">Ruler</span> · @endif
                                {{ $hero->era_start ? ($hero->era_start < 0 ? abs($hero->era_start).' BCE' : $hero->era_start.' CE') : '' }}
                            </span>
                        </span>
                    </button>
                @empty
                    <p class="text-sm text-slate-400 col-span-full py-1">
                        No catalog figures for this topic yet — type your hero's name below.
                    </p>
                @endforelse
            </div>

            <div class="pt-1">
                <input type="text" wire:model.blur="protagonist_name"
                       placeholder="…or type a hero's name"
                       class="input input-bordered input-sm bg-slate-900 w-full" />
                @if ($protagonist_name && ! $protagonist_qid)
                    <span class="text-xs text-emerald-400/80 mt-1 inline-block">Using "{{ $protagonist_name }}".</span>
                @endif
            </div>
        </div>
    @elseif ($narrative_framework === 'branching')
        <div class="bg-base-300/60 rounded-2xl px-6 py-4 space-y-3">
            <p class="text-sm text-slate-300 flex items-center gap-2">
                <i class="ti ti-git-branch text-amber-300"></i>
                {{ $story_game
                    ? __('Spel-verhaal: 3 choice points, class meters and a game master — the class survives the story together. History stays true.')
                    : __('The AI adds one choice point mid-lesson — you can edit both paths in Configure.') }}
            </p>
            <label class="label cursor-pointer justify-start gap-3 py-0">
                <input type="checkbox" wire:model.live="story_game" class="toggle toggle-sm toggle-primary" />
                <span class="label-text text-sm">{{ __('Spel-verhaal (full game story)') }}</span>
            </label>
            @if ($story_game)
                <label class="label cursor-pointer justify-start gap-3 py-0 pl-1">
                    <input type="checkbox" wire:model.live="print_pack" class="checkbox checkbox-sm checkbox-primary" />
                    <span class="label-text text-xs opacity-80">{{ __('Also generate a printable game pack (role cards, tokens, meter poster)') }}</span>
                </label>
            @endif
        </div>
    @endif

    {{-- The standalone "Game" field (quiz / strategy / debate) was removed: a lesson is either
         plain narrative or a full Spel-verhaal (driven by the branching-arc toggle above). A story
         game is a game in its own right and shouldn't be mixed with a separate quiz picker. --}}

    {{-- ═══════════════════════════════════ FOOTER ══════════════════════════════════════ --}}
    <div class="relative flex items-center justify-center pt-2">
        <button type="button" wire:click="back" class="btn btn-sm btn-ghost text-slate-300 absolute left-0">← Back</button>
        <button type="button" wire:click="generate" class="btn btn-primary">
            Generate lesson <i class="ti ti-arrow-right"></i>
        </button>
    </div>
</div>
