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
$result = $obj->check($payload, "sha1=45778be36d0926f5dfce2264041dcd2cfee8beec", $conf);