#!/usr/bin/env php
<?php

/**
 * LibreNMS
 *
 *   This file is part of LibreNMS.
 *
 * @package    LibreNMS
 * @subpackage poller
 * @copyright  (C) 2006 - 2012 Adam Armstrong
 */

chdir(__DIR__); // cwd to the directory containing this script

require 'includes/defaults.inc.php';
require 'config.php';
require 'includes/definitions.inc.php';
require 'includes/functions.php';
require 'includes/polling/functions.inc.php';
require 'includes/alerts.inc.php';

$poller_start = microtime(true);
echo $config['project_name_version']." Poller\n";
$versions = version_info(false);
echo "Version info:\n";
$cur_sha = $versions['local_sha'];
echo "Commit SHA: $cur_sha\n";
echo "Commit Date: ".$versions['local_date']."\n";
echo "DB Schema: ".$versions['db_schema']."\n";
echo "PHP: ".$versions['php_ver']."\n";
echo "MySQL: ".$versions['mysql_ver']."\n";
echo "RRDTool: ".$versions['rrdtool_ver']."\n";
echo "SNMP: ".$versions['netsnmp_ver']."\n";

$options = getopt('h:m:i:n:r::d::v::a::f::');

if ($options['h'] == 'odd') {
    $options['n'] = '1';
    $options['i'] = '2';
} elseif ($options['h'] == 'even') {
    $options['n'] = '0';
    $options['i'] = '2';
} elseif ($options['h'] == 'all') {
    $where = ' ';
    $doing = 'all';
} elseif ($options['h']) {
    if (is_numeric($options['h'])) {
        $where = "AND `device_id` = ".$options['h'];
        $doing = $options['h'];
    } else {
        if (preg_match('/\*/', $options['h'])) {
            $where = "AND `hostname` LIKE '".str_replace('*', '%', mres($options['h']))."'";
        } else {
            $where = "AND `hostname` = '".mres($options['h'])."'";
        }
        $doing = $options['h'];
    }
}

if (isset($options['i']) && $options['i'] && isset($options['n'])) {
    $where = true;
    // FIXME
    $query = 'SELECT `device_id` FROM (SELECT @rownum :=0) r,
        (
            SELECT @rownum := @rownum +1 AS rownum, `device_id`
            FROM `devices`
            WHERE `disabled` = 0
            ORDER BY `device_id` ASC
        ) temp
        WHERE MOD(temp.rownum, '.mres($options['i']).') = '.mres($options['n']).';';
    $doing = $options['n'].'/'.$options['i'];
}

if (!$where) {
    echo "-h <device id> | <device hostname wildcard>  Poll single device\n";
    echo "-h odd                                       Poll odd numbered devices  (same as -i 2 -n 0)\n";
    echo "-h even                                      Poll even numbered devices (same as -i 2 -n 1)\n";
    echo "-h all                                       Poll all devices\n\n";
    echo "-i <instances> -n <number>                   Poll as instance <number> of <instances>\n";
    echo "                                             Instances start at 0. 0-3 for -n 4\n\n";
    echo "Debugging and testing options:\n";
    echo "-r                                           Do not create or update RRDs\n";
    echo "-f                                           Do not insert data into InfluxDB\n";
    echo "-d                                           Enable debugging output\n";
    echo "-v                                           Enable verbose debugging output\n";
    echo "-m                                           Specify module(s) to be run\n";
    echo "\n";
    echo "No polling type specified!\n";
    exit;
}

if (isset($options['d']) || isset($options['v'])) {
    echo "DEBUG!\n";
    if (isset($options['v'])) {
        $vdebug = true;
    }
    $debug = true;
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_reporting', 1);
} else {
    $debug = false;
    // ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 0);
    // ini_set('error_reporting', 0);
}

if (isset($options['r'])) {
    $config['norrd'] = true;
}

if (isset($options['f'])) {
    $config['noinfluxdb'] = true;
}

if ($config['noinfluxdb'] !== true && $config['influxdb']['enable'] === true) {
    $influxdb = influxdb_connect();
} else {
    $influxdb = false;
}

rrdtool_initialize();

echo "Starting polling run:\n\n";
$polled_devices = 0;
if (!isset($query)) {
    $query = "SELECT `device_id` FROM `devices` WHERE `disabled` = 0 $where ORDER BY `device_id` ASC";
}

foreach (dbFetch($query) as $device) {
    $device = dbFetchRow("SELECT * FROM `devices` WHERE `device_id` = " .$device['device_id']);
    if ($device['os_group'] == 'cisco') {
        $device['vrf_lite_cisco'] = dbFetchRows("SELECT * FROM `vrf_lite_cisco` WHERE `device_id` = " . $device['device_id']);
    } else {
        $device['vrf_lite_cisco'] = '';
    }
    poll_device($device, $options);
    echo "#### Start Alerts ####\n";
    RunRules($device['device_id']);
    echo "#### End Alerts ####\r\n";
    $polled_devices++;
}

$poller_end  = microtime(true);
$poller_run  = ($poller_end - $poller_start);
$poller_time = substr($poller_run, 0, 5);

if ($polled_devices) {
    dbInsert(array('type' => 'poll', 'doing' => $doing, 'start' => $poller_start, 'duration' => $poller_time, 'devices' => $polled_devices, 'poller' => $config['distributed_poller_name'] ), 'perf_times');
}

$string = $argv[0]." $doing ".date($config['dateformat']['compact'])." - $polled_devices devices polled in $poller_time secs";
d_echo("$string\n");

echo ("\n".'MySQL: Cell['.($db_stats['fetchcell'] + 0).'/'.round(($db_stats['fetchcell_sec'] + 0), 2).'s]'.' Row['.($db_stats['fetchrow'] + 0).'/'.round(($db_stats['fetchrow_sec'] + 0), 2).'s]'.' Rows['.($db_stats['fetchrows'] + 0).'/'.round(($db_stats['fetchrows_sec'] + 0), 2).'s]'.' Column['.($db_stats['fetchcol'] + 0).'/'.round(($db_stats['fetchcol_sec'] + 0), 2).'s]'.' Update['.($db_stats['update'] + 0).'/'.round(($db_stats['update_sec'] + 0), 2).'s]'.' Insert['.($db_stats['insert'] + 0).'/'.round(($db_stats['insert_sec'] + 0), 2).'s]'.' Delete['.($db_stats['delete'] + 0).'/'.round(($db_stats['delete_sec'] + 0), 2).'s]');

echo "\n";

logfile($string);
rrdtool_close();
unset($config);
// Remove this for testing
// print_r(get_defined_vars());
