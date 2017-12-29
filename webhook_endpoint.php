<?php
/**
 * Created by PhpStorm.
 * User: francescolevanti
 * Date: 29/12/2017
 * Time: 11:11
 */

include("GithubNotifier.php");
$obj = new GithubNotifier();
$conf = file_get_contents("config_example");
$payload = file_get_contents("payload_example2");
// Check if the payload is json or urlencoded.
if (strpos($payload, 'payload=') === 0) {
  $payload = substr(urldecode($payload), 8);
}
$result = $obj->check($payload, "sha1=79b574edf629987a1922387c0fe2e82f38b0b702", $conf);