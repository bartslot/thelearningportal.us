<div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <h1 class="text-2xl font-bold text-slate-100">{{ __('Account Settings') }}</h1>
    <p class="mt-1 text-sm text-slate-400">{{ __('Manage your account preferences.') }}</p>

    @if (session('saved'))
        <div class="alert alert-success mt-4 text-sm" role="status">
            {{ __('Your settings have been saved.') }}
        </div>
    @endif

    <div class="card mt-6 border border-slate-800 bg-base-200">
        <div class="card-body gap-5">
            {{-- Read-only account info --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-xs uppercase tracking-wider text-slate-500">{{ __('Name') }}</p>
                    <p class="mt-0.5 text-slate-200">{{ auth()->user()->name }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wider text-slate-500">{{ __('Email') }}</p>
                    <p class="mt-0.5 truncate text-slate-200">{{ auth()->user()->email }}</p>
                </div>
            </div>

            <div class="border-t border-slate-800"></div>

            {{-- UI-language switcher --}}
            <label class="form-control w-full max-w-xs">
                <span class="mb-1 text-sm font-medium text-slate-300">{{ __('Language') }}</span>
                <select wire:model="locale" class="select select-bordered bg-slate-900">
                    @foreach ($this->localeOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <span class="mt-1 text-xs text-slate-500">{{ __('Changes the language of the interface.') }}</span>
                @error('locale')
                    <span class="mt-1 text-xs text-rose-400">{{ $message }}</span>
                @enderror
            </label>

            <div>
                <button type="button" wire:click="save"
                        class="btn border-0 bg-amber-500 text-slate-950 hover:bg-amber-400">
                    {{ __('Save') }}
                </button>
            </div>
        </div>
    </div>
</div>
