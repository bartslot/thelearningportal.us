{{-- The app's one card row.

     Use it anywhere a horizontal shelf of cards is needed — the landing shelves, the teacher's
     lesson shelves — so the gesture is identical everywhere: drag with the pointer, swipe on
     touch, arrows on hover. Woken up by resources/js/carousel.js, where the options live.

     This renders the row and nothing else, so a page can frame it however it likes (the landing
     shelves clip it in a rounded box and fade one edge). Wrap it in a `relative overflow-hidden`
     box unless you want cards to spill past the edge.

     Each child must carry `carousel-cell` and a width, plus `mx-2` for the gap between cards —
     the cells are positioned, so a `gap` on the row would do nothing:

         <div class="relative overflow-hidden rounded-[2rem]">
             <x-carousel aria-label="Lessons">
                 <a class="carousel-cell mx-2 w-52">…</a>
             </x-carousel>
         </div>
--}}
<div {{ $attributes->merge(['class' => 'js-disney-carousel']) }}>
    {{ $slot }}
</div>
