<div class="max-w-5xl mx-auto p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">Story Catalog Review</h1>
        <select wire:model.live="statusFilter" class="select select-sm select-bordered">
            <option value="all">All statuses</option>
            <option value="draft">Draft</option>
            <option value="reviewed">Reviewed</option>
            <option value="published">Published</option>
        </select>
    </div>

    @error('transition')
        <div class="alert alert-error text-sm">{{ $message }}</div>
    @enderror

    <div class="space-y-2">
        @forelse ($this->stories as $story)
            <div class="card bg-base-200 border border-base-300">
                <div class="card-body p-4 gap-2">
                    <div class="flex items-center justify-between gap-3">
                        <button type="button" wire:click="toggle({{ $story->id }})" class="text-left flex-1">
                            <span class="font-semibold">{{ $story->title }}</span>
                            @if ($story->subtitle)
                                <span class="text-sm opacity-60">— {{ $story->subtitle }}</span>
                            @endif
                            <div class="text-xs opacity-50 mt-0.5">
                                {{ collect([$story->locale, $story->region, $story->grade_band])->filter()->implode(' · ') }}
                                · {{ $story->sources_count }} {{ __('sources') }}
                                · {{ count($story->learning_objectives ?? []) }} {{ __('objectives') }}
                                · {{ $story->lessons_count }} {{ __('lessons') }}
                            </div>
                        </button>

                        @if (empty($story->assets) && in_array($story->status->value, ['reviewed', 'published'], true))
                            {{-- Publishing is NOT blocked without a pack — lessons fall back to live shots. --}}
                            <span class="badge badge-warning badge-sm" title="Lessons from this story fall back to live per-scene image generation">no asset pack</span>
                        @endif

                        <span class="badge badge-sm
                            @if($story->status->value === 'published') badge-success
                            @elseif($story->status->value === 'reviewed') badge-info
                            @else badge-ghost @endif">{{ $story->status->label() }}</span>

                        @if ($story->status->value === 'draft')
                            <button wire:click="transition({{ $story->id }}, 'reviewed')" class="btn btn-xs btn-outline">Mark reviewed</button>
                        @elseif ($story->status->value === 'reviewed')
                            <button wire:click="transition({{ $story->id }}, 'published')" class="btn btn-xs btn-success">Publish</button>
                            <button wire:click="transition({{ $story->id }}, 'draft')" class="btn btn-xs btn-ghost">Back to draft</button>
                        @else
                            <button wire:click="transition({{ $story->id }}, 'reviewed')" class="btn btn-xs btn-ghost">Unpublish</button>
                        @endif
                    </div>

                    @if ($openStoryId === $story->id && $this->openStory)
                        <div class="border-t border-base-300 pt-3 mt-1 grid md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <h3 class="font-semibold text-xs uppercase opacity-60 mb-1">Learning objectives</h3>
                                <ul class="list-disc ml-4 space-y-1">
                                    @forelse ($this->openStory->learning_objectives ?? [] as $objective)
                                        <li><span class="opacity-60">{{ $objective['id'] ?? '?' }}:</span> {{ $objective['text'] ?? '' }}</li>
                                    @empty
                                        <li class="opacity-50">None — run <code>story:draft {{ $story->slug }}</code></li>
                                    @endforelse
                                </ul>

                                <h3 class="font-semibold text-xs uppercase opacity-60 mt-3 mb-1">Misconceptions</h3>
                                <ul class="list-disc ml-4 space-y-1">
                                    @forelse ($this->openStory->misconceptions ?? [] as $entry)
                                        <li>{{ $entry['misconception'] ?? '' }} <span class="opacity-60">→ {{ $entry['correction'] ?? '' }}</span></li>
                                    @empty
                                        <li class="opacity-50">None</li>
                                    @endforelse
                                </ul>
                            </div>
                            <div>
                                <h3 class="font-semibold text-xs uppercase opacity-60 mb-1">Sources</h3>
                                @forelse ($this->openStory->sources as $source)
                                    <div class="mb-2">
                                        <div class="font-medium">{{ $source->title }}</div>
                                        <div class="text-xs opacity-60">{{ collect([$source->author, $source->license, $source->origin])->filter()->implode(' · ') }}</div>
                                        <p class="text-xs opacity-80 mt-1 line-clamp-4">{{ \Illuminate\Support\Str::limit($source->excerpt, 400) }}</p>
                                    </div>
                                @empty
                                    <p class="opacity-50">No source excerpts yet.</p>
                                @endforelse
                            </div>

                            <div class="md:col-span-2 border-t border-base-300 pt-3">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-xs uppercase opacity-60">Asset pack</h3>
                                    @if (! empty($this->openStory->assets))
                                        <button wire:click="generatePack({{ $story->id }}, true)"
                                                wire:loading.attr="disabled" wire:target="generatePack"
                                                class="btn btn-xs btn-outline">
                                            <span wire:loading wire:target="generatePack" class="loading loading-spinner loading-xs"></span>
                                            Regenerate pack (~$1)
                                        </button>
                                    @else
                                        <button wire:click="generatePack({{ $story->id }})"
                                                wire:loading.attr="disabled" wire:target="generatePack"
                                                class="btn btn-xs btn-primary">
                                            <span wire:loading wire:target="generatePack" class="loading loading-spinner loading-xs"></span>
                                            Generate asset pack (~$1)
                                        </button>
                                    @endif
                                </div>

                                @error('assetPack')
                                    <div class="alert alert-error text-xs mb-2">{{ $message }}</div>
                                @enderror

                                @if ($assets = $this->openStory->assets)
                                    <div class="text-xs opacity-50 mb-2">
                                        style: {{ $assets['style'] ?? '?' }} · generated {{ $assets['generated_at'] ?? '?' }}
                                        · {{ count($assets['backgrounds'] ?? []) }} backgrounds · {{ count($assets['hero'] ?? []) }} hero poses
                                    </div>

                                    <div class="flex flex-wrap gap-3 mb-3">
                                        @foreach ($assets['backgrounds'] ?? [] as $background)
                                            <figure class="w-36">
                                                <img src="{{ asset('storage/'.$background['image_path']) }}"
                                                     alt="{{ $background['tag'] }}"
                                                     class="w-36 h-24 object-cover rounded border border-base-300">
                                                <figcaption class="flex flex-wrap gap-1 mt-1">
                                                    <span class="badge badge-ghost badge-xs">{{ $background['tag'] }}</span>
                                                    <span class="badge badge-outline badge-xs">{{ $background['lighting'] }}</span>
                                                </figcaption>
                                            </figure>
                                        @endforeach
                                    </div>

                                    <div class="flex flex-wrap gap-3 items-end">
                                        @foreach ($assets['hero'] ?? [] as $pose)
                                            <figure class="w-20">
                                                <img src="{{ asset('storage/'.$pose['image_path']) }}"
                                                     alt="{{ $pose['pose'] }}"
                                                     class="w-20 h-28 object-contain rounded border border-base-300 bg-base-100">
                                                <figcaption class="mt-1">
                                                    <span class="badge badge-ghost badge-xs">{{ $pose['pose'] }}</span>
                                                </figcaption>
                                            </figure>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-xs opacity-50">
                                        No asset pack yet — lessons from this story fall back to live per-scene generation.
                                        Generate once here (or <code>story:pack {{ $story->slug }}</code>), review, then publish.
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="text-center opacity-60 py-10">
                No stories yet. Seed with <code>php artisan db:seed --class=CanonStoriesSeeder</code>
                then import excerpts via <code>story:import-gutenberg</code>.
            </div>
        @endforelse
    </div>
</div>
