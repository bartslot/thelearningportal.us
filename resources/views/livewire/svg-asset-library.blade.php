<div class="space-y-6">

    {{-- Search Wikimedia Commons --}}
    <div class="card bg-base-200 border border-base-300">
        <div class="card-body gap-4">
            <div>
                <h3 class="card-title text-base">Add artwork from the open web</h3>
                <p class="text-sm opacity-70">Search public-domain and freely-licensed SVGs, or paste a
                    freesvg.org link. Imported art is drawn line-by-line in the ink style.</p>
            </div>

            <form wire:submit="search" class="join w-full">
                <input type="search" wire:model="query" placeholder="Roman soldier, tall ship, castle…"
                       class="input input-bordered join-item w-full" aria-label="Search public-domain SVGs">
                <button class="btn btn-primary join-item" type="submit">
                    <span wire:loading wire:target="search" class="loading loading-spinner loading-sm"></span>
                    Search
                </button>
            </form>

            @if ($notice)
                <div class="alert alert-info py-2 text-sm">{{ $notice }}</div>
            @endif

            @error('query') <p class="text-error text-sm">{{ $message }}</p> @enderror

            @if ($searched && count($results) === 0)
                <p class="text-sm opacity-60 italic">No freely-licensed SVGs found for that search. Try a simpler term,
                    or paste a freesvg.org link below.</p>
            @endif

            @if (count($results))
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($results as $i => $r)
                        <div class="group relative rounded-lg border border-base-300 bg-base-100 p-2">
                            <div class="flex h-24 items-center justify-center overflow-hidden rounded bg-base-200">
                                @if (! empty($r['thumb_url']))
                                    <img src="{{ $r['thumb_url'] }}" alt="{{ $r['title'] }}" class="max-h-full max-w-full">
                                @else
                                    <span class="text-xs opacity-30">SVG</span>
                                @endif
                            </div>
                            <p class="mt-2 truncate text-xs font-medium" title="{{ $r['title'] }}">{{ $r['title'] }}</p>
                            <span class="badge badge-ghost badge-xs mt-1">{{ $r['license'] }}</span>
                            <button wire:click="import({{ $i }})" wire:loading.attr="disabled"
                                    class="btn btn-xs btn-primary mt-2 w-full">
                                <span wire:loading.remove wire:target="import({{ $i }})">Add to library</span>
                                <span wire:loading wire:target="import({{ $i }})" class="loading loading-spinner loading-xs"></span>
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Paste-a-link fallback (freesvg.org, Wikimedia) --}}
            <div class="divider text-xs opacity-50">or paste a link</div>
            <form wire:submit="importUrl" class="join w-full">
                <input type="url" wire:model="urlInput" placeholder="https://freesvg.org/…"
                       class="input input-bordered input-sm join-item w-full" aria-label="Import SVG from URL">
                <button class="btn btn-sm join-item" type="submit">Import</button>
            </form>
            @error('urlInput') <p class="text-error text-sm">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Personal library --}}
    <div>
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide opacity-60">Your imported artwork</h3>
        @if ($this->library->isEmpty())
            <p class="text-sm opacity-50 italic">Nothing yet. Search above to build your ink-art library.</p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 md:grid-cols-5">
                @foreach ($this->library as $asset)
                    <div class="group relative rounded-lg border border-base-300 bg-base-100 p-2">
                        <div class="flex h-24 items-center justify-center overflow-hidden rounded bg-base-200">
                            <img src="{{ $asset->url() }}" alt="{{ $asset->title }}" class="max-h-full max-w-full">
                        </div>
                        <p class="mt-2 truncate text-xs font-medium" title="{{ $asset->title }}">{{ $asset->title }}</p>
                        <p class="truncate text-[11px] opacity-50" title="{{ $asset->credit() }}">{{ $asset->credit() }}</p>
                        <button wire:click="remove({{ $asset->id }})"
                                wire:confirm="Remove this artwork from your library?"
                                class="btn btn-ghost btn-xs btn-circle absolute right-1 top-1 text-error opacity-0 transition group-hover:opacity-100"
                                aria-label="Remove {{ $asset->title }}">✕</button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
