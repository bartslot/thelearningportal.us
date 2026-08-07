<?php

declare(strict_types=1);

return [
    'temporary_file_upload' => [
        // Narrator introduction videos pass through Livewire's temporary endpoint
        // before component validation, so this must match the advertised limit.
        'rules' => ['required', 'file', 'max:51200'],
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'webm', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 10,
        'cleanup' => true,
    ],
];
