{{-- The message Laravel was given at the abort() site is far more useful than a generic line:
     "This demo can only open the lesson it was given" tells a visitor exactly where they are. --}}
@include('errors.layout', [
    'code' => 403,
    'title' => __('You cannot open that page'),
    'message' => $exception?->getMessage() ?: __('Your account does not have access to this page.'),
])
