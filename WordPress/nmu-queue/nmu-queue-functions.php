<?php

/**
 * include this file in worker scripts
 *
 * this sets up necessary contstants and defines the get and update actions
 * for workers to use when requesting and completing tasks (or marking them as failed)
 * 
 */

define('NMU_QUEUE_JOBS_TABLE', 'wp_nmu_jobs');

define('NMU_JOB_STATUS_OPEN', 1);
define('NMU_JOB_STATUS_CLAIMED', 2);
define('NMU_JOB_STATUS_DONE', 3);
define('NMU_JOB_STATUS_ERROR', 4);

/**
 * get the next open job and update its status to "claimed"
 * @param PDO active database connection
 * @param string the queue you're working on
 * @return object|null the job be performed or null if error or no open jobs
 */
function nmu_queue_get_next_job($dbh, $queue) {
	//find the next open job
	$sql = "select * from " . NMU_QUEUE_JOBS_TABLE . " where queue = ? and status = ? order by created_at limit 1";
	$stmt = $dbh->prepare($sql);
	$stmt->execute([$queue, NMU_JOB_STATUS_OPEN]);
	$job = $stmt->fetch(PDO::FETCH_OBJ);
	
	if ($job) {
		//claim the job and register an attempt to complete
		$stmt = $dbh->prepare('update ' . NMU_QUEUE_JOBS_TABLE . ' set status = ?, attempts = ? where job_id = ?');
		$attempts = $job->attempts++;
		$result = $stmt->execute([NMU_JOB_STATUS_CLAIMED, $job->attempts++, $job->job_id]);

		if ($result) {
			//reload job object before returning to caller
			$stmt = $dbh->prepare("select * from " . NMU_QUEUE_JOBS_TABLE . " where job_id = ?");
			$stmt->execute([$job->job_id]);
			$job = $stmt->fetch(PDO::FETCH_OBJ);
			
			return $job;
		}
	}

	return null;
}

/**
 * update the status of a job
 * @param PDO active PDO database connection
 * @param int the job id
 * @param int job's new status
 * @return bool true on success, false on failure
 */
function nmu_queue_update_job($dbh, $job_id, $status) {

	$stmt = $dbh->prepare("update " . NMU_QUEUE_JOBS_TABLE . " set status = ? where job_id = ?");
	$result = $stmt->execute([$status, $job_id]);

	return $result;
}