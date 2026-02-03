<?php
$body = json_encode(['tokenchat' => 'tokenejemplo12345']);
$opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'ignore_errors' => true]];
$ctx = stream_context_create($opts);
$res = file_get_contents('http://127.0.0.1:8000/api/chat/privado/salir?tokenusuario=a87c71ec0fb12657b600086626ccc22d6a514d10f42f0c4d19ab55b0e70d', false, $ctx);
if (isset($http_response_header)) echo implode('\n', $http_response_header)."\n";
var_dump($res);
if ($res !== false) {
    echo "LEN:" . strlen($res) . "\n";
    echo $res . "\n";
} else {
    echo "NO BODY (file_get_contents returned false)\n";
}
