<?php
/**
 * Converts the tiger Shapefiles into something else
 * by Andrew Brampton 2011
 */

require 'lib/ShapeFile.inc.php';

if ($argc < 2) {
	die("Usage: php import_tiger.php {db name} {shp files}\n");
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

/**
 * A vistor pattern, ala SAX parser
 * @author bramp
 */
interface TigerDatabase {
	/**
	 * A new region, such as a state, or county
	 * @param int $geoid10
	 * @param string $name10
	 * @return a ID to be used later
	 */
	function region($geoid10, $name10);

	/**
	 * A new boundary of a region
	 * @param string $id The ID returned from a previous call to region
	 * @param array $bounds
	 * @param array $points
	 */
	function part($id, $bounds, $points);
};

class SqliteTigerDatabase implements TigerDatabase {

	var $dbh;
	var $sth_region;
	var $sth_part;

	function __construct($dbname) {
		$this->dbh = new PDO("sqlite:$dbname") or die("Failed to open database $dbname\n");
		$this->dbh->setAttribute (PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// We don't care if the script fails while it's being made, so lets use these to speed things up!
		$this->dbh->exec('PRAGMA synchronous = OFF');
		$this->dbh->exec('PRAGMA journal_mode = MEMORY');

		// Clear out all the data
		$this->dbh->exec('CREATE TABLE IF NOT EXISTS regions (id INTEGER PRIMARY KEY, geoid TEXT, name TEXT);');
		$this->dbh->exec('CREATE TABLE IF NOT EXISTS parts   (id INTEGER PRIMARY KEY, regionid , minX NUMERIC, minY NUMERIC, maxX NUMERIC, maxY NUMERIC, points BLOB);');
		$this->dbh->exec('DELETE FROM regions');
		$this->dbh->exec('DELETE FROM parts');

		$this->sth_region  = $this->dbh->prepare('INSERT INTO regions (geoid, name) VALUES (?,?)') or die('Failed to prepare region INSERT query');
		$this->sth_part    = $this->dbh->prepare('INSERT INTO parts (regionid, minx, miny, maxx, maxy, points) VALUES (?,?,?,?,?,?)');

		$this->dbh->beginTransaction();
	}

	function __destruct() {
		$this->dbh->commit();
	}

	function region($geoid10, $name10) {
		$this->sth_region->execute( array($geoid10, $name10) );
		return $this->dbh->lastInsertId();
	}

	function part($region_id, $bounds, $points) {
		$points = implode(',', $points);
		$points = gzcompress($points); // Compress, because PHP/SQLite crashes if the string is too long

		$this->sth_part->execute( array($region_id, $bounds[0], $bounds[1], $bounds[2], $bounds[3], $points) );
	}
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

function process_zip_file($db, $filename) {
	// Uncompress
	$zipfiles = unzip($filename, sys_get_temp_dir());

	// This ensures PHP cleans up our temp files
	$unlink = new UnlinkMe($zipfiles);

	foreach($zipfiles as $file) {
		process_file($db, $file);
	}
}

function process_file($db, $filename) {

	if (ends_with($filename, '.zip')) {
		return process_zip_file($db, $filename);
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

		if (!isset($data['GEOID10'])) {
			echo "Record doesn't contain geoid10\n";
			var_dump($data);
			continue;
		}

		$geoid10 = trim($data['GEOID10']);
		if (empty($geoid10)) {
			echo "Record contains empty geoid10\n";
			var_dump($data);
			continue;
		}

		if (isset($data['NAME10']))
			$name10  = trim(convert_to_utf8($data['NAME10']));
		else
			$name10 = '';


		$region_id = $db->region($geoid10, $name10);

		$shape = $record->getShpData();

		if (!isset($shape['parts'])) {
			echo "Record missing points!\n";
			var_dump($data);
			var_dump($shape);
			die();
		}

		$total_points = 0;
		$total_points_compress = 0;

		foreach ($shape['parts'] as $partidx => $part) {

			$minx = 180;
			$miny = 90;
			$maxx = -180;
			$maxy = -90;

			$finalpoints = '';
			$points = $part['points'];
			for ($i = 0; $i < count($points); $i++) {

				$point = $points[$i];
				$pointX = $point['x'];
				$pointY = $point['y'];

				if (REMOVE_POINTS > 0.0 && $i >= 2) {
					// 3 points
					// Figure out if last three point are on a straight line.
					// If so we can throw away the last point
					$n = count($finalpoints);
					$point1Y = $finalpoints[$n-1];
					$point1X = $finalpoints[$n-2];
					$point2Y = $finalpoints[$n-3];
					$point2X = $finalpoints[$n-4];

					$diffX = $pointX - $point2X;
					$diffY = $pointY - $point2Y;
					$grad  = @($diffX / $diffY); // Gradent between first and last

					$diffX2 = $point1X - $point2X;
					$diffY2 = $point1Y - $point2Y;
					$grad2  = @($diffX2 / $diffY2); // Gradent between first and middle

					// Be double sure (due to floating point errors)
					$diffX1 = $pointX - $point1X;
					$diffY1 = $pointY - $point1X;
					$grad1  = @($diffX1 / $diffY1); // Gradent between middle and last

					if (abs($grad2 - $grad) < REMOVE_POINTS && abs($grad1 - $grad) < REMOVE_POINTS) {
						// Remove the last point
						array_pop($finalpoints); //y
						array_pop($finalpoints); //x
						$total_points_compress--;
					}
				}

				$total_points_compress++;
				$total_points++;

				$finalpoints[] = $pointX;
				$finalpoints[] = $pointY;

				// Update min-max
				$minx = min($minx, $pointX);
				$miny = min($miny, $pointY);
				$maxx = max($maxx, $pointX);
				$maxy = max($maxy, $pointY);
			}

			$db->part($region_id, array($minx, $miny, $maxx, $maxy), $finalpoints);
			unset($finalpoints);
		}

		echo "Adding $geoid10: $name10 - $total_points_compress/$total_points\n";
	}
}


array_shift($argv); // Pop the script name

$dbname = array_shift($argv);

// TODO make the type of DB configurable
$db = new SqliteTigerDatabase($dbname);

foreach ($argv as $filename) {
	process_file($db, $filename);
}
