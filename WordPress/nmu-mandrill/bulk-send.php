#!/usr/bin/php
<?php
/*
 -r path to CSV with recipient emails
 -b path to txt or html file with message body
 -s subject string
 -n from name; optional, defaults to New Music USA
 -a from email address; optional, defaults to info@newmusicusa.org
 -t timestamp (YYYY-MM-DD HH:MM:SS) in UTC of when to send messages
*/
$args = getopt('r:b:s:n::a::t::');

require 'nmu-mandrill.php';

$mandrill = nm_mandrill_get_instance();
$message = nm_mandrill_get_default_message();

$recipients = [];
$csv = fopen($args['r'], 'r');
if ($csv) {
	while (($data = fgetcsv($csv)) !== false) {
		$recipients[] = [
			'email' => $data[0],
			'type' => 'to'
		];
	}

	$message['to'] = $recipients;
	fclose($csv);
}

$body = file_get_contents($args['b']);
$template_content = [
	['name' => 'main', 'content' => nl2br($body)]
];

$message['subject'] = $args['s'];

if ($args['a'] && $args['n']) {
	$message['from_name'] = $args['n'];
	$message['from_email'] = $args['a'];
} else {
	$message['from_name'] = "New Music USA";
	$message['from_email'] = "info@newmusicusa.org";
}

$message['tags'] = ['notifications', 'cli-bulk-send'];

try {
	if ($args['t']) {
		$outcome = $mandrill->messages->sendTemplate('nmu-basic', $template_content, $message, true, '', $args['t']);
	} else {
		$outcome = $mandrill->messages->sendTemplate('nmu-basic', $template_content, $message);
	}
	$attempted = count($outcome);
	$rejected = 0;
	$rejected_reasons = [
		'hard-bounce' => 0,
		'soft-bounce' => 0,
		'spam' => 0,
		'unsub' => 0,
		'custom' => 0,
		'invalid-sender' => 0,
		'invalid' => 0,
		'test-mode-limit' => 0,
		'unsigned' => 0,
		'rule' => 0
	];
	$invalid = 0;
	foreach ($outcome as $m) {
		if ($m['status'] == 'rejected') {
			$rejected++;
			$rejected_reasons[$m['reject_reason']]++;
		}
		if ($m['status'] == 'invalid') {
			$invalid++;
		}
	}
	if ($invalid || $rejected) {
		$out = "{$attempted} messages on your list, but there were errors\n";
		$out .= "Invalid: {$invalid}\n";
		$out .= "Rejected: {$rejected}\n";
		$out .= print_r($rejected_reasons, true);
	} else {
		$out = "{$attempted} messages sent.";
	}

	echo $out . "\n";
} catch (Exception $e) {
	echo get_class($e) . ": " . $e->getMessage() . "\n";
}