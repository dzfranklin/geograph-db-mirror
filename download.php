<?php

/**
 * $Project: GeoGraph $
 * $Id: download.php barry $
 *
 * GeoGraph geographic photo archive project
 * This file copyright (C) 2011 Barry Hunter (geo@barryhunter.co.uk)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */



##########################

# REQUIREMENTS

# php 5 (tested on php 5.2) 
# with mysql extension installed, and safe mode off 

# mysql server (tested on mysql 5.0.19) 

# Linux OS 
# (not tested on anything else! But it could work... use at own risk!) 

##########################

# INSTRUCTIONS

# 1. Add your database creditentials here: (this should be created - but the script will create the table inside the database)

$db_hostname = '127.0.0.1:3307'; // mysql_connect accepts host:port
$db_host = '127.0.0.1';
$db_port = '3307';
$db_username = 'geograph';
$db_password = '';
$db_database = 'geograph';

# 2. Define a folder here for storing downloaded files (make sure exists and is writable) 

$folder = sys_get_temp_dir() . "/";

# 3. Check this points to server and table you want to download (probably dont need changing)

$server = "https://data.geograph.org.uk/dumps/";

$table = "gridimage_search";

$timestamp_file = "/var/lib/mysql/" . $db_database . "/" . $table . ".last_sync";

# 4. Execute this script. Possibly automate it, eg 5am daily. 

##########################
# Nothing below here should need modifing

$path = array();
$path['wget'] = '/usr/bin/wget';
$path['gunzip'] = '/usr/bin/gunzip';
$path['mysql'] = '/usr/bin/mysql';
$path['zcat'] = '/bin/zcat';

##########################

if (!is_dir($folder)) {
        die("Folder $folder doesnt exist - please create it!\n");
} elseif (!is_writable($folder)) {
        die("Folder $folder not writable - please make it so!\n");
}

$db = mysql_connect($db_hostname,$db_username,$db_password) or die("unable to connect : ".mysql_error()."\n");
mysql_select_db($db_database,$db) or die("unable to select database : ".mysql_error()."\n");

$path['mysql'] .= " -h".escapeshellarg($db_host)." -P".escapeshellarg($db_port)." -u".escapeshellarg($db_username).
                  " ".escapeshellarg($db_database);

$url = "$server$table/?C=M;O=D";

$listing = file_get_contents($url, false, stream_context_create([
    'http' => ['header' => "User-Agent: github.com/dzfranklin/geograph-db-mirror\r\n"],
]));
if (!empty($listing)) {

    if (preg_match_all('/href="('.$table.'\..+?)\"/',$listing,$m)) {

        chdir($folder);

        $files = $m[1];
        $import = array();

        $tables = getRow("SHOW TABLES LIKE '$table'",$db);

//no table - so lets use the latest full file
        if (empty($tables)) {

                $full = preg_grep('/\.full\./',$files);

                if (count($full)) {
			print "# no database so getting latest full download\n";

                        $file = array_shift($full);

	                preg_match("/(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})/",$file,$m);

	                $criteria = $m[0];
                } else {
                        die("unable to identify full download at $url\n");
                }

	} else {
                if (file_exists($timestamp_file)) {
                        $criteria = trim(file_get_contents($timestamp_file));
                } else {
                        die("Table exists but no timestamp file found at $timestamp_file\n");
                }
        }

        print "# Looking for files since $criteria\n";

//filter the array (by making a new one)
        foreach ($files as $file) {
                preg_match("/(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})/",$file,$m);

                if (empty($m[0])) {
                        continue; // skip files without a timestamp (e.g. gridimage_search.schema)
                } elseif ($m[0] >= $criteria) {
                        array_unshift($import,$file);
                } else {
                        break;
                }
        }

        if (!empty($import)) {

//if there are any full in the list, remove any before it. (pointless bothering with them)
                $full = preg_grep('/\.full\./',$import);
                if (count($full)) {
			$file = array_pop($full);

			print "# latest full $file so only ignoring any files before it...\n";

			$index = array_search($file,$import);
			$import = array_splice($import,$index);
		}

//run the commands!
                foreach ($import as $file) {

                        if (!file_exists($file) && !file_exists(str_replace('.gz','',$file))) {
                                run("{$path['wget']} -q " . escapeshellarg("$server$table/$file"));
                        }

                        if (!file_exists(str_replace('.gz','',$file)) && !empty($path['zcat']) && file_exists($path['zcat'])) {

                                run("{$path['zcat']} " . escapeshellarg($file) . " | {$path['mysql']}");

                        } else {
                                run("{$path['gunzip']} " . escapeshellarg($file));
                                $file = str_replace(".gz",'',$file);
                                run("{$path['mysql']} < " . escapeshellarg($file));
                        }
                }
        }

        if (empty($import)) {
                die("nothing to do!\n");
        }

        preg_match("/(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})-(\d{2})/", $file, $m);
        file_put_contents($timestamp_file, $m[0]);
        print "# updated $timestamp_file to {$m[0]}\n";

    } else {
        die("unable to get file listing??\n\n");
    }

} else {
    die("unable to fetch $url\n\n");
}


######################

function run($cmd) {
        print "# $cmd\n";
        passthru($cmd, $ret);
        if ($ret !== 0) {
                die("command failed with code $ret\n");
        }
}

function getRow($query,$db) {
        $result = mysql_query($query, $db) or print('<br>Error getRow: '.mysql_error());
        if (mysql_num_rows($result)) {
                return mysql_fetch_assoc($result);
        } else {
                return FALSE;
        }
}

