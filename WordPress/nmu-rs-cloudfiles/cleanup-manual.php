#!/usr/bin/php
<?php
/**
 * clean up script (run at the command line or by cron) for files missed by workers
 */

//load config settings based on environment
if (isset($argv[1]) && file_exists($argv[1])) {
	require $argv[1];
} else {
	require '/var/www/html/build/wp-config.production.php';
}

include '/var/www/html/build/wp-content/plugins/nmu-rs-cloudfiles/rs-auth.php';

$files_present = array();
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOADS_DIR, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

foreach ($files as $fileinfo) {
	$full_path = $fileinfo->getRealPath();
	if (!is_dir($full_path)) {
		$files_present[] = $fileinfo->getRealPath();
	}
}

if ($present = count($files_present)) {
	$missed = [];
	$caught = 0;
	$cf_auth = new NMURackspaceAuth('cloudFiles', 'ORD', RS_INTERNAL);

	foreach($files_present as $f) {
		$headers = get_headers(NMU_CDN_PUBLIC_URL . str_replace(UPLOADS_DIR, '', $f));
		if ($headers[0] != 'HTTP/1.0 200 OK') {
			$missed[] = $f;

			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$type = finfo_file($finfo, $f);
			$size = filesize($f);
			$fp = fopen($f, 'r');

			$remote_path = rawurlencode(str_replace(UPLOADS_DIR . '/', '', $f));

			$curl_options = array(
				CURLOPT_URL => $cf_auth->serviceURL . "/" . CDN_CONTAINER . "/{$remote_path}",
				CURLOPT_HTTPHEADER => array(
					'X-Auth-Token: ' . $cf_auth->getToken(),
					'Content-Type: ' . $type,
					'Content-Length: ' . $size
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_PUT => true,
				CURLOPT_INFILE => $fp,
				CURLOPT_INFILESIZE => $size
			);

			$result = NMURackspaceAuth::docURLRequest($curl_options);
			if ($result['http_response'] == '201') {
				$caught++;	
			} else {
				echo sprintf("PUT request returned a %d response.\n", $result['http_response']);
			}
		}
	}

	$log_msg = date('r') . sprintf(" %s: %d files present; %d files missed; %d files transferred\n", gethostname(), $present, count($missed), $caught);
	
	error_log($log_msg, 3, CDN_ERROR_LOG);
	error_log(implode("\n", $missed), 3, CDN_ERROR_LOG);

	$slack_webhook = 'xxx';
	$ch = curl_init($slack_webhook);
	curl_setopt_array($ch, [
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => 'payload=' . json_encode(['channel' => '#technology', 'text' => $log_msg])
	]);
	curl_exec($ch);
	curl_close($ch);
}
?>