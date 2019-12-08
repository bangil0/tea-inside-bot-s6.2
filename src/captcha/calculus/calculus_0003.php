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
        return factorial((int)$extra) === (int)trim($answer);
    }

    return false;
}

$timeout = 300; // 5 minutes.
$extra = rand(0, 12);
$up1 = rand(10, 300);
$up2 = rand(10, 300);
$mul = rand(2, 5);
$lw1 = $up1 - $mul;
$lw2 = $up2 - 1;
$latex = "\int_{".$up2."}^{".$lw1."} \int_{".$up2."}^{".$lw2."} \int_{0}^{\infty} (x^{".$extra."} e^{-x}) dx dy dz";
$extra *= $mul;

$hash = md5("=".factorial($extra));
is_dir("/tmp/telegram/calculus_lock/") or mkdir("/tmp/telegram/calculus_lock/");
file_put_contents("/tmp/telegram/calculus_lock/".$hash, time());

$msg = "<b>Please solve this problem to make sure you are a human or you will be kicked in 5 minutes. Reply your answer to this message!</b>\n\n".
    "Integrate the following expression:\n<code>".htmlspecialchars($latex, ENT_QUOTES, "UTF-8")."</code>";

$photo = "https://api.teainside.org/latex_x.php?d=300&exp=".urlencode($latex);

return [
    "timeout" => 300,
    "extra" => $extra,
    "msg" => $msg,
    "photo" => $photo,
    "banned_hash" => $hash
];
