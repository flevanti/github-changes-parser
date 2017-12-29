<?php
/**
 * Created by PhpStorm.
 * User: francescolevanti
 * Date: 28/12/2017
 * Time: 14:25
 */

class GithubNotifier {

  protected $payload;
  protected $repoId;
  protected $headers;
  protected $secret_ok = false;
  protected $configMaxLen = 10000;
  protected $config = ["NOTIFY" => [], "EXCEPTIONS" => []];
  protected $configMetadata = [];
  protected $projectRootKeyword = "[PROOT]";

  public $lastError = "";
  public $verbose = PHP_SAPI == 'cli';

  function __construct($verbose = null) {
    if (is_bool($verbose)) {
      $this->verbose = $verbose;
    }
    $this->e("WELCOME TO GITHUB NOTIFIER");
  }

  protected function loadConfig($config) {
    $this->e("Request to load config");

    if (strtolower(substr($config, 0, 4) == "http")) {
      $this->e("Config appears to be a http url");
      $config = file_get_contents($config, false, null, 0, $this->configMaxLen + 500);
      if (!$config) {
        $this->lastError = "Unable to read config file from url";
        $this->e($this->lastError);
        return false;
      }
      $this->e("Config retrieved from url");
    }

    if (strlen($config) > $this->configMaxLen) {
      $this->lastError = "Config file too big, max allowed size is " . $this->configMaxLen;
      $this->e($this->lastError);
      return false;
    }

    $config = explode("\n", $config);

    $this->e("Config file is " . count($config) . " lines");

    foreach ($config as $line_num => $line) {
      $this->e("-------------------------------------------------");
      $this->e("Processing line number #" . ($line_num + 1) . "    " . $line);
      $line = trim($line);
      if (empty($line)) {
        $this->e("Line is empty, skipped");
        continue;
      }
      $first_char = substr($line, 0, 1);
      $this->e("Line identifier: [$first_char]");
      $line = trim(substr($line, 1));
      if (empty($line)) {
        $this->e("Line is empty after the line identifier, skipped");
        continue;
      }
      //line commented
      if ($first_char == ";" || $first_char == "#") {
        $this->e("Line is commented, skipped");
        continue;
      }
      //config metadata
      if ($first_char == "@") {
        $this->e("Line is metadata");
        $line = explode("=", $line, 2);
        if (count($line) != 2) {
          $this->e("Metadata line parsing error, skipped");
          continue;
        }
        $line[0] = trim($line[0]);
        $line[1] = trim($line[1]);
        if (empty($line[0])) {
          $this->e("Metadata line missing key, skipped");
          continue;
        }
        $this->configMetadata[trim($line[0])] = trim($line[1]);
        continue;
      }
      //actual rule
      if ($first_char == "-") {
        if (substr($line, 0, 1) == "/") {
          $line = $this->projectRootKeyword . $line;
        }
        $this->config['NOTIFY'][] = $line;
        $this->e("RULE: " . $line);
        continue;
      }
      //exception
      if ($first_char == "!") {
        if (substr($line, 0, 1) == "/") {
          $line = $this->projectRootKeyword . $line;
        }
        $this->config['EXCEPTIONS'][] = $line;
        $this->e("RULE EXCEPTION: " . $line);
        continue;
      }

      //ooops your line has been ignored.....
      $this->e("Line has been ignored....");

    } //end foreach line in config file

    $this->e("--------------------------------");
    $this->e("Configuration loaded");


    return true;

  }


  protected function checkSecret($payload, $signature) {
    $this->e("Check secret key");

    if (!isset($this->configMetadata['secret']) || empty($this->configMetadata['secret'])) {
      $this->lastError = "Unable to find secret key in config";
      $this->e($this->lastError);
      return false;
    }


    // Split signature into algorithm and hash
    list($algo, $hash) = explode('=', $signature, 2);

    // Calculate hash based on payload and the secret
    $payloadHash = hash_hmac($algo, $payload, $this->configMetadata['secret']);

    // Check if hashes are equivalent
    if ($hash !== $payloadHash) {
      $this->lastError = "Secret check failed";
      $this->e($this->lastError);
      return false;
    }
    return true;
  }

  protected function loadJsonPayload($payload) {
    $this->e("Loading payload");
    $payload = json_decode($payload, true);
    if (!$payload || json_last_error() != JSON_ERROR_NONE) {
      $this->lastError = "Payload json decode error";
      $this->e($this->lastError);
      return false;
    }

    if (!isset($payload['repository']['full_name']) || empty($payload['repository']['full_name'])) {
      $this->lastError = "Unable to find repository full name in payload";
      $this->e($this->lastError);

      //todo restore the return false before prod.
      //return false;
    }

    $this->repoId = $payload['repository']['full_name'];

    $this->e("Payload loaded correctly");

    return true;
  }


  public function check($payload, $signature, $config) {
    if (!$this->loadConfig($config)) {
      return false;
    }

    if (!$this->checkSecret($payload, $signature)) {
      return false;
    }

    if ($this->loadJsonPayload($payload)) {
      return false;
    }


  }


  protected function e($txt, $nl = PHP_EOL) {
    if (!$this->verbose) {
      return;
    }
    echo date("H:i:s     ") . $txt . $nl;
  }


}