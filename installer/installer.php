<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2008 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class installer {
  static function command_line() {
    // Set error handlers
    set_error_handler(create_function('$errno, $errstr, $errfile, $errline',
        'throw new ErrorException($errstr, 0, $errno, $errfile, $errline);'));
    set_exception_handler(array("installer", "print_exception"));

    if (self::already_installed()) {
      print "Gallery 3 is already installed.\n";
      return;
    }

    $config = self::parse_cli_params();
    try {
      self::setup_database($config);
      self::setup_var();
      self::install($config);
      list ($user, $password) = self::create_admin($config);
      print "Successfully installed your Gallery 3!\n";
      print "Log in with:\n  username: admin\n  password: $password\n";
    } catch (InstallException $e) {
      self::display_errors($e->errors());
    }
  }

  static function web() {
    if (self::already_installed()) {
      $state = "already_installed";
      include("install.html.php");
      return;
    }

    switch ($step = $_GET["step"]) {
    default:
      $step = "welcome";
      break;

    case "get_info":
      break;

    case "save_info":
      $config = array("host" => $_GET["dbhost"],
                      "user" => $_GET["dbuser"],
                      "password" => $_GET["dbpass"],
                      "dbname" => $_GET["dbname"],
                      "prefix" => "",
                      "type" => function_exists("mysqli_init") ? "mysqli" : "mysql");
      try {
        self::setup_database($config);
        self::setup_var();
        self::install($config);
        list ($user, $password) = self::create_admin($config);
      } catch (Exception $e) {
        $step = "database_failure";
      }
      break;
    }

    include("install.html.php");
  }

  static function already_installed() {
    return file_exists(VARPATH . "database.php");
  }

  static function parse_cli_params() {
    $config = array("host" => "localhost",
                    "user" => "root",
                    "password" => "",
                    "dbname" => "gallery3",
                    "prefix" => "");

    if (function_exists("mysqli_init")) {
      $config["type"] = "mysqli";
    } else {
      $config["type"] = "mysql";
    }

    $argv = $_SERVER["argv"];
    for ($i = 1; $i < count($argv); $i++) {
      switch (strtolower($argv[$i])) {
      case "-d":
        $config["dbname"] = $argv[++$i];
        break;
      case "-h":
        $config["host"] = $argv[++$i];
        break;
      case "-u":
        $config["user"] = $argv[++$i];
        break;
      case "-p":
        $config["password"] = $argv[++$i];
        break;
      }
    }

    return $config;
  }

  static function setup_database($config) {
    $errors = array();
    if (!mysql_connect($config["host"], $config["user"], $config["password"])) {
      $errors["Database"] =
        "Unable to connect to your database with the credentials provided.  Error details:\n" .
        mysql_error();
      return;
    }

    if (!mysql_select_db($config["dbname"])) {
      if (!(mysql_query("CREATE DATABASE {$config['dbname']}") &&
            mysql_select_db($config["dbname"]))) {
        $errors["Database"] = sprintf(
          "Database '%s' is not defined and can't be created",
          $config["dbname"]);
      }
    }

    if (empty($errors) && mysql_num_rows(mysql_query("SHOW TABLES FROM {$config['dbname']}"))) {
      $errors["Database"] = sprintf(
        "Database '%s' exists and has tables in it, continuing may overwrite an existing install",
        $config["dbname"]);
    }

    if ($errors) {
      throw new InstallException($errors);
    }
  }

  static function setup_var() {
    $errors = array();
    if (is_writable(VARPATH)) {
      return;
    }

    if (is_writable(dirname(VARPATH)) && !mkdir(VARPATH)) {
      $errors["Filesystem"] =
        sprintf("The %s directory doesn't exist and can't be created", VARPATH);
    }

    if ($errors) {
      throw new InstallException($errors);
    }
  }

  static function install($config) {
    $errors = array();

    include(DOCROOT . "installer/init_var.php");

    $buf = "";
    foreach (file("installer/install.sql") as $line) {
      $buf .= $line;
      if (preg_match("/;$/", $buf)) {
        if (!mysql_query($buf)) {
          throw new InstallException(
            array("Database" => "Unable to install database tables.  Error details:\n" .
                  mysql_error()));
          break;
        }
        $buf = "";
      }
    }

    $db_config_file = VARPATH . "database.php";
    ob_start();
    extract($config);
    include("installer/database_config.php");
    $output = ob_get_clean();

    if (!file_put_contents($db_config_file, $output) !== false) {
      throw new InstallException(array("Config" => "Unable to create " . VARPATH . "database.php"));
    }

    system("chmod -R 777 " . VARPATH);
  }

  static function create_admin($config) {
    $errors = array();
    $salt = "";
    for ($i = 0; $i < 4; $i++) {
      $char = mt_rand(48, 109);
      $char += ($char > 90) ? 13 : ($char > 57) ? 7 : 0;
      $salt .= chr($char);
    }
    $password = substr(md5(time() * rand()), 0, 6);
    $hashed_password = $salt . md5($salt . $password);
    if (mysql_query("UPDATE `users` SET `password` = '$hashed_password' WHERE `id` = 2")) {
    } else {
      $errors["Database"] = "Unable to set admin password.  Error details:\n" . mysql_error();
    }

    if ($errors) {
      throw new InstallException($errors);
    }

    return array("admin", $password);
  }

  static function print_exception($exception) {
    print $exception->getMessage() . "\n";
    print $exception->getTraceAsString();
  }

  static function display_errors($errors) {
    print "Errors\n";
    foreach ($errors as $title => $error) {
      print "$title\n";
      print "  $error\n\n";
    }
  }
}

class InstallException extends Exception {
  var $errors;

  function __construct($errors) {
    parent::__construct();
    $this->errors = $errors;
  }

  function errors() {
    return $this->errors;
  }
}