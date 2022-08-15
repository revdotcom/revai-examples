<?php
return [
    'rev' => [
      'token' => getenv('REVAI_ACCESS_TOKEN'),
      'callback' => getenv('CALLBACK_PREFIX') . '/hook',
    ],
    'mongo' => [
      'uri' => getenv('MONGODB_URI')
    ]
];
