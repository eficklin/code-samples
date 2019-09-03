#!/usr/bin/php

<?php
//load config settings based on environment
if (isset($argv[1]) && file_exists($argv[1])) {
	require $argv[1];
} else {
	require '/var/www/html/build/wp-config.production.php';
}

require_once dirname(__FILE__) . '/rs-auth.php';
require_once dirname(__FILE__) . '/nmu-cf-functions.php';
$cf_auth = new NMURackspaceAuth('cloudFiles', 'ORD', RS_INTERNAL);

require_once dirname(__FILE__) . '/../nmu-queue/nmu-queue-functions.php';

$slack_webhook = 'https://hooks.slack.com/services/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';

try {
	$db = new PDO('mysql:dbname=' . DB_NAME . ';charset=utf8;host=' . DB_HOST, DB_USER, DB_PASSWORD);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log(date('r') . ': Cloudfiles worker, DB Connection failed: ' . $e->getMessage() . "\n", 3, CDN_ERROR_LOG);
    die();
}

while (true) {
	try {
		$job = nmu_queue_get_next_job($db, CDN_QUEUE);
		$ok = false;
		
		if ($job) {
			$task = unserialize($job->task);
			
			if ($task['action'] == 'upload') {
				if (file_exists($task['local_path'])) {
					$ok = nmu_cf_put_file($task['local_path'], $task['remote_pseudo_path'], $cf_auth);
				} else {
					error_log(date('r') . ": File to be uploaded not found " . $task['local_path'] . "\n", 3, CDN_ERROR_LOG);
				}
			} else if ($task['action'] == 'delete') {
				$ok = nmu_cf_delete_file($task['remote_pseudo_path'], $cf_auth);
			}
			
			if ($ok) {
				nmu_queue_update_job($db, $job->job_id, NMU_JOB_STATUS_DONE);
			} else {
				nmu_queue_update_job($db, $job->job_id, NMU_JOB_STATUS_ERROR);
			}
		}
	} catch (Exception $e) {
		error_log(date('r') . ': Cloudfiles worker exception; ' . $e->getMessage() . "\n", 3, CDN_ERROR_LOG);
		$ch = curl_init($slack_webhook);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => 'payload=' . json_encode(['channel' => '#technology', 'text' => "Cloudfiles worker exception: " . $e->getMessage() ])
		]);
		curl_exec($ch);
		curl_close($ch);
		die();
	}

	sleep(10);
}
?>