<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">{{ __('Classes') }}</h1>
            <p class="text-sm opacity-60">{{ __('A class is a group of children. Manage the roster and assign lessons here.') }}</p>
        </div>
    </div>

    @if ($notice)
        <div class="alert alert-success py-2 text-sm">{{ $notice }}</div>
    @endif

    {{-- Create --}}
    <form wire:submit="create" class="card bg-base-200 p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="form-control flex-1">
                <span class="label-text text-xs opacity-70">{{ __('Class name') }}</span>
                <input type="text" wire:model="newName" class="input input-bordered input-sm"
                       placeholder="{{ __('e.g. Group 7A') }}" />
                @error('newName') <span class="mt-1 text-xs text-error">{{ $message }}</span> @enderror
            </label>
            <label class="form-control w-full sm:w-32">
                <span class="label-text text-xs opacity-70">{{ __('Grade') }}</span>
                <input type="text" wire:model="newGrade" class="input input-bordered input-sm" placeholder="7" />
            </label>
            <button type="submit" class="btn btn-primary btn-sm">
                <span wire:loading.remove wire:target="create">{{ __('Add class') }}</span>
                <span wire:loading wire:target="create" class="loading loading-spinner loading-xs"></span>
            </button>
        </div>
    </form>

    {{-- List --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($this->classes as $class)
            <div class="card bg-base-200 p-4" wire:key="class-{{ $class->id }}">
                <div class="flex items-start justify-between gap-2">
                    <a href="{{ route('teacher.classes.manage', $class) }}" class="min-w-0">
                        <div class="truncate font-semibold hover:text-primary">{{ $class->name }}</div>
                        <div class="text-xs opacity-60">
                            {{ $class->grade_level ? __('Grade :g', ['g' => $class->grade_level]).' · ' : '' }}
                            {{ trans_choice(':count child|:count children', $class->members_count, ['count' => $class->members_count]) }}
                            · {{ trans_choice(':count lesson|:count lessons', $class->lessons_count, ['count' => $class->lessons_count]) }}
                        </div>
                    </a>
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-ghost btn-xs">⋯</div>
                        <ul tabindex="0" class="dropdown-content menu menu-sm z-10 w-40 rounded-box bg-base-100 p-2 shadow">
                            <li><a href="{{ route('teacher.classes.manage', $class) }}">{{ __('Manage') }}</a></li>
                            <li>
                                <button type="button" class="text-error"
                                        wire:click="delete({{ $class->id }})"
                                        wire:confirm="{{ __('Delete this class? Its roster is removed; past results are kept anonymously.') }}">
                                    {{ __('Delete') }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="mt-3 flex items-center gap-2">
                    <span class="badge badge-ghost badge-sm font-mono">{{ $class->join_code }}</span>
                    @unless ($class->is_active)<span class="badge badge-sm badge-warning">{{ __('archived') }}</span>@endunless
                    <a href="{{ route('teacher.classes.manage', $class) }}" class="btn btn-ghost btn-xs ml-auto">{{ __('Manage →') }}</a>
                </div>
            </div>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-base-300 p-8 text-center text-sm opacity-60">
                {{ __('No classes yet. Add your first class above.') }}
            </div>
        @endforelse
    </div>
</div>
