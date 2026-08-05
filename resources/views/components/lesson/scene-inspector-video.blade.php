@props(['scene' => null])

@php
    $embed = ($scene->config ?? [])['bg_embed'] ?? null;
    $hasVideo = is_array($embed) && ($embed['kind'] ?? '') === 'video';
@endphp

{{-- Video scene (kind='video') — the film IS the scene: it fills the stage and the class moves on
     with Continue. Pasting a link here is the whole of authoring one. --}}
<div class="space-y-3 text-sm">
    <h3 class="flex items-center gap-2 font-semibold text-indigo-300">
        <x-lesson.icon-video class="h-5 w-5" />
        {{ __('Video') }}
    </h3>

    <div x-data="{ link: '' }">
        <span class="mb-1 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Video link or embed code') }}</span>
        <div class="flex gap-1.5">
            <input type="text" x-model="link" placeholder="https://youtu.be/… · vimeo.com/… · &lt;iframe …&gt;"
                   @keydown.enter.prevent="if (link.trim()) { $wire.setVideoEmbed(link); link = '' }"
                   class="input input-xs input-bordered flex-1 bg-slate-900" />
            <button type="button" @click="if (link.trim()) { $wire.setVideoEmbed(link); link = '' }"
                    class="btn btn-xs border-0 bg-amber-500 font-semibold text-slate-950 hover:bg-amber-400">{{ __('Set') }}</button>
        </div>
    </div>

    @if ($hasVideo)
        <div class="aspect-video overflow-hidden rounded-lg ring-1 ring-slate-700">
            <iframe src="{{ $embed['src'] }}" class="h-full w-full" style="border:0"
                    allow="autoplay; fullscreen; encrypted-media; picture-in-picture" allowfullscreen></iframe>
        </div>

        {{-- Playback settings — autoplay, controls, cover/fit, start/end (live-saved). --}}
        <div x-data="{
                autoplay: @js((bool) ($embed['autoplay'] ?? true)),
                controls: @js((bool) ($embed['controls'] ?? true)),
                fit: @js($embed['fit'] ?? 'cover'),
                start: @js((int) ($embed['start'] ?? 0)),
                end: @js((int) ($embed['end'] ?? 0)),
                save() { $wire.setEmbedOptions({ autoplay: this.autoplay, controls: this.controls, fit: this.fit, start: Number(this.start) || 0, end: Number(this.end) || 0 }) }
             }" class="space-y-2 rounded-lg bg-slate-800/40 p-2">
            <label class="flex items-center justify-between gap-2">
                <span class="text-[11px] text-slate-300">{{ __('Autoplay (muted)') }}</span>
                <input type="checkbox" x-model="autoplay" @change="save()" class="toggle toggle-sm toggle-warning" />
            </label>
            <label class="flex items-center justify-between gap-2">
                <span class="text-[11px] text-slate-300">{{ __('Show controls') }}</span>
                <input type="checkbox" x-model="controls" @change="save()" class="toggle toggle-sm toggle-warning" />
            </label>
            <div class="flex items-center justify-between gap-2">
                <span class="text-[11px] text-slate-300">{{ __('Fit') }}</span>
                <div class="inline-flex overflow-hidden rounded-lg border border-slate-700/60">
                    <button type="button" @click="fit = 'cover'; save()" :class="fit === 'cover' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400'" class="px-2.5 py-1 text-xs font-medium">{{ __('Cover') }}</button>
                    <button type="button" @click="fit = 'fit'; save()" :class="fit === 'fit' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400'" class="px-2.5 py-1 text-xs font-medium">{{ __('Fit') }}</button>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <label class="form-control">
                    <span class="text-[10px] uppercase tracking-wider text-slate-500">{{ __('Start (s)') }}</span>
                    <input type="number" min="0" x-model="start" @change="save()" class="input input-xs input-bordered bg-slate-900 mt-1" />
                </label>
                <label class="form-control">
                    <span class="text-[10px] uppercase tracking-wider text-slate-500">{{ __('End (s)') }}</span>
                    <input type="number" min="0" x-model="end" @change="save()" class="input input-xs input-bordered bg-slate-900 mt-1" />
                </label>
            </div>
        </div>

        <button type="button" wire:click="clearBgEmbed" class="text-[11px] text-rose-300 underline hover:text-rose-200">{{ __('Remove video') }}</button>
    @endif
</div>
