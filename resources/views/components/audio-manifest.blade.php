{{-- The lesson soundtrack, handed to the browser: the music bed's tracks and the interface's
     sound effects. Included by every page that can make a sound (the player, and the wizard whose
     preview plays the same lesson), so both are working from the same list. --}}
@once
    <script>window.LP_AUDIO = @js(\App\Support\AudioManifest::forBrowser());</script>
@endonce
