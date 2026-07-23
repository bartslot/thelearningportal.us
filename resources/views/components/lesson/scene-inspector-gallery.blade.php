@props(['scene' => null])

@php
    $config = $scene->config ?? [];
    $images = array_values($config['images'] ?? []);
@endphp

<div class="space-y-3 text-sm">
    <h3 class="flex items-center gap-2 font-semibold text-violet-300">
        <x-lesson.icon-slideshow class="h-5 w-5" />
        Slideshow
    </h3>

    <p class="text-xs text-slate-400">
        An auto-cycling slideshow of the drawings and paintings for this landfall, with a side panel
        of the story.
    </p>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Title</span>
        <input type="text" wire:model.blur="selectedScene.config.title" wire:change="saveSelected"
               placeholder="e.g. Verversingspost"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Date label</span>
        <input type="text" wire:model.blur="selectedScene.config.date_label" wire:change="saveSelected"
               placeholder="e.g. 5 september 1642"
               class="input input-sm input-bordered bg-slate-900 mt-1" />
    </label>

    <label class="form-control">
        <span class="text-xs uppercase tracking-wider text-slate-400">Story</span>
        <textarea wire:model.blur="selectedScene.config.story" wire:change="saveSelected" rows="5"
                  placeholder="What happened at this stop…"
                  class="textarea textarea-bordered bg-slate-900 mt-1 leading-relaxed"></textarea>
    </label>

    {{-- IMAGE FIT — how each image sits in the frame. Cover fills it (may crop); Fit shows the whole
         image letterboxed, with a blurred fill behind. --}}
    @php $fit = ($config['fit'] ?? 'fit') === 'cover' ? 'cover' : 'fit'; @endphp
    <div class="form-control border-t border-slate-700/50 pt-3">
        <span class="text-xs uppercase tracking-wider text-slate-400">Image fit</span>
        <div class="mt-1 inline-flex overflow-hidden rounded-lg border border-slate-700/60">
            <button type="button" wire:click="setGalleryFit('cover')"
                    class="px-3 py-1 text-xs font-medium transition-colors {{ $fit === 'cover' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400' }}">
                Cover
            </button>
            <button type="button" wire:click="setGalleryFit('fit')"
                    class="px-3 py-1 text-xs font-medium transition-colors {{ $fit === 'fit' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-400' }}">
                Fit
            </button>
        </div>
        <span class="mt-1 text-[10px] text-slate-500">Cover fills the frame (may crop). Fit shows the whole image on a blurred backdrop.</span>
    </div>

    {{-- IMAGES — [{url, credit}]. Auto-cycling slideshow; drag to reorder, add from paintings, upload, or URL. --}}
    <div class="form-control border-t border-slate-700/50 pt-3" x-data="{ url: '', drag: null }">
        <span class="text-xs uppercase tracking-wider text-slate-400">Images</span>
        <span class="mt-0.5 block text-[10px] text-slate-500">Shown as a cross-fading slideshow. Drag to reorder.</span>

        @if (count($images))
            <ul class="mt-2 space-y-2">
                @foreach ($images as $i => $img)
                    @php $src = is_array($img) ? ($img['url'] ?? '') : $img; @endphp
                    <li wire:key="galscene-img-{{ $i }}" draggable="true"
                        x-on:dragstart="drag = {{ $i }}; $el.classList.add('opacity-50')"
                        x-on:dragend="$el.classList.remove('opacity-50')"
                        x-on:dragover.prevent
                        x-on:drop.prevent="if (drag !== null && drag !== {{ $i }}) $wire.moveGalleryImage(drag, {{ $i }}); drag = null"
                        class="rounded-lg border border-slate-700/50 bg-slate-900/60 p-1.5 cursor-grab active:cursor-grabbing">
                        <div class="flex items-center gap-2">
                            <span class="shrink-0 select-none text-slate-600" aria-hidden="true" title="Drag to reorder">⠿</span>
                            <img src="{{ $src }}" alt="" class="h-9 w-9 shrink-0 rounded object-cover"
                                 onerror="this.style.visibility='hidden'" />
                            <span class="min-w-0 flex-1 truncate text-[11px] text-slate-400">{{ $src }}</span>
                            <button type="button" wire:click="moveGalleryImage({{ $i }}, {{ $i - 1 }})" @disabled($i === 0)
                                    class="shrink-0 px-1 text-slate-400 hover:text-slate-200 disabled:opacity-30" title="Move up" aria-label="Move up">↑</button>
                            <button type="button" wire:click="moveGalleryImage({{ $i }}, {{ $i + 1 }})" @disabled($i === count($images) - 1)
                                    class="shrink-0 px-1 text-slate-400 hover:text-slate-200 disabled:opacity-30" title="Move down" aria-label="Move down">↓</button>
                            <button type="button" wire:click="removeGalleryImage({{ $i }})"
                                    class="shrink-0 px-1 text-rose-300 hover:text-rose-200" title="Remove" aria-label="Remove">✕</button>
                        </div>
                        <input type="text" value="{{ is_array($img) ? ($img['credit'] ?? '') : '' }}"
                               wire:change="setGalleryImageCredit({{ $i }}, $event.target.value)"
                               placeholder="Credit / source"
                               class="input input-xs input-bordered bg-slate-900 mt-1.5 w-full" />
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-1 text-[10px] text-slate-500">No images yet.</p>
        @endif

        {{-- Consistent "+ Add image" affordance (same picker as voyage — paintings + upload). --}}
        <button type="button" wire:click="openGalleryImagePicker"
                class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-slate-600 py-1.5 text-xs font-medium text-slate-300 hover:border-slate-400 hover:text-slate-100">
            <span class="text-base leading-none">+</span> Add image
        </button>
        <div class="mt-1.5 flex items-center gap-1.5">
            <input type="url" x-model="url" placeholder="or paste an image URL"
                   class="input input-xs input-bordered bg-slate-900 flex-1" />
            <button type="button" class="btn btn-xs btn-primary"
                    @click="if (url.trim()) { $wire.addGalleryImage(url); url='' }">Add</button>
        </div>
    </div>

    <button type="button" wire:click="deleteScene({{ $scene->id }})"
            wire:confirm="Delete this gallery?"
            class="mt-2 text-xs text-rose-300 underline hover:text-rose-200">Delete gallery</button>
</div>
