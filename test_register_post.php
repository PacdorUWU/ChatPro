<?php
$data = [
    'email' => 'docstest2@example.com',
    'password' => 'secret',
    'nombre' => 'Doc Test2'
];
$options = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($data),
        'ignore_errors' => true,
    ],
];
$context = stream_context_create($options);
$result = file_get_contents('http://127.0.0.1:8000/api/register', false, $context);
echo $result . PHP_EOL;
