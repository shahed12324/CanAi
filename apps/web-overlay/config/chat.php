<?php

return [
    // Upper bounds per answer (also limited by each model's own max_output / context_window).
    'max_output' => (int) env('CHAT_MAX_OUTPUT', 2048),
    'max_input' => (int) env('CHAT_MAX_INPUT', 24000),
    // Text-file attachments.
    'attach_max_files' => 3,
    'attach_max_kb' => 200,          // text files
    'attach_image_max_kb' => 5000,   // pictures (read by the AI first, see App\Vision\ImageAttachment)
    'attach_extensions' => ['txt', 'md', 'csv', 'json', 'log', 'xml', 'yml', 'yaml', 'html', 'css', 'js', 'php', 'py', 'sql', 'png', 'jpg', 'jpeg', 'webp'],
];
