#!/usr/bin/env php
<?php

$cfg_path = realpath(dirname(__FILE__)).'/';
$forbidden_file = $cfg_path."forbidden.lst";
$log="/tmp/redirector.log";
$log = "/var/log/redirector.log";

function addLog($line)
{
  global $log;
  $date = new DateTime();
  $d=$date->format('Y-m-d H:i:s : ');
	file_put_contents($log, $d . str_replace("\n","", $line) . "\n", FILE_APPEND);
}

printf("Config Path=%s\n", $cfg_path);

// config
$config = require(__DIR__ . "/config.php");
$conf_timeout = $config["time_out"] ?? 86400;
$conf_redirect = $config["redirect"] ?? [];
$redirect_map = create_redirect_map($conf_redirect);

$forbidden_time="";
function readForbidden()
{
  global $forbidden_time;
  global $forbidden_file;
  global $forbidden;
	$time = filemtime($forbidden_file);
  if ($time != $forbidden_time)
  {
    $forbidden_time = $time;
	  $forbidden = file($forbidden_file, FILE_IGNORE_NEW_LINES);
  }
}

addLog("Started");

// stdin
stream_set_timeout(STDIN, $conf_timeout);
while ( $input = fgets(STDIN) ) {
   readForbidden();
   $continue=false;
   addLog($input);
   foreach($forbidden as $reject)
    if (strpos($input, $reject) !== false)
    {
        echo "OK status=302 url=127.0.0.1\n";
				$continue=true;
        break;
    }
    if ($continue) continue;

    // parse input
    $parsed = parse_input($input);
    if($parsed == null){ continue;}

    // get redirect url
    $url = get_redirect_map($redirect_map,$parsed['domain'],$parsed['port']);
    addLog($input . '->' . ' (' . $url . ")", FILE_APPEND);

    // output redirect url
    $output = create_response($url, $parsed['url']);
    echo $output;
}

/**
 * @param $map
 * @param $domain
 * @param $port
 * @return bool
 */
function get_redirect_map($map, $domain, $port){
    $port = $port ?? '*';
    return $map[$domain][$port]
        ?? $map[$domain]['*']
        ?? false;
}

/**
 * @param $map
 * @param $domain
 * @param $port
 * @param $url
 */
function set_redirect_map(&$map, $domain, $port, $url){
    $port = $port ?? '*';
    $map[$domain][$port] = $url;
}

/**
 * @param array $config_map
 * @return array
 */
function create_redirect_map($config_map){
    $ret = [];
    foreach((array)$config_map as $map){
        $port = $map['port'] ?? null;
        set_redirect_map($ret, $map['domain'], $port, $map['redirect']);
    }
    return $ret;
}

/**
 * @param $redirect_url
 * @param $input_url
 * @return string
 */
function create_response($redirect_url, $input_url){
    $ret = $redirect_url
        ? 'OK status=302 url="' . $redirect_url . '"' . "\n"
        : $input_url . "\n";
     return $ret;
}

/**
 * parse input value
 * @param $input
 * @return array
 */
function parse_input($input){
    # input parts
    $input_parts = explode(" ", $input);
    $url = $input_parts[0] ?? "";

    # parts
    $scheme = "((?<scheme>http)://)";
    $domain = "(?<domain>[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+){1,})";
    $port = "(:(?<port>[0-9]{1,5}))";
    $query = "(?<query>/.*)?";

    # ssh, http
    $ssl_pattern = "#\A{$domain}{$port}?\Z#u";
    $http_pattern = "#\A{$scheme}{$domain}{$port}?{$query}\Z#u"; #{$domain}{$query}?

    # pattern
    $ret = [];
    if(preg_match($ssl_pattern, $url, $match)){
        $ret['domain'] = $match['domain'] ?? "";
        $ret['port'] = $match['port'] ?? "";
        $ret['url'] = $url;
    }
    elseif(preg_match($http_pattern, $url, $match)){
        $ret['domain'] = $match['domain'] ?? "";
        $ret['port'] = $match['port'] ?? "80";
        $ret['url'] = $url;
    }
    return $ret;
}
