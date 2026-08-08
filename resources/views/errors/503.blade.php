@include('errors.layout', [
    'code' => 503,
    'title' => __('Back in a moment'),
    'message' => __('We are updating the site. This usually takes less than a minute.'),
])
