<?php
require_once __DIR__ . '/../vendor/autoload.php'; // Autoload files using Composer autoload

//specify 'file://' so that the parser knows it is a local file
//http is also available if your config is remote
//or you can read the config file and pass the whole content as a string
$config_file = "file://" . __DIR__ . "/../example_data/config_example";
$payload = file_get_contents('php://input');
if (empty($payload)) {
  echo "Loading local payload\n";
  $payload = file_get_contents(__DIR__ . "/../example_data/payload_example");
  $signature = "sha1=5f5707905b0d87045b89e58332c380e1dfcd90bb";
}
else {
  if (!$_SERVER['CONTENT_TYPE'] == "application/json") {
    // do something here, the check is not running!
    return;
  }
  $signature = $_SERVER["HTTP_X_HUB_SIGNATURE"] ?: "";
}

$obj = new flevanti\GithubChangesParser\GithubChangesParser();
$ret = $obj->loadConfig($config_file);
//do something here if you want to check if config has not been loaded ($ret == false)
//or notify someone.. or do whatever you want.


//if config is not loaded check will return false.
$ret = $obj->check($payload, $signature);

if ($ret === false) {
  echo "Something went wrong, please check the output\n";
  //do something?? email.. slack...
  $email = $obj->getMetadataValue("email", false);
  if ($email) {
    //send email.....
    echo "mail sent to $email\n";
  }
  return;
}

if (!empty($ret)) {
  echo "Some files have changed and we need to notify someone\n";
  echo "This is the list of the files:\n";
  echo implode("\n", $ret);
}

echo "\n\nBye bye\n\n";