{{-- 419 is a session that quietly timed out. Saying "page expired" to a teacher mid-lesson explains
     nothing; saying they were signed out for a while does. --}}
@include('errors.layout', [
    'code' => 419,
    'title' => __('Your session timed out'),
    'message' => __('You were away for a while, so we signed you out to keep the account safe. Sign in and carry on.'),
])
