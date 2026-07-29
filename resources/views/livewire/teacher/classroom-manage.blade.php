<div class="space-y-6">
    <div class="flex items-center gap-2 text-sm opacity-60">
        <a href="{{ route('teacher.classes.index') }}" class="hover:text-primary">{{ __('Classes') }}</a>
        <span>/</span>
        <span class="text-base-content">{{ $classroom->name }}</span>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Details --}}
        <form wire:submit="saveDetails" class="card bg-base-200 p-4 lg:col-span-1">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide opacity-70">{{ __('Class details') }}</h2>

            <label class="form-control mb-2">
                <span class="label-text text-xs opacity-70">{{ __('Name') }}</span>
                <input type="text" wire:model="name" class="input input-bordered input-sm" />
                @error('name') <span class="mt-1 text-xs text-error">{{ $message }}</span> @enderror
            </label>

            <label class="form-control mb-2">
                <span class="label-text text-xs opacity-70">{{ __('Grade') }}</span>
                <input type="text" wire:model="grade" class="input input-bordered input-sm" />
            </label>

            <label class="label mb-2 cursor-pointer justify-start gap-2">
                <input type="checkbox" wire:model="isActive" class="toggle toggle-sm toggle-primary" />
                <span class="label-text text-sm">{{ __('Active') }}</span>
            </label>

            <div class="mb-3 flex items-center gap-2 text-xs opacity-70">
                {{ __('Join code') }}
                <span class="badge badge-ghost font-mono">{{ $classroom->join_code }}</span>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </form>

        {{-- Roster --}}
        <div class="card bg-base-200 p-4 lg:col-span-2">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide opacity-70">
                {{ __('Roster') }} <span class="opacity-50">({{ count($this->members) }})</span>
            </h2>

            <form wire:submit="addMember" class="mb-3 flex gap-2">
                <input type="text" wire:model="newMember" class="input input-bordered input-sm flex-1"
                       placeholder="{{ __('Add a child, e.g. Emma V.') }}" />
                <button type="submit" class="btn btn-primary btn-sm">{{ __('Add') }}</button>
            </form>
            @error('newMember') <span class="-mt-2 mb-2 block text-xs text-error">{{ $message }}</span> @enderror

            <div class="divide-y divide-base-300">
                @forelse ($this->members as $member)
                    <div class="flex items-center gap-2 py-2" wire:key="member-{{ $member->id }}">
                        @if ($editingMemberId === $member->id)
                            <input type="text" wire:model="editingMemberName" wire:keydown.enter="saveMember"
                                   class="input input-bordered input-xs flex-1" />
                            <button type="button" wire:click="saveMember" class="btn btn-primary btn-xs">{{ __('Save') }}</button>
                            <button type="button" wire:click="cancelEdit" class="btn btn-ghost btn-xs">{{ __('Cancel') }}</button>
                            @error('editingMemberName') <span class="text-xs text-error">{{ $message }}</span> @enderror
                        @else
                            <span class="flex-1 text-sm">{{ $member->display_name }}</span>
                            <button type="button" wire:click="startEdit({{ $member->id }})" class="btn btn-ghost btn-xs">{{ __('Rename') }}</button>
                            <button type="button" wire:click="removeMember({{ $member->id }})"
                                    wire:confirm="{{ __('Remove :name from this class?', ['name' => $member->display_name]) }}"
                                    class="btn btn-ghost btn-xs text-error">✕</button>
                        @endif
                    </div>
                @empty
                    <p class="py-4 text-center text-sm opacity-60">{{ __('No children yet. Add them above, or they appear when they join with the class code.') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Assigned lessons --}}
    <div class="card bg-base-200 p-4">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide opacity-70">{{ __('Assigned lessons') }}</h2>

        @forelse ($this->assignedLessons as $lesson)
            <div class="flex items-center gap-2 border-b border-base-300 py-2 last:border-0" wire:key="lesson-{{ $lesson->id }}">
                <span class="flex-1 truncate text-sm">{{ $lesson->title ?: $lesson->topic }}</span>
                <a href="{{ route('teacher.lessons.results', $lesson) }}" class="btn btn-ghost btn-xs">{{ __('Results') }}</a>
                <button type="button" wire:click="unassignLesson({{ $lesson->id }})" class="btn btn-ghost btn-xs text-error">✕</button>
            </div>
        @empty
            <p class="py-2 text-sm opacity-60">{{ __('No lessons assigned yet.') }}</p>
        @endforelse

        @if ($this->assignableLessons->isNotEmpty())
            <div class="mt-3 flex items-center gap-2">
                <select class="select select-bordered select-sm flex-1"
                        wire:change="assignLesson($event.target.value)">
                    <option value="">{{ __('Assign a lesson…') }}</option>
                    @foreach ($this->assignableLessons as $lesson)
                        <option value="{{ $lesson->id }}">{{ $lesson->title ?: $lesson->topic }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>
</div>
