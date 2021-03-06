<?php

function factorial($number)
{ 
    $factorial = 1; 
    for ($i = 1; $i <= $number; $i++){ 
      $factorial = $factorial * $i; 
    } 
    return $factorial; 
}

if (isset($checkAnswer)) {
    if (isset($extra, $answer)) {
        return (string)$extra === trim($answer);
    }

    return false;
}

$timeout = 300; // 5 minutes.
$extra = rand(0, 12);
$latex = "\\int_{0}^{\\infty} t^{".$extra."} e^{-t} dt";

$extra = factorial($extra);
$hash = md5("=".$extra);
is_dir("/tmp/telegram/calculus_lock/") or mkdir("/tmp/telegram/calculus_lock/");
file_put_contents("/tmp/telegram/calculus_lock/".$hash, time());

$msg = "<b>Please solve this captcha problem to make sure you are a human or you will be kicked in 10 minutes. Reply your answer to this message!</b>\n\nIntegrate the following expression!";

$photo = "https://api.teainside.org/latex_x.php?border=200&d=300&exp=".urlencode($latex);

return [
    "timeout" => 600,
    "extra" => $extra,
    "msg" => $msg,
    "photo" => $photo,
    "banned_hash" => $hash
];
