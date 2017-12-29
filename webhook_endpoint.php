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
$payload = trim(file_get_contents("payload_example"));
$result = $obj->check($payload, "sha1=9d71fc6c158bb29cd18773591644ee1a2fcc0efd", $conf);