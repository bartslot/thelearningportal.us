{{-- Handout story pages — one block per narration scene, order asc --}}
<div class="page-break"></div>
<h2 class="section-title">{{ __('The story') }}</h2>

@foreach ($scenes as $scene)
    <div class="scene">
        <div class="scene-heading">
            {{ __('Scene') }} {{ $loop->iteration }}@if ($scene->year) &middot; {{ $scene->year }}@endif @if ($scene->location) &middot; {{ $scene->location }}@endif
        </div>
        <div class="scene-text">{!! nl2br(e($scene->script_segment)) !!}</div>
    </div>
@endforeach
