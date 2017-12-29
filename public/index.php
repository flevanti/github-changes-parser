<?php
/**
 * Created by PhpStorm.
 * User: francescolevanti
 * Date: 29/12/2017
 * Time: 13:16
 */

mail("frenke@frenke.it",
  "git payload received",
  json_encode($_POST) . "\n\n\n" .
  "SERVER:\n" . json_encode($_SERVER) . "\n\n\n" .
  "RAW INPUT:\n" . file_get_contents('php://input') . "\n\n\n"
);

