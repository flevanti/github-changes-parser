<?php

namespace flevanti\GithubChangesParser;

use Exception;

/**
 * Git webhook needs to be configured as json
 *
 * Class GithubChangesParser
 */
class GithubChangesParser {

  protected $payload;
  protected $payloadFiles;
  public $repoId;
  protected $headers;
  protected $secret_ok = false;
  protected $configMaxLen = 10000;
  protected $config = ["RULES" => [], "EXCEPTIONS" => [], 'METADATA' => []];
  protected $configLoaded = false;
  protected $projectRootKeyword = "[PROOT]";
  protected $filesToNotify;

  public $lastError = "";
  public $verbose = PHP_SAPI == 'cli';

  function __construct($verbose = null) {
    if (is_bool($verbose)) {
      $this->verbose = $verbose;
    }
    $this->e("WELCOME TO GITHUB CHANGES PARSER");
  }

  public function loadConfig($config) {
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

    if (strtolower(substr($config, 0, 8) == "file:///")) {
      $this->e("Config appears to be a local file");
      if (!file_exists($config)) {
        $this->lastError = "Config file not found";
        $this->e($this->lastError);
        return false;
      }
      $config = file_get_contents($config, false, null, 0, $this->configMaxLen + 500);
      if (!$config) {
        $this->lastError = "Unable to read config file";
        $this->e($this->lastError);
        return false;
      }
      $this->e("Config retrieved from file");
    }

    if (strlen($config) > $this->configMaxLen) {
      $this->lastError = "Config file too big, max allowed size is " . $this->configMaxLen;
      $this->e($this->lastError);
      return false;
    }

    $config = explode("\n", $config);

    $this->e("Config file is " . count($config) . " lines");

    $metadata_sent = false;
    $rule_processing = "";

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
      //line commented
      if ($first_char == ";" || $first_char == "#") {
        $this->e("Line is commented, skipped");
        continue;
      }
      if (empty($line)) {
        $this->lastError = "Line is empty after the line identifier";
        $this->e($this->lastError);
        return false;
      }
      //config metadata
      if ($first_char == "@") {
        $this->e("Line is metadata");
        if ($metadata_sent) {
          $this->lastError = "Metadata already parsed, too late!";
          $this->e($this->lastError);
          return false;
        }
        $line = explode("=", $line, 2);
        if (count($line) != 2) {
          $this->lastError = "Metadata line parsing error";
          $this->e($this->lastError);
          return false;
        }
        $line[0] = trim($line[0]);
        $line[1] = trim($line[1]);
        if (empty($line[0])) {
          $this->lastError = "Metadata line missing key";
          $this->e($this->lastError);
          return false;
        }
        $this->config['METADATA'][trim($line[0])] = trim($line[1]);
        continue;
      }
      // if we are here, line is not a metadata
      // so we consider the metadata section over
      $metadata_sent = true;

      //actual rule
      if ($first_char == "-") {
        if (substr($line, 0, 1) == "/") {
          $line = $this->projectRootKeyword . $line;
        }
        $this->config['RULES'][] = $line;
        $this->e("RULE: " . $line);
        $rule_processing = $line;
        continue;
      }
      //exception
      if ($first_char == "!") {
        if (substr($line, 0, 1) == "/") {
          $line = $this->projectRootKeyword . $line;
        }
        if (!empty($rule_processing)) {
          if (strpos($line, $rule_processing) !== 0) {
            $this->lastError = "Rule exception does not belong to previously imported rule: " . $line;
            $this->e($this->lastError);
            return false;
          }
        }

        $this->config['EXCEPTIONS'][] = $line;
        $this->e("RULE EXCEPTION: " . $line);
        continue;
      }

      //ooops your line has been ignored.....
      $this->lastError = "Unable to parse line";
      $this->e($this->lastError);
      return false;

    } //end foreach line in config file

    $this->e("--------------------------------");
    $this->e("Configuration loaded");

    $this->e("Configuration statistics:");
    $this->e("Config file lines: " . count($config));
    $this->e("Rules: " . count($this->config['RULES']));
    $this->e("Rules exceptions: " . count($this->config['EXCEPTIONS']));
    $this->e("Metadata: " . count($this->config['METADATA']));
    $this->e("--------------------------------");

    $this->configLoaded = true;
    return true;

  }

  protected function checkSecret($payload, $signature) {
    $this->e("Check secret key");

    if (!isset($this->config['METADATA']['secret']) || empty($this->config['METADATA']['secret'])) {
      $this->e("Secret key not found in config file, check skipped");
      return true;
    }

    if (empty($signature)) {
      $this->lastError = "Signature is empty!";
      $this->e($this->lastError);
      $this->e("If you don't want to check signature or it is not provided, remove it from the config");
      return false;
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

  /**
   * @param $payload
   * @param $signature
   * @param $config
   *
   * @return bool|array
   */
  public function check($payload, $signature) {
    try {
      if (!$this->configLoaded) {
        $this->lastError = "Configuration not loaded";
        $this->e($this->lastError);
        return false;
      }

      if (!$this->checkSecret($payload, $signature)) {
        return false;
      }

      if (!$this->loadPayload($payload)) {
        return false;
      }

      $this->checkRules();

    } catch (Exception $e) {
      $this->lastError = $e->getMessage();
      return false;
    }
    return $this->filesToNotify;
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

  public function getMetadataValue($key, $default = null) {
    return isset($this->config['METADATA'][$key]) ? $this->config['METADATA'][$key] : $default;
  }

}