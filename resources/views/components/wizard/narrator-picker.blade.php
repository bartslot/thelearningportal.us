@props([
    'narrators'  => collect(),
    'selectedId' => null,
])

<div
    x-data="{
        playNarrator(url) {
            if (!url) return;

            window.__narratorPickerAudio?.pause();
            window.__narratorPickerAudio = new Audio(url);
            window.__narratorPickerAudio.currentTime = 0;
            window.__narratorPickerAudio.play().catch(() => {});
        }
    }"
    class="flex gap-3 overflow-x-auto pb-2"
>
    @foreach ($narrators as $narrator)
        @php
            $teacherId = auth()->id();
            $greetingPath = $teacherId ? "avatar-greetings/{$narrator->id}/{$teacherId}.mp3" : null;
            $greetingUrl = $greetingPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($greetingPath)
                ? "/storage/{$greetingPath}"
                : null;
        @endphp

        <button type="button"
                wire:click="$set('avatar_id', {{ $narrator->id }})"
                x-on:click="playNarrator(@js($greetingUrl))"
                data-narrator-sound-url="{{ $greetingUrl ?? '' }}"
                aria-label="Select {{ $narrator->name }}{{ $greetingUrl ? ' and play voice preview' : '' }}"
                aria-pressed="{{ $selectedId === $narrator->id ? 'true' : 'false' }}"
                @class([
                    'group shrink-0 w-32 h-32 rounded-xl overflow-hidden transition-all relative focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-300 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900',
                    'ring-2 ring-amber-400 ring-offset-2 ring-offset-slate-900' => $selectedId === $narrator->id,
                    'ring-1 ring-slate-700/50 hover:ring-slate-500' => $selectedId !== $narrator->id,
                ])>
            <img src="{{ $narrator->portraitUrl() ?? asset('assets/avatar-fallback.png') }}"
                 alt="{{ $narrator->name }}"
                 class="w-full h-full object-cover transition-transform duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-110 group-focus-visible:scale-105 motion-reduce:transition-none motion-reduce:scale-100" />
            <span class="absolute bottom-0 left-0 right-0 text-[9px] bg-black/55 text-white py-0.5">{{ $narrator->name }}</span>
        </button>
    @endforeach
</div>
