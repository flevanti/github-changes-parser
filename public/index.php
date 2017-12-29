<?php
/**
 * Created by PhpStorm.
 * User: francescolevanti
 * Date: 29/12/2017
 * Time: 13:16
 */

$mail_to = "frenke@frenke.it";

include("../GithubNotifier.php");
$obj = new GithubNotifier();
$conf = file_get_contents("../config_example");
$payload = trim(file_get_contents('php://input'));
$result = $obj->check($payload, $_SERVER['HTTP_X_HUB_SIGNATURE'], $conf);

if ($result === false) {
  $message = "Error while processing github Notifier for repo {$obj->repoId}
  
this is the error: {$obj->lastError}

payload:
$payload\n\n\n";
  mail($mail_to, "Github notifier error - " . $obj->repoId . " - " . $obj->lastError, $message);
  return;
} //end if result is false

//all is fine...


if (is_array($result) && count($result)) {
  $message = "These files in the {$obj->repoId} repository have changed:\n\n" .
    implode("\n", $result) . "\n\n\n";
  mail($mail_to, "Github notifier - " . $obj->repoId, $message);
  return;
}

