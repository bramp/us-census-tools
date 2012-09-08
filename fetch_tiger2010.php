<?php 
// Fetches 2010 TIGER/Line Shapefiles
// by Andrew Brampton 2011
// 
// I created this because a lot of the TIGER data is in multiple files, for example,
// the county data is broken down by state, and in one large US file.
// This will download the most complete data possible.

$tiger_ftp = 'ftp2.census.gov';
$tiger_path = '/geo/tiger';
$tiger_user = 'anonymous';
$tiger_pass = 'a@b.com';
$tiger_pasv = true;

$geos = array(
	'cbsa'  => array(
		'name' => 'Metropolitan/Micropolitan Statistical Area',
		'path' => 'TIGER2010/CBSA',
		//'regex' => '/_us_.+\.zip$/',
		'regex' => '/_\d{2}_.+\.zip$/',
	),
	'bg'    => array(
		'name' => 'Block Group',
		'path' => 'TIGER2010/BG',
		'regex' => '/_\d{2}_bg.*\.zip$/',
	),
	'csa'   => array(
		'name' => 'Combined Statistical Area',
		'path' => 'TIGER2010/CSA',
		//'regex' => '/_us_.+\.zip$/',
		'regex' => '/_\d{2}_.+\.zip$/',
	),
	'cd'    => array(
		'name' => 'Congress Districts',
		'path' => 'TIGER2010/CD',
		//'regex' => '/_us_.+\.zip$/',
		'regex' => '/_\d{2}_.+\.zip$/',
	),
	'state' => array(
		'name' => 'State',
		'path' => 'TIGER2010/STATE',
		'regex' => '/_us_.+\.zip$/',
	),
	'county' => array(
		'name' => 'County',
		'path' => 'TIGER2010/COUNTY',
		//'regex' => '/_us_.+\.zip$/',
		'regex' => '/_\d{2}_.+\.zip$/',
	),
	'cnecta' => array(
		'name' => 'Combined New England City and Town Area',
		'path' => 'TIGER2010/CNECTA',
		//'regex' => '/_us_.+\.zip$/',
		'regex' => '/_\d{2}_.+\.zip$/',
	),
	'necta' => array(
		'name' => 'New England City and Town Area',
		'path' => 'TIGER2010/NECTA',
		//'regex' => '/_us_.+\.zip$/',
		'regex' => '/_\d{2}_.+\.zip$/',
	),
	'elsd' => array(
		'name' => 'Elementary School District',
		'path' => 'TIGER2010/ELSD',
		'regex' => '/.zip$/',
	),
	'scsd' => array(
		'name' => 'Secondary School District',
		'path' => 'TIGER2010/SCSD',
		'regex' => '/.zip$/',
	),
	'unsd' => array(
		'name' => 'Unified School District',
		'path' => 'TIGER2010/UNSD',
		'regex' => '/.zip$/',
	),
	'sldl' => array(
		'name' => 'State Legislative District Lower Chamber',
		'path' => 'TIGER2010/SLDL',
		'regex' => '/.zip$/',
	),
	'sldu' => array(
		'name' => 'State Legislative District Upper Chamber',
		'path' => 'TIGER2010/SLDU',
		'regex' => '/.zip$/',
	),
	'submcd' => array(
		'name' => 'Subminor Civil Division',
		'path' => 'TIGER2010/SUBMCD',
		'regex' => '/.zip$/',
	),
	'vtd' => array(
		'name' => 'Voting Districting',
		'path' => 'TIGER2010/VTD',
		'regex' => '/.zip$/',
	),
	'zcta5' => array(
		'name' => 'ZIP Code Tabulation Areas',
		'path' => 'TIGER2010/ZCTA5',
		//'regex' => '/_us_.+\.zip$/',
		'regex' => '/_\d{2}_.+\.zip$/',
	),
);

function show_usage() {
	echo "Fetches 2010 TIGER/Line Shapefiles \n";
	echo "Usage: php fetch_tiger.php [--all|--help] [geographies]\n";
}

function show_help() {
	global $geos;

	show_usage();
	echo "Geographies\n";
	foreach ($geos as $key => $set) {
		echo "\t$key\t$set[name]\n";
	}
}

if ($argc < 2) {
	die(show_usage());
}

$_geos = array();
$output = 'maps';

for ($i = 1; $i < count($argv); $i++) {
	$arg = strtolower($argv[$i]);

	if ($arg[0] == '-') {

		switch ($arg) {
			case '-h':
			case '--help':
				die( show_help() );
			case '--output':
				$i++;
				if ($i >= count($argv))
					die( "Invalid directory specified" );

				$output = $argv[$i];
				break;
			case '--all':
				$_geos = $geos;
				break;
			default:
				die("$arg is an invalid option\n");
		}

	} elseif(!isset($geos[$arg])) {
		die("Error: $arg is not a valid geography\n");

	} else {
		$_geos[$arg] = $geos[$arg];
	}
}

$ftp = ftp_connect($tiger_ftp);
if (!$ftp)
	die('Failed to connect to ' . $tiger_ftp);

if (!ftp_login($ftp, $tiger_user, $tiger_pass)) {
	die('Failed to login to ' . $tiger_ftp);
}

ftp_pasv($ftp, $tiger_pasv);

foreach ($_geos as $geo) {
	$paths = ftp_nlist($ftp, $tiger_path . '/' . $geo['path']);
	if(!$paths)
		die('Failed to fetch list of paths for ' . $geo['name']);

	// Always go one level in
	foreach ($paths as $path) {
		echo $path . "\n";
		$files = ftp_nlist($ftp, $path);
		if (!$files)
			die('Failed to fetch list of files for ' . $geo['name']);

		foreach($files as $file) {
			if (preg_match($geo['regex'], $file) > 0) {

				$local = $output . '/' . trim(substr($file, strlen($tiger_path)), '/');
				$localdir = dirname($local);

				if (!file_exists($localdir))
					mkdir($localdir, 0777, true);

				echo "\t$local ";
				if (!file_exists($local) || ftp_size($ftp, $file) != filesize($local)) {
 					$ret = ftp_get($ftp, $local, $file, FTP_BINARY);
 					if (!$ret) {
 						echo "[ERROR]";
 					} else {
						echo "[OK]";
 					}
				} else {
					echo "[EXISTS]";
				}

				echo "\n";

			}
		}
	}
}

?>
