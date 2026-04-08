<div class="space-y-6"
    @if(in_array($avatar->sprite_status?->value, ['pending', 'processing']))
        wire:poll.3s
    @endif
>

    {{-- Current portrait --}}
    <div class="flex items-start gap-6">
        <img
            src="{{ $avatar->portraitUrl() }}"
            alt="{{ $avatar->name }}"
            class="h-32 w-32 rounded-2xl object-cover border-2 border-amber-500/30"
        >
        <div class="space-y-1">
            <p class="text-sm font-medium text-slate-200">Current portrait</p>
            <p class="text-xs text-slate-500">512 × 512 px recommended. Clear face, neutral background.</p>
            <div class="flex items-center gap-2 mt-2">
                <span class="text-xs px-2 py-0.5 rounded-full border
                    {{ match($avatar->sprite_status?->value) {
                        'ready'      => 'bg-emerald-950/60 border-emerald-700 text-emerald-300',
                        'processing' => 'bg-amber-950/60 border-amber-700 text-amber-300',
                        'failed'     => 'bg-rose-950/60 border-rose-700 text-rose-300',
                        default      => 'bg-slate-900 border-slate-700 text-slate-500',
                    } }}">
                    Sprites: {{ ucfirst($avatar->sprite_status?->value ?? 'pending') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Upload form --}}
    <form wire:submit="uploadPortrait" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">Upload new portrait</label>
            <input
                type="file"
                wire:model="portraitUpload"
                accept="image/*"
                class="block w-full text-sm text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-amber-500/10 file:text-amber-400 hover:file:bg-amber-500/20"
            >
            @error('portraitUpload') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
        </div>

        <button
            type="submit"
            wire:loading.attr="disabled"
            class="px-4 py-2 rounded-xl bg-amber-500 text-slate-900 text-sm font-semibold hover:bg-amber-400 disabled:opacity-50 transition-colors"
        >
            <span wire:loading.remove wire:target="uploadPortrait">Upload & Process</span>
            <span wire:loading wire:target="uploadPortrait">Processing…</span>
        </button>
    </form>

    {{-- Animated preview (shown when sprites are ready) --}}
    @if($avatar->spritesReady())
        <div class="space-y-3">
            <p class="text-sm font-medium text-slate-300">Live preview</p>
            <div
                id="avatar-preview"
                data-portrait="{{ Storage::disk('public')->url($avatar->portrait_path) }}"
                data-landmarks="{{ json_encode($avatar->landmarks_json) }}"
                data-sprites="{{ json_encode([
                    'mouth' => [
                        Storage::disk('public')->url("avatars/{$avatar->id}/sprites/mouth_0.png"),
                        Storage::disk('public')->url("avatars/{$avatar->id}/sprites/mouth_1.png"),
                        Storage::disk('public')->url("avatars/{$avatar->id}/sprites/mouth_2.png"),
                        Storage::disk('public')->url("avatars/{$avatar->id}/sprites/mouth_3.png"),
                    ],
                    'left_eye_open'    => Storage::disk('public')->url("avatars/{$avatar->id}/sprites/eye_left_open.png"),
                    'left_eye_closed'  => Storage::disk('public')->url("avatars/{$avatar->id}/sprites/eye_left_closed.png"),
                    'right_eye_open'   => Storage::disk('public')->url("avatars/{$avatar->id}/sprites/eye_right_open.png"),
                    'right_eye_closed' => Storage::disk('public')->url("avatars/{$avatar->id}/sprites/eye_right_closed.png"),
                ]) }}"
            >
                <canvas id="avatar-canvas" width="512" height="512" class="w-full max-w-sm rounded-2xl border border-slate-700"></canvas>
            </div>
            <p class="text-xs text-slate-500">Preview uses demo amplitude. Connect audio at playback.</p>
        </div>
    @endif
</div>
