<?php
/**
 * Converts the tiger Shapefiles into something else
 * by Andrew Brampton 2011-2012
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

// Unzip uses the fuse-zip filesystem
function unzip_fuse($zipfile, $destdir = '') {
	mkdir($destdir, 0777, true);
	system("fuse-zip $zipfile $destdir");
	return array($destdir);
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
	function region($geoid10, $name10, $area, $meta = null);

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
		$this->dbh->exec( 'CREATE TABLE IF NOT EXISTS region (
			id INTEGER PRIMARY KEY, geoid TEXT NOT NULL, name TEXT,
			area NUMERIC, meta TEXT,
			minX NUMERIC NOT NULL, minY NUMERIC NOT NULL,
			maxX NUMERIC NOT NULL, maxY NUMERIC NOT NULL);'
		);
		$this->dbh->exec('CREATE TABLE IF NOT EXISTS part (
			id INTEGER PRIMARY KEY, region_id INTEGER NOT NULL,
			minX NUMERIC NOT NULL, minY NUMERIC NOT NULL,
			maxX NUMERIC NOT NULL, maxY NUMERIC NOT NULL, points BLOB NOT NULL);'
		);

		$this->dbh->exec('DELETE FROM region');
		$this->dbh->exec('DELETE FROM part');

		$this->sth_region  = $this->dbh->prepare('INSERT INTO region (geoid, name, area, meta, minx, miny, maxx, maxy) VALUES (?,?,?,?, -180, -90, 180, 90)') or die('Failed to prepare region INSERT query');
		$this->sth_part    = $this->dbh->prepare('INSERT INTO part (region_id, minx, miny, maxx, maxy, points) VALUES (?,?,?,?,?,?)');

		$this->dbh->beginTransaction();
	}

	function __destruct() {
		$this->dbh->commit();

		// Now denormalise the DB a little and work out max bounds for the regions
		$sth_region_up = $this->dbh->prepare('UPDATE region SET minx = ?, miny = ?, maxx = ?, maxy = ? WHERE id = ?') or die('Failed to prepare region UPDATE query');
		$sth = $this->dbh->query('SELECT MIN(p.minx), MIN(p.miny), MAX(p.maxx), MAX(p.maxy), r.id FROM region r JOIN part p ON r.id = p.region_id GROUP BY r.id');

		$this->dbh->beginTransaction();

		foreach ($sth->fetchAll(PDO::FETCH_NUM) as $row) {
			$sth_region_up->execute($row);
		}

		$this->dbh->commit();
	}

	function region($geoid10, $name10, $area, $meta = null) {
		$this->sth_region->execute( array($geoid10, $name10, $area, json_encode($meta)) );
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

class UnmountMe {
	var $path;

	function __construct($path) {
		$this->path = $path;
	}

	function __destruct() {
		echo "Umounting $this->path\n";
		//system ("umount $this->path");
		system ("fusermount -u $this->path");
		rmdir($this->path);
	}
}



function process_zip_file($db, $filename) {
	$usefuse = true;

	if ($usefuse) {
		$mountpath = sys_get_temp_dir() . '/' . uniqid();
		// Uncompress
		$zipfiles = unzip_fuse($filename, $mountpath);

		// This ensures PHP cleans up our temp files
		$unlink = new UnmountMe($mountpath);
	} else {
		// Uncompress
		$zipfiles = unzip($filename, sys_get_temp_dir());

		// This ensures PHP cleans up our temp files
		$unlink = new UnlinkMe($zipfiles);
	}

	foreach($zipfiles as $file) {
		process_file($db, $file);
	}
}

function process_file($db, $filename) {

	if (is_dir($filename)) {
		foreach (glob("$filename/*") as $f)
			process_file($db, $f);
		return;
	}

	if (ends_with($filename, '.zip')) {
		return process_zip_file($db, $filename);
	}

	if (ends_with($filename, '.shp')) {
		return process_shp_file($db, $filename);
	}


	echo "Skipping $filename\n";
}

function process_shp_file($db, $filename) {

	$shp = new ShapeFile($filename, array('noparts' => false));
	if (!$shp)
		die("Problem opening shape file: $filename\n");

	while ($record = $shp->getNext()) {

		unset($prev_record); // Ensure we keep things cleaned up
		$prev_record = $record;

		// Describitive data
		$data = $record->getDbfData();
		if (count($data) == 0)
			continue;

		// Ensure everything is UTF-8 and trimmed
		foreach ($data as $k => &$value) {
			$value = trim(convert_to_utf8($value));
		}

		if (!isset($data['GEOID10'])) {
			echo "Record doesn't contain geoid10\n";
			var_dump($data);
			continue;
		}

		$geoid10 = $data['GEOID10'];
		if (empty($geoid10)) {
			echo "Record contains empty geoid10\n";
			var_dump($data);
			continue;
		}

		$name10 = null;
		if (isset($data['NAME10']))
			$name10 = $data['NAME10'];

		// in square meters (SI units FTW)
		$area = null;
		if (isset($data['ALAND10']))
			$land_area = (int)$data['ALAND10'];
		//if (isset($data['AWATER10']))
		//	$water_area = (int)trim($data['AWATER10']);

		$region_id = $db->region($geoid10, $name10, $area, $data);

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

	unset($shp);
}


array_shift($argv); // Pop the script name

$dbname = array_shift($argv);

// TODO make the type of DB configurable
$db = new SqliteTigerDatabase($dbname);

foreach ($argv as $filename) {
	process_file($db, $filename);
}

unset($db); // force a deconstruct
