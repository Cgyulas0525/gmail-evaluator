<?php

return [
    'enabled' => env('AUTO_REPLY_ENABLED', false),

    'categories' => array_filter(array_map(
        'trim',
        explode(',', env('AUTO_REPLY_CATEGORIES', 'billing,work'))
    )),
];
