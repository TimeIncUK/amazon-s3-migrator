<?php

/*
Plugin Name: Amazon S3 Migrator
Description: Adds extra options to WP CLI to migrate all old images to S3
Author: Nick Stacey, IPC Media
Version: 1.0
Author URI: http://www.ipcmedia.com/
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

// only run this plugin if WP_CLI exists
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__.'/migrator/Command.php';
}
