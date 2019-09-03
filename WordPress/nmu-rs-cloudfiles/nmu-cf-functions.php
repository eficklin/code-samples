<?php
/**
* PUT file to CloudFiles
*/
function nmu_cf_put_file($local_path, $remote_path, $cf_auth) {
	if (!$cf_auth) {
		return false;
	}	

	$remote_path = rawurlencode($remote_path);

	$finfo = finfo_open(FILEINFO_MIME_TYPE);
	$type = finfo_file($finfo, $local_path);
	$size = filesize($local_path);
	
	$etag = md5_file($local_path);

	$fp = fopen($local_path, 'r');

	$curl_options = array(
		CURLOPT_URL => $cf_auth->serviceURL . '/' . CDN_CONTAINER . '/' . $remote_path,
		CURLOPT_HTTPHEADER => array(
			'X-Auth-Token: ' . $cf_auth->getToken(),
			'Content-Type: ' . $type,
			'Content-Length: ' . $size,
			'ETag: ' . $etag
		),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_PUT => true,
		CURLOPT_INFILE => $fp,
		CURLOPT_INFILESIZE => $size
	);

	$result = NMURackspaceAuth::docURLRequest($curl_options);
	if ($result['http_response'] == '201') {
		preg_match('/E[Tt]ag:\s*([a-f0-9]{32})/', $result['header'], $matches);
		if ($etag == $matches[1]) {
			return true;
		} else {
			error_log(sprintf("%s: Error PUTting file to CDN; ETags do not match.\n", date('r')), 3, CDN_ERROR_LOG);
			return false;
		}
	} else {
		error_log(sprintf("%s: Error PUTting file to CDN; API response code: %d\n", date('r'), $result['http_response']), 3, CDN_ERROR_LOG);
		return false;
	}
}

/**
* DELETE file from CloudFiles
*/
function nmu_cf_delete_file($remote_path, $cf_auth) {
	if (!$cf_auth) {
		return false;
	}

	$curl_options = array(
		CURLOPT_URL => $cf_auth->serviceURL . '/' . CDN_CONTAINER . '/' . $remote_path,
		CURLOPT_HTTPHEADER => array(
			'X-Auth-Token: ' . $cf_auth->getToken()
		),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CUSTOMREQUEST => 'DELETE',
	);

	$result = NMURackspaceAuth::docURLRequest($curl_options);
	if ($result['http_response'] == '204') {
		return true;
	} else {
		error_log(sprintf("%s: Error deleting file; API response code: %d\n", date('r'), $result['http_response']), 3, CDN_ERROR_LOG);
		return false;
	}
}