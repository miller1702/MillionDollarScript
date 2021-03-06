<?php
/**
 * @version        $Id: check_selection.php 137 2011-04-18 19:48:11Z ryan $
 * @package        mds
 * @copyright    (C) Copyright 2010 Ryan Rhode, All rights reserved.
 * @author        Ryan Rhode, ryan@milliondollarscript.com
 * @license        This program is free software; you can redistribute it and/or modify
 *        it under the terms of the GNU General Public License as published by
 *        the Free Software Foundation; either version 3 of the License, or
 *        (at your option) any later version.
 *
 *        This program is distributed in the hope that it will be useful,
 *        but WITHOUT ANY WARRANTY; without even the implied warranty of
 *        MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *        GNU General Public License for more details.
 *
 *        You should have received a copy of the GNU General Public License along
 *        with this program;  If not, see http://www.gnu.org/licenses/gpl-3.0.html.
 *
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 *
 *        Million Dollar Script
 *        A pixel script for selling pixels on your website.
 *
 *        For instructions see README.txt
 *
 *        Visit our website for FAQs, documentation, a list team members,
 *        to post any bugs or feature requests, and a community forum:
 *        http://www.milliondollarscript.com/
 *
 */

session_start();
define( 'NO_HOUSE_KEEP', 'YES' );
// check the image selection.
require( "../config.php" );

header( "Cache-Control: no-cache, must-revalidate" ); // HTTP/1.1
header( "Expires: Mon, 26 Jul 1997 05:00:00 GMT" ); // Date in the past

$BID = ( isset( $_REQUEST['BID'] ) && $f2->bid( $_REQUEST['BID'] ) != '' ) ? $f2->bid( $_REQUEST['BID'] ) : 1;
load_banner_constants( $BID );

// normalize...

$_REQUEST['map_x']    = floor( $_REQUEST['map_x'] / BLK_WIDTH ) * BLK_WIDTH;
$_REQUEST['map_y']    = floor( $_REQUEST['map_y'] / BLK_HEIGHT ) * BLK_HEIGHT;
$_REQUEST['block_id'] = floor( $_REQUEST['block_id'] );

/**
 * Check available pixels
 *
 * @param $in_str
 *
 * @return bool
 */
function check_pixels( $in_str ) {

	global $f2, $label;

	// cannot reserve pixels if there is no session
	if ( session_id() == '' ) {
		return false;
	}

	if ( isset( $_REQUEST['BID'] ) && $f2->bid( $_REQUEST['BID'] ) != '' ) {
		$BID = $f2->bid( $_REQUEST['BID'] );
	} else {
		$BID = 1;

	}

	// check if it is free
	$sql = "SELECT block_id FROM blocks WHERE banner_id='" . intval($BID) . "' AND block_id IN(".mysqli_real_escape_string( $GLOBALS['connection'], $in_str) . ")";

	$result = mysqli_query( $GLOBALS['connection'], $sql ) or die ( $sql . mysqli_error( $GLOBALS['connection'] ) );
	if ( mysqli_num_rows( $result ) > 0 ) {
		echo js_out_prep( $label['check_sel_notavailable'] . " (E432)" );

		return false;
	}

	return true;
}

#######################################################################
## MAIN 
#######################################################################
// return true, or false if the image can fit

check_selection_main();

function check_selection_main() {

	global $f2;

	# check the status of the block.

	###################################################
	if ( USE_LOCK_TABLES == 'Y' ) {
		$sql = "LOCK TABLES blocks WRITE, temp_orders WRITE, currencies READ, prices READ, banners READ";
		$result = mysqli_query( $GLOBALS['connection'], $sql ) or die ( " <b>Dear Webmaster: The current MySQL user does not have permission to lock tables. Please give this user permission to lock tables, or turn off locking in the Admin. To turn off locking in the Admin, please go to Main Config and look under the MySQL Settings.<b>" );
	} else {
		// poor man's lock
		$sql = "UPDATE `config` SET `val`='YES' WHERE `key`='SELECT_RUNNING' AND `val`='NO' ";
		$result = mysqli_query( $GLOBALS['connection'], $sql ) or die( mysqli_error( $GLOBALS['connection'] ) );
		if ( mysqli_affected_rows( $GLOBALS['connection'] ) == 0 ) {
			// make sure it cannot be locked for more than 30 secs 
			// This is in case the proccess fails inside the lock
			// and does not release it.

			$unix_time = time();

			// get the time of last run
			$sql = "SELECT * FROM `config` WHERE `key` = 'LAST_SELECT_RUN' ";
			$result = mysqli_query( $GLOBALS['connection'], $sql ) or die( mysqli_error( $GLOBALS['connection'] ) );
			$t_row = mysqli_fetch_array( $result );

			if ( $unix_time > $t_row['val'] + 30 ) {
				// release the lock

				$sql = "UPDATE `config` SET `val`='NO' WHERE `key`='SELECT_RUNNING' ";
				$result = mysqli_query( $GLOBALS['connection'], $sql ) or die( mysqli_error( $GLOBALS['connection'] ) );

				// update timestamp
				$sql = "REPLACE INTO config (`key`, `val`) VALUES ('LAST_SELECT_RUN', '$unix_time')  ";
				$result = mysqli_query( $GLOBALS['connection'], $sql ) or die ( mysqli_error( $GLOBALS['connection'] ) );
			}

			usleep( 5000000 ); // this function is executing in another process. sleep for half a second
			check_selection_main();

			return;
		}

	}
	####################################################

	$upload_image_file = get_tmp_img_name();

	$imagine = new Imagine\Gd\Imagine();

	$image = $imagine->open( $upload_image_file );
	$size  = $image->getSize();

	$cb_array = array();
	for ( $y = 0; $y < ( $size->getHeight() ); $y += BLK_HEIGHT ) {
		for ( $x = 0; $x < ( $size->getWidth() ); $x += BLK_WIDTH ) {

			$map_x = $x + intval($_REQUEST['map_x']);
			$map_y = $y + intval($_REQUEST['map_y']);

			$GRD_WIDTH  = BLK_WIDTH * G_WIDTH;
			$cb         = ( ( $map_x ) / BLK_WIDTH ) + ( ( $map_y / BLK_HEIGHT ) * ( $GRD_WIDTH / BLK_WIDTH ) );
			$cb_array[] = $cb;
		}
	}

	$in_str = implode( ',', $cb_array );
	$f2->write_log( "in_str is:" . $in_str );
	check_pixels( $in_str );

	###################################################

	if ( USE_LOCK_TABLES == 'Y' ) {
		$sql = "UNLOCK TABLES";
		$result = mysqli_query( $GLOBALS['connection'], $sql ) or die ( mysqli_error( $GLOBALS['connection'] ) . " <b>Dear Webmaster: The current MySQL user set in config.php does not have permission to lock tables. Please give this user permission to lock tables, or set USE_LOCK_TABLES to N in the config.php file that comes with this script.<b>" );
	} else {

		// release the poor man's lock
		$sql = "UPDATE `config` SET `val`='NO' WHERE `key`='SELECT_RUNNING' ";
		mysqli_query( $GLOBALS['connection'], $sql );

		$unix_time = time();

		// update timestamp
		$sql = "REPLACE INTO config (`key`, `val`) VALUES ('LAST_SELECT_RUN', '$unix_time')  ";
		$result = @mysqli_query( $GLOBALS['connection'], $sql ) or die ( mysqli_error( $GLOBALS['connection'] ) );

	}
	####################################################

}
