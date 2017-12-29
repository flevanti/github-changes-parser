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
$payload = file_get_contents("payload_example");
$result = $obj->check($payload, "sha1=5f5707905b0d87045b89e58332c380e1dfcd90bb", $conf);