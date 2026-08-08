@include('errors.layout', [
    'code' => 404,
    'title' => __('That page does not exist'),
    'message' => __('The link may be old, or the lesson may have been removed.'),
])
