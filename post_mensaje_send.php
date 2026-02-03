<?php
$body=json_encode(['tokenchat'=>'dasghbyiu21s445f3s3dfsdf54s53f12d1f854fse','contenido'=>'Mensaje de prueba desde script']);
$opts=['http'=>['method'=>'POST','header'=>'Content-Type: application/json\r\nAuthorization: Bearer a87c71ec0fb12657b600086626ccc22d6a514d10f42f0c4d19ab55b0e70d\r\n','content'=>$body,'ignore_errors'=>true]];
$ctx=stream_context_create($opts);
$res=file_get_contents('http://127.0.0.1:8000/api/mensaje?tokenusuario=a87c71ec0fb12657b600086626ccc22d6a514d10f42f0c4d19ab55b0e70d&tokenchat=dasghbyiu21s445f3s3dfsdf54s53f12d1f854fse', false, $ctx);
if(isset($http_response_header)) echo implode('\n', $http_response_header)."\n";
var_dump($res);
if ($res !== false) {
    echo "LEN:" . strlen($res) . "\n";
    echo $res . "\n";
} else {
    echo "NO BODY (file_get_contents returned false)\n";
}
