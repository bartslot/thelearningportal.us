@include('errors.layout', [
    'code' => 500,
    'title' => __('Something went wrong at our end'),
    'message' => __('This one is our fault, not yours. It has been logged and we are looking at it.'),
])
