<?php
$body=json_encode(['tokeninvitacion'=>'cae49d58da30737bc06da97c89c07852','accion'=>'rechazar']);
$opts=['http'=>['method'=>'POST','header'=>'Content-Type: application/json\r\n','content'=>$body,'ignore_errors'=>true]];
$ctx=stream_context_create($opts);
$res=file_get_contents('http://127.0.0.1:8000/api/invitacion/responder?tokenusuario=57c7513df0a76d7ebbb586e80301656757eff5f28fae695db9d8b351bb3d', false, $ctx);
if(isset($http_response_header)) echo implode('\n', $http_response_header)."\n";
var_dump($res);
if ($res !== false) {
    echo "LEN:" . strlen($res) . "\n";
    echo $res . "\n";
} else {
    echo "NO BODY (file_get_contents returned false)\n";
}
