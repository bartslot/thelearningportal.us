@props([
    'message',
    'animate' => false,
])

<div {{ $attributes->class(['chat chat-start']) }}>
    <div class="chat-header mb-1 text-xs text-base-content/55">
        {{ __('Lesson assistant') }}
    </div>
    <div class="chat-bubble p-3 max-w-[92%] bg-base-300 text-sm leading-5 text-base-content shadow-sm sm:max-w-[82%] sm:text-base">
        @if ($animate)
            <span
                x-data="{
                    characters: Array.from(@js($message)),
                    visibleText: '',
                    characterIndex: 0,
                    finished: false,
                    timer: null,
                    init() {
                        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                            this.visibleText = this.characters.join('');
                            this.finished = true;
                            return;
                        }

                        const typeNextCharacter = () => {
                            this.visibleText += this.characters[this.characterIndex] ?? '';
                            this.characterIndex += 1;

                            if (this.characterIndex >= this.characters.length) {
                                this.finished = true;
                                return;
                            }

                            const previousCharacter = this.characters[this.characterIndex - 1];
                            const delay = previousCharacter === '\n' ? 110 : 14;
                            this.timer = window.setTimeout(typeNextCharacter, delay);
                        };

                        this.timer = window.setTimeout(typeNextCharacter, 180);
                    },
                    destroy() {
                        window.clearTimeout(this.timer);
                    },
                }"
            >
                <span aria-hidden="true" class="whitespace-pre-line" x-text="visibleText"></span><span
                    x-show="! finished"
                    aria-hidden="true"
                    class="ml-0.5 inline-block h-4 w-px translate-y-0.5 animate-pulse bg-primary"
                ></span>
                <span class="sr-only">{{ $message }}</span>
            </span>
        @else
            {{ $message }}
        @endif
    </div>
</div>
