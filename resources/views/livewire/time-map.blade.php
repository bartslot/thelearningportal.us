<div>
    @forelse ($stories as $story)
        <article wire:key="story-{{ $story['id'] }}">{{ $story['title'] }}</article>
    @empty
        <p>No stories here yet</p>
    @endforelse
</div>
