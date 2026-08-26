<?php
require_once __DIR__ . '/../../config/app.php';
if (!payg_sample_login_enabled()) {
    http_response_code(403);
    exit('Forbidden');
}
payg_start_session();
$emp = (int)($_GET['emp'] ?? 3);
$names = [1=>['Asha Sales','Sales Executive'],2=>['Ravi Sales','Sales Executive'],3=>['Meena Manager','Sales Manager']];
[$nm,$dg] = $names[$emp] ?? ['User','Staff'];
$_SESSION['user'] = ['user_type'=> $emp===3?'manager':'employee','data'=>[
  'full_name'=>$nm,'id'=>$emp,'employee'=>['id'=>$emp,'designation'=>$dg]]];
header('Content-Type: application/json');
echo json_encode(['success'=>true,'as'=>$nm,'emp'=>$emp]);
