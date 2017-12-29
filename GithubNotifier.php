<?php
/**
 * Created by PhpStorm.
 * User: francescolevanti
 * Date: 28/12/2017
 * Time: 14:25
 */

class GithubNotifier {

  protected $payload;
  protected $payloadFiles;
  protected $repoId;
  protected $headers;
  protected $secret_ok = false;
  protected $configMaxLen = 10000;
  protected $config = ["NOTIFY" => [], "EXCEPTIONS" => [], 'METADATA' => []];
  protected $projectRootKeyword = "[PROOT]";
  protected $filesToNotify;

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
        $this->config['METADATA'][trim($line[0])] = trim($line[1]);
        continue;
      }
      //actual rule
      if ($first_char == "-") {
        if (substr($line, 0, 1) == "/") {
          $line = $this->projectRootKeyword . $line;
        }
        $this->config['RULES'][] = $line;
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

    $this->e("Configuration statistics:");
    $this->e("Config file lines: " . count($config));
    $this->e("Rules: " . count($this->config['RULES']));
    $this->e("Rules exceptions: " . count($this->config['EXCEPTIONS']));
    $this->e("Metadata: " . count($this->config['METADATA']));
    $this->e("--------------------------------");

    return true;

  }

  protected function checkSecret($payload, $signature) {
    //$payload = json_encode(json_decode($payload, true));
    $this->e("Check secret key");

    if (!isset($this->config['METADATA']['secret']) || empty($this->config['METADATA']['secret'])) {
      $this->e("Secret key not found in config file, check skipped");
      return true;
    }

    if (!function_exists("hash_algos")) {
      $this->lastError = "Hash module not installed in the current version of php";
      $this->e($this->lastError);
      return false;
    }

    // Split signature into algorithm and hash
    list($algo, $hash) = explode('=', $signature, 2);
    if (array_search($algo, hash_algos()) === false) {
      $this->lastError = "The specified algorithm is not valid";
      $this->e($this->lastError);
      return false;
    }

    // Calculate hash based on payload and the secret
    $payloadHash = hash_hmac($algo, $payload, $this->config['METADATA']['secret']);

    // Check if hashes are equivalent
    if ($hash !== $payloadHash) {
      $this->lastError = "Secret key check failed";
      $this->e($this->lastError);
      return false;
    }

    $this->e("Secret key ok");
    return true;
  }

  protected function loadPayload($payload) {
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

      return false;
    }

    $this->payload = $payload;
    $this->repoId = $payload['repository']['full_name'];
    $this->payloadFiles = [];
    foreach ($payload['commits'] as $commit) {
      $this->e("adding commit " . $commit['id']);
      $this->payloadFiles = array_merge($this->payloadFiles, $commit['added'], $commit['modified'], $commit['removed']);
    }

    $this->e("Files found in the payload: " . count($this->payloadFiles));

    //add project root prefix to files...
    $ret = array_walk($this->payloadFiles, function (&$file) {
      $file = $this->projectRootKeyword . "/" . $file;
    });

    if (!$ret) {
      $this->lastError = "Payload loading failed, unable to add project root prefix";
      $this->e("$this->lastError");
      return false;
    }

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

    if (!$this->loadPayload($payload)) {
      return false;
    }

    $this->checkRules();

    $this->notify();

    return true;


  }

  protected function notify() {
    if (empty($this->filesToNotify)) {
      return;
    }

    

  }

  protected function checkRules() {

    $this->e("Check rules");

    //first look for files that need to be notified...
    $this->filesToNotify = [];
    foreach ($this->payloadFiles as $file) {
      $this->e("----------------------------------------");
      $this->e("Processing file $file");
      foreach ($this->config['RULES'] as $rule) {
        $rule_found = false;
        if (strpos($file, $rule) !== false) {
          $rule_found = true;
          $this->e("Rule matched: " . $rule);
          //file matches a rule
          $rule_exception_found = false;
          //let's see if there's an exception
          foreach ($this->config['EXCEPTIONS'] as $rule_exception) {
            if (strpos($file, $rule_exception) !== false) {
              //file matches an exception
              $rule_exception_found = true;
              $this->e("Rule matched exception: " . $rule_exception);
              break;
            }
          } //end foreach exception
          if (!$rule_exception_found) {
            $this->e("Notification queued");
            $this->filesToNotify[] = $file;
          }
          break;
        } //end if file match rule
      } //end foreach rule
      if (!$rule_found) {
        $this->e("No rules matched");
      }
    } //end foreach file...

    $this->e("-------------------------------------------");
    $this->e("Files to notify: " . count($this->filesToNotify));

  }


  protected function e($txt, $nl = PHP_EOL) {
    if (!$this->verbose) {
      return;
    }
    echo date("H:i:s     ") . $txt . $nl;
  }

}