<?php
/**
 * Plugin Name: NMU Jobs Queue
 * Description: queue service for non-synchronous/background tasks
 * Version: 1.1
 * Author: New Music USA
 */

define('NMU_QUEUE_VERSION', '1.1');

include 'nmu-queue-functions.php';

register_activation_hook(__FILE__, 'nmu_queue_install');

function nmu_queue_install() {
	global $wpdb;
	$installed_version = get_option('nmu_queue_version');

	if ($installed_version != NMU_QUEUE_VERSION) {
		$table_name = NMU_QUEUE_JOBS_TABLE;

		$sql = "CREATE TABLE $table_name (
			job_id BIGINT(20) NOT NULL AUTO_INCREMENT,
			queue VARCHAR(255),
			task text,
			created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			status TINYINT DEFAULT 1,
			updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			attempts TINYINT DEFAULT 0,
			PRIMARY KEY (job_id),
			KEY queue (queue),
			KEY status (status)
		);";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		update_option('nmu_queue_version', NMU_QUEUE_VERSION);
	}
}

function nmu_queue_update_db_check() {
    if (get_option('nmu_queue_version') != NMU_QUEUE_VERSION) {
        nmu_queue_install();
    }
}
add_action('plugins_loaded', 'nmu_queue_update_db_check');

register_uninstall_hook(__FILE__, 'nmu_queue_uninstall');

function nmu_queue_uninstall() {
	global $wpdb;

	$table_name = NMU_QUEUE_JOBS_TABLE;
	$wpdb->query("drop table {$table_name}");

	delete_option('nmu_queue_version');
}

/**
 * add a job to a queue
 * @param string the queue to add the job to
 * @param string|array details of the task to be completed
 * @return bool true on success, false on failure
 */
function nmu_queue_add_job($queue, $task) {
	global $wpdb;

	if (is_array($task)) {
		$task = serialize($task);
	}

	$table = NMU_QUEUE_JOBS_TABLE;
	$sql = "insert into wp_nmu_jobs (queue, task) values (%s, %s)";
	$result = $wpdb->query($wpdb->prepare($sql, $queue, $task));

	if ($result === 1) {
		return true;
	} else {
		return false;
	}
}

/**
 * delete a job from the jobs table
 * @param int id of the job to delete
 * @return bool true on success, false on failure
 */
function nmu_queue_delete_job($job_id) {
	global $wpdb;

	$table = NMU_QUEUE_JOBS_TABLE;
	$sql = "delete from {$table} where job_id = %d";
	$result = $wpdb->query($wpdb->prepare($sql, $job_id));

	if ($result === 1) {
		return true;
	} else {
		return false;
	}
}
