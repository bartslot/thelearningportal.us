<div class="mx-auto max-w-6xl" x-data>
    <a href="{{ route('admin.narrators.index') }}" class="btn btn-ghost btn-sm px-0">← Back to narrators</a>

    <div class="mt-7 grid gap-10 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <main>
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-400">Narrator Studio · New narrator</p>
            <h1 class="mt-2 font-history text-5xl font-semibold leading-none text-slate-100 md:text-6xl">Build a voice<br>students remember.</h1>

            <nav aria-label="Narrator setup progress" class="mt-10 grid grid-cols-4 border-b border-slate-800">
                @foreach([1 => 'Identity', 2 => 'Portrait', 3 => 'Voice', 4 => 'Review'] as $number => $label)
                    <button type="button" wire:click="goToStep({{ $number }})" @disabled($number > $step)
                            class="relative pb-3 text-left text-xs font-semibold uppercase tracking-wider {{ $step === $number ? 'text-amber-300' : ($number < $step ? 'text-slate-300' : 'text-slate-600') }} disabled:cursor-not-allowed">
                        <span class="mr-1.5">0{{ $number }}</span> {{ $label }}
                        @if($step === $number)<span class="absolute inset-x-0 -bottom-px h-0.5 bg-amber-400"></span>@endif
                    </button>
                @endforeach
            </nav>

            <form wire:submit="createNarrator" class="mt-9 min-h-[28rem]">
                @if($step === 1)
                    <section wire:key="identity" class="max-w-2xl space-y-6">
                        <div><h2 class="text-2xl font-semibold text-slate-100">Who is joining the cast?</h2><p class="mt-1 text-sm text-slate-500">Give teachers enough context to choose this narrator with confidence.</p></div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <fieldset class="fieldset sm:col-span-2"><legend class="fieldset-legend">Display name</legend><input wire:model="name" autofocus placeholder="e.g. Dr. Maya Okafor" class="input w-full">@error('name')<p class="label text-error">{{ $message }}</p>@enderror</fieldset>
                            <fieldset class="fieldset"><legend class="fieldset-legend">Short name <span class="font-normal text-base-content/40">optional</span></legend><input wire:model="short_name" placeholder="Maya" class="input w-full"></fieldset>
                            <fieldset class="fieldset"><legend class="fieldset-legend">Role or title</legend><input wire:model="avatar_title" placeholder="Historian" class="input w-full"></fieldset>
                            <fieldset class="fieldset"><legend class="fieldset-legend">Best for</legend><select wire:model="subject" class="select w-full"><option value="all">All subjects</option><option value="history">History</option><option value="science">Science</option><option value="literature">Literature</option><option value="civics">Civics</option></select></fieldset>
                            <fieldset class="fieldset sm:col-span-2"><legend class="fieldset-legend">Teacher-facing description</legend><textarea wire:model="description" rows="3" placeholder="A warm, curious guide who makes complex ideas feel approachable." class="textarea w-full resize-none"></textarea></fieldset>
                        </div>
                    </section>
                @elseif($step === 2)
                    <section wire:key="portrait" class="max-w-2xl space-y-6">
                        <div><h2 class="text-2xl font-semibold text-slate-100">Choose a strong portrait.</h2><p class="mt-1 text-sm text-slate-500">Use a square image with a clear face. It appears beside lessons while the narrator speaks.</p></div>
                        <label class="card group grid min-h-72 cursor-pointer place-items-center overflow-hidden border-dashed transition hover:border-primary/70">
                            @if($portrait)
                                <img src="{{ $portrait->temporaryUrl() }}" alt="Portrait preview" class="h-72 w-72 object-contain aspect-square">
                            @else
                                <span class="text-center"><span class="btn btn-circle btn-ghost mx-auto text-2xl">↑</span><span class="mt-4 block font-semibold text-base-content">Drop a portrait here or browse</span><span class="mt-1 block text-xs text-base-content/50">JPG, PNG or WebP · up to 4 MB</span></span>
                            @endif
                            <input wire:model="portrait" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only">
                        </label>
                        <div wire:loading wire:target="portrait" class="flex items-center gap-2 text-sm text-primary"><span class="loading loading-spinner loading-xs"></span> Preparing preview…</div>
                        @error('portrait')<div role="alert" class="alert alert-error alert-soft py-2 text-sm">{{ $message }}</div>@enderror

                        <fieldset class="fieldset" x-data="{ uploading: false, uploadError: '' }"
                                  x-on:livewire-upload-start="uploading = true; uploadError = ''"
                                  x-on:livewire-upload-finish="uploading = false"
                                  x-on:livewire-upload-error="uploading = false; uploadError = @js(__('The video could not be uploaded. Check the file type and keep it under :limit.', ['limit' => $this->videoLimit]))">
                            <legend class="fieldset-legend">Introduction video <span class="font-normal text-base-content/40">optional</span></legend>
                            <input wire:model="intro_video" type="file" accept="video/mp4,video/webm,video/quicktime" class="file-input w-full">
                            <p class="label">{{ __('A short, landscape or portrait video where the narrator introduces themselves. MP4, WebM or MOV · up to :limit.', ['limit' => $this->videoLimit]) }}</p>
                            @if($this->videoLimitIsServerImposed)
                                {{-- Not our rule: this server's PHP is the thing capping it, and a
                                     teacher blaming their file for a deployment setting is how this
                                     stayed broken. Name it, with the two directives to change. --}}
                                <p class="label text-warning">{{ __('This server currently accepts :limit. Raise upload_max_filesize and post_max_size in PHP to allow more.', ['limit' => $this->videoLimit]) }}</p>
                            @endif
                            <div x-show="uploading" x-cloak class="flex items-center gap-2 text-sm text-primary"><span class="loading loading-spinner loading-xs"></span> Preparing video…</div>
                            <div x-show="uploadError" x-cloak role="alert" class="alert alert-error alert-soft mt-2 py-2 text-sm" x-text="uploadError"></div>
                            @if($intro_video)
                                <video controls preload="metadata" class="mt-2 max-h-72 w-full rounded-box bg-base-300" src="{{ $intro_video->temporaryUrl() }}">
                                    Your browser does not support video playback.
                                </video>
                            @endif
                            @error('intro_video')<div role="alert" class="alert alert-error alert-soft mt-2 py-2 text-sm">{{ $message }}</div>@enderror
                        </fieldset>
                    </section>
                @elseif($step === 3)
                    <section wire:key="voice" class="space-y-6">
                        <div><h2 class="text-2xl font-semibold text-slate-100">{{ __("Set the narrator’s voice.") }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('More languages and fine controls are available in the Studio.') }}</p></div>

                        {{-- Which service speaks. ElevenLabs first: it is what every narrator in the
                             catalogue already uses, and the only one that can carry a cloned voice. --}}
                        <div role="radiogroup" class="grid gap-3 sm:grid-cols-2">
                            @foreach(['elevenlabs' => __('ElevenLabs'), 'edge_tts' => __('Edge TTS')] as $key => $label)
                                <label class="card cursor-pointer p-4 transition {{ $voice_provider === $key ? 'border-primary bg-primary/10' : 'hover:border-base-content/25' }}">
                                    <span class="flex items-center justify-between">
                                        <span>
                                            <span class="block font-semibold text-base-content">{{ $label }}</span>
                                            <span class="mt-1 block text-xs text-base-content/50">{{ $key === 'elevenlabs' ? __('Your own cloned voices. Paste the voice id.') : __('Free built-in voices.') }}</span>
                                        </span>
                                        <input wire:model.live="voice_provider" type="radio" value="{{ $key }}" class="radio radio-primary radio-sm">
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        @if($voice_provider === 'elevenlabs')
                            <fieldset class="fieldset max-w-md">
                                <legend class="fieldset-legend">{{ __('ElevenLabs voice id') }}</legend>
                                <input wire:model.blur="elevenlabs_voice_id" type="text" spellcheck="false" autocomplete="off"
                                       class="input input-bordered w-full font-mono @error('elevenlabs_voice_id') input-error @enderror"
                                       placeholder="Gwv6uO8RNVuOAN68JgUb">
                                <p class="fieldset-label">{{ __('Open the voice in your ElevenLabs dashboard and copy its id.') }}</p>
                                @error('elevenlabs_voice_id') <p class="fieldset-label text-error">{{ $message }}</p> @enderror
                            </fieldset>
                        @else
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach($this->voices as $voice)
                                    <label class="card cursor-pointer p-4 transition {{ $voice_id === $voice['id'] ? 'border-primary bg-primary/10' : 'hover:border-base-content/25' }}">
                                        <span class="flex items-center justify-between"><span><span class="block font-semibold text-base-content">{{ $voice['flag'] }} {{ $voice['name'] }}</span><span class="mt-1 block text-xs text-base-content/50">{{ $voice['language'] }} · {{ $voice['note'] ?: 'natural' }}</span></span><input wire:model.live="voice_id" type="radio" value="{{ $voice['id'] }}" class="radio radio-primary radio-sm"></span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        <fieldset class="fieldset max-w-md"><legend class="fieldset-legend flex w-full justify-between"><span>Speaking pace</span><span>{{ number_format($voice_speed, 2) }}×</span></legend><input wire:model.live="voice_speed" type="range" min="0.75" max="1.2" step="0.05" class="range range-primary range-sm"><div class="flex justify-between px-1 text-xs text-base-content/40"><span>Measured</span><span>Energetic</span></div></fieldset>
                    </section>
                @else
                    <section wire:key="review" class="max-w-2xl space-y-7">
                        <div><h2 class="text-2xl font-semibold text-slate-100">Ready for the first lesson?</h2><p class="mt-1 text-sm text-slate-500">The narrator can stay backstage until you finish testing the voice.</p></div>
                        <div class="flex items-center gap-5 border-y border-slate-800 py-6">@if($portrait)<img src="{{ $portrait->temporaryUrl() }}" alt="" class="h-24 w-24 rounded-2xl object-cover">@endif<div><p class="text-xl font-semibold text-slate-100">{{ $name }}</p><p class="text-sm text-amber-300">{{ $avatar_title ?: 'Narrator' }} · {{ ucfirst($subject === 'all' ? 'all subjects' : $subject) }}</p><p class="mt-2 text-sm text-slate-500">{{ $description ?: 'No description added.' }}</p>@if($intro_video)<span class="badge badge-success badge-outline mt-3">Introduction video ready</span>@else<span class="badge badge-ghost mt-3">No introduction video</span>@endif</div></div>
                        <label class="card flex cursor-pointer flex-row items-start gap-3 p-4"><input wire:model="is_active" type="checkbox" class="checkbox checkbox-primary checkbox-sm mt-0.5"><span><span class="block text-sm font-semibold text-base-content">Make available to teachers now</span><span class="mt-0.5 block text-xs text-base-content/50">Leave this off if you want to test and refine the narrator first.</span></span></label>
                    </section>
                @endif

                <div class="mt-10 flex items-center justify-between border-t border-slate-800 pt-5">
                    <button type="button" wire:click="previousStep" @disabled($step === 1) class="btn btn-ghost disabled:invisible">← Previous</button>
                    @if($step < 4)<button type="button" wire:click="nextStep" class="btn btn-primary">Continue <span aria-hidden="true">→</span></button>@else<button type="submit" wire:loading.attr="disabled" class="btn btn-primary"><span wire:loading.remove wire:target="createNarrator">Create narrator</span><span wire:loading wire:target="createNarrator" class="loading loading-spinner loading-sm"></span><span wire:loading wire:target="createNarrator">Creating…</span></button>@endif
                </div>
            </form>
        </main>

        <aside class="hidden border-l border-slate-800 pl-8 lg:block"><div class="sticky top-8"><p class="text-xs font-semibold uppercase tracking-widest text-slate-600">Cast note</p><blockquote class="mt-4 font-history text-2xl leading-snug text-slate-300">“A narrator is the thread students follow through the story.”</blockquote><div class="mt-8 h-px bg-gradient-to-r from-amber-400/60 to-transparent"></div><p class="mt-5 text-sm leading-6 text-slate-500">New narrators begin inactive by default. Preview the portrait and voice in the Studio before introducing them to teachers.</p></div></aside>
    </div>
</div>
