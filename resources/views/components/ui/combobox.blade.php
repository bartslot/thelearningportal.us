@props([
    'options'     => [],   // array of {value, label}
    'wireModel'   => null, // Livewire property name to sync
    'placeholder' => 'Search…',
    'inputClass'  => '',
    'initialValue' => '',  // pre-selected value (for server-rendered state)
])

<div
    x-data="{
        open: false,
        search: '',
        selected: '',
        selectedLabel: '',
        options: {{ Js::from($options) }},
        get filtered() {
            if (!this.search) return this.options;
            const q = this.search.toLowerCase();
            return this.options.filter(o => o.label.toLowerCase().includes(q));
        },
        pick(option) {
            this.selected      = option.value;
            this.selectedLabel = option.label;
            this.search        = option.label;
            this.open          = false;
            this.$refs.hidden.value = option.value;
            this.$refs.hidden.dispatchEvent(new Event('input'));
        },
        clear() {
            this.selected      = '';
            this.selectedLabel = '';
            this.search        = '';
            this.$refs.hidden.value = '';
            this.$refs.hidden.dispatchEvent(new Event('input'));
        },
        init() {
            const init = this.$refs.hidden.value;
            if (init) {
                const match = this.options.find(o => o.value === init);
                if (match) {
                    this.selected      = match.value;
                    this.selectedLabel = match.label;
                    this.search        = match.label;
                }
            }
        },
    }"
    x-init="init()"
    class="relative w-full"
>
    {{-- Hidden input Livewire binds to --}}
    <input type="hidden" x-ref="hidden"
           value="{{ $initialValue }}"
           @if($wireModel) wire:model.live="{{ $wireModel }}" @endif />

    {{-- Visible search input --}}
    <div class="relative">
        <input
            type="text"
            x-model="search"
            x-on:focus="open = true"
            x-on:input="open = true"
            x-on:keydown.escape="open = false; $el.blur()"
            x-on:keydown.enter.prevent="filtered.length === 1 && pick(filtered[0])"
            x-on:blur="setTimeout(() => open = false, 150)"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            class="input input-bordered bg-slate-900 w-full pr-8 {{ $inputClass }}"
        />
        {{-- Clear button --}}
        <button
            type="button"
            x-show="search.length > 0"
            x-on:click="clear()"
            class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white"
            tabindex="-1"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- Dropdown --}}
    <ul
        x-show="open && filtered.length > 0"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="menu menu-sm bg-base-200 border border-white/10 rounded-box shadow-xl
               absolute z-50 w-full mt-1 max-h-56 overflow-y-auto"
        style="display: none"
    >
        <template x-for="option in filtered" :key="option.value">
            <li>
                <button
                    type="button"
                    x-on:mousedown.prevent="pick(option)"
                    x-text="option.label"
                    :class="option.value === selected ? 'active' : ''"
                    class="text-sm"
                ></button>
            </li>
        </template>
    </ul>
</div>
