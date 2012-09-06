<?php
/**
 * by Andrew Brampton 2011
 */

require 'lib/ShapeFile.inc.php';

if ($argc < 2) {
	die("Usage: php debug_shapefile.php {shp files}\n");
}

define('REMOVE_POINTS', 0.001); // Remove points which are within this distance from a straight line
define('SHP_CHAR_ENCODING', 'ISO-8859-1'); // Encoding used in the Shp file

function convert_to_utf8($str) {
	return iconv(SHP_CHAR_ENCODING, 'UTF-8', $str);
}

function ends_with($haystack, $needle) {
	$len = strlen($needle);
	return substr_compare($haystack, $needle, -$len, $len) === 0;
}

function unzip($zipfile, $destdir = '') {
	$files = array();

	$zip = zip_open($zipfile);
	if(!is_resource($zip))
		die ("Unable to open zip file\n");

	while(($zip_entry = zip_read($zip)) !== false) {
		$file = $destdir . '/' . zip_entry_name($zip_entry);
		echo "Unpacking " . zip_entry_name($zip_entry) . "\n";

		file_put_contents($file, zip_entry_read($zip_entry, zip_entry_filesize($zip_entry)));
		$files[] = $file;
	}

	zip_close($zip);

	return $files;
}


// Class to ensure files are unlinked
class UnlinkMe {
	var $files;

	function __construct($files) {
		$this->files = $files;
	}

	function __destruct() {
		foreach($this->files as $file) {
			echo "Unlinking $file\n";
			unlink($file);
		}
	}
}

function process_zip_file($filename) {
	// Uncompress
	$zipfiles = unzip($filename, sys_get_temp_dir());

	// This ensures PHP cleans up our temp files
	$unlink = new UnlinkMe($zipfiles);

	foreach($zipfiles as $file) {
		process_file($file);
	}
}

function process_file($filename) {

	if (ends_with($filename, '.zip')) {
		return process_zip_file($filename);
	}

	if (!ends_with($filename, '.shp')) {
		echo "Skipping $filename\n";
		return;
	}


	$shp = new ShapeFile($filename, array('noparts' => false));
	if (!$shp)
		die("Problem opening shape file: $filename\n");

	while ($record = $shp->getNext()) {

		// Describitive data
		$data = $record->getDbfData();
		if (count($data) == 0)
			continue;

		var_dump($data);

		$shape = $record->getShpData();
		var_dump($shape);
	}
}


array_shift($argv); // Pop the script name

foreach ($argv as $filename) {
	process_file($filename);
}
