{{-- Language picker with flags.

     A native <select> cannot render an SVG per option, so this is a listbox: button + dropdown,
     keyboard driven (Enter/Space to open, arrows to move, Enter to pick, Esc to close), with the
     selected language announced through aria-activedescendant.

     Usage inside a Livewire component:
         <x-ui.language-picker model="locale" :current="$locale" />
     `model` is the Livewire property to write; `current` is its value. --}}
@props([
    'model',
    'current' => 'en',
    'label' => null,
])

@php
    use App\Support\Locales;

    $options = Locales::options();
    $currentIndex = array_search($current, array_column($options, 'code'), true) ?: 0;
@endphp

<div
    x-data="{
        open: false,
        active: {{ (int) $currentIndex }},
        options: @js(array_column($options, 'code')),
        toggle() { this.open = !this.open; if (this.open) this.$nextTick(() => this.$refs.list?.focus()) },
        close() { this.open = false; this.$refs.button?.focus() },
        move(delta) {
            this.active = (this.active + delta + this.options.length) % this.options.length
        },
        pick(index) {
            this.active = index
            this.open = false
            $wire.set('{{ $model }}', this.options[index])
            this.$refs.button?.focus()
        },
    }"
    x-on:keydown.escape.stop="if (open) close()"
    {{ $attributes->merge(['class' => 'relative w-full max-w-xs']) }}
>
    <button
        type="button"
        x-ref="button"
        @click="toggle()"
        @keydown.arrow-down.prevent="open = true; move(1)"
        @keydown.arrow-up.prevent="open = true; move(-1)"
        :aria-expanded="open"
        aria-haspopup="listbox"
        @if ($label) aria-label="{{ $label }}" @endif
        class="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-700 bg-slate-900 px-3 py-2.5 text-left text-sm text-slate-100 transition hover:border-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
    >
        <span class="flex items-center gap-2.5">
            <span class="overflow-hidden rounded-[3px] ring-1 ring-white/15">
                <x-dynamic-component :component="'flags.'.Locales::flag($current)" class="block h-4 w-6" />
            </span>
            <span>{{ Locales::name($current) }}</span>
        </span>
        <svg class="h-4 w-4 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15 12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
        </svg>
    </button>

    <ul
        x-show="open"
        x-cloak
        x-ref="list"
        tabindex="-1"
        role="listbox"
        @if ($label) aria-label="{{ $label }}" @endif
        @click.outside="open = false"
        @keydown.arrow-down.prevent="move(1)"
        @keydown.arrow-up.prevent="move(-1)"
        @keydown.home.prevent="active = 0"
        @keydown.end.prevent="active = options.length - 1"
        @keydown.enter.prevent="pick(active)"
        @keydown.space.prevent="pick(active)"
        @keydown.tab="open = false"
        x-transition:enter="transition duration-150 ease-[cubic-bezier(0.215,0.61,0.355,1)]"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:leave="transition duration-100 ease-[cubic-bezier(0.55,0.055,0.675,0.19)]"
        x-transition:leave-end="opacity-0"
        class="absolute z-50 mt-2 w-full overflow-hidden rounded-lg border border-slate-700 bg-slate-900 py-1 shadow-2xl focus:outline-none"
    >
        @foreach ($options as $index => $option)
            <li
                role="option"
                id="language-option-{{ $option['code'] }}"
                :aria-selected="active === {{ $index }}"
                @click="pick({{ $index }})"
                @mouseenter="active = {{ $index }}"
                :class="active === {{ $index }} ? 'bg-slate-800 text-white' : 'text-slate-300'"
                class="flex cursor-pointer items-center gap-2.5 px-3 py-2 text-sm transition-colors"
            >
                <span class="overflow-hidden rounded-[3px] ring-1 ring-white/15">
                    <x-dynamic-component :component="'flags.'.$option['flag']" class="block h-4 w-6" />
                </span>
                <span>{{ $option['name'] }}</span>
                @if ($option['code'] === $current)
                    <svg class="ml-auto h-4 w-4 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                @endif
            </li>
        @endforeach
    </ul>
</div>
