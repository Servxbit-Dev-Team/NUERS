<?php

return [
    'node_binary' => env('NUERS_PDF_NODE', 'node'),
    // Linux hosting can set an explicit reviewed Chromium/Chrome executable.
    'chrome_binary' => env('NUERS_PDF_CHROME'),
    'timeout_seconds' => 30,
];
