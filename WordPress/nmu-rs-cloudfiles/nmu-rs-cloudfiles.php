<?php
/*
Plugin Name: New Music USA CDN
Description: Queues post attachements for uploading to CDN; rewrites post attachment URLs to be served from CDN
Version: 1.0
Author: New Music USA
*/

require_once dirname(__FILE__) . '/rs-auth.php';
require_once dirname(__FILE__) . '/nmu-cf-functions.php';

function nmu_rs_cloudfiles_enqueue_new($meta_id, $object_id, $meta_key, $_meta_value) {
	$current_user = wp_get_current_user();
	$files_to_upload = array();

	switch($meta_key) {
		case '_wp_attachment_metadata':
			if ($parent = wp_get_post_parent_id($object_id)) {
				$post = get_post($parent);
				$date_mask = date('Y/m', strtotime($post->post_date));
				$upload_dir = wp_upload_dir($date_mask);
			} else {
				$upload_dir = wp_upload_dir();
			}
			foreach ($_meta_value['sizes'] as $size) {
				$files_to_upload[] = array(
					'local_path' => $upload_dir['path'] . '/' . $size['file'],
					'remote_pseudo_path' => trim($upload_dir['subdir'], '/')  . '/' . $size['file'],
					'action' => 'upload'
				);
			}
			break;
		case '_wp_attached_file':
			$upload_dir = wp_upload_dir();
			$files_to_upload[] = array(
				'local_path' => $upload_dir['basedir'] . '/' . $_meta_value,
				'remote_pseudo_path' => $_meta_value,
				'action' => 'upload'
			);
			break;
		case 'nm_profile_original_image':
		case 'nm_project_original_image':
			$upload_dir = wp_upload_dir();
			$files_to_upload[] = array(
				'local_path' => $upload_dir['basedir'] . '/' . $_meta_value,
				'remote_pseudo_path' => $_meta_value,
				'action' => 'upload'
			);
			break;
	}
	
	if (count($files_to_upload)) {
		if (in_array('administrator', $current_user->roles)) {
			//uploads by admins get priority, PUT files immediately to CDN
			$cf_auth = new NMURackspaceAuth('cloudFiles', 'ORD', RS_INTERNAL);
			foreach ($files_to_upload as $f) {
				$ok = nmu_cf_put_file($f['local_path'], $f['remote_pseudo_path'], $cf_auth);
				if (!$ok) {
					error_log('direct PUT to CDN failed for ' . $f['local_path']);
				} 
			}
		} else {
			//regular user requests are queued
			foreach ($files_to_upload as $f) {
				nmu_queue_add_job(CDN_QUEUE, $f);
			}
		}
	}
}
add_action('added_post_meta', 'nmu_rs_cloudfiles_enqueue_new', 10, 4);

function nmu_rs_cloudfiles_enqueue_update($meta_id, $object_id, $meta_key, $_meta_value) {
	if ($parent = wp_get_post_parent_id($object_id)) {
		$post = get_post($parent);
		$date_mask = date('Y/m', strtotime($post->post_date));
		$upload_dir = wp_upload_dir($date_mask);
	} else {
		$upload_dir = wp_upload_dir();
	}

	$files_to_upload = array();

	if ($meta_key == '_wp_attachment_metadata') {		
		foreach ($_meta_value['sizes'] as $size) {
			$files_to_upload[] = array(
				'local_path' => $upload_dir['path'] . '/' . $size['file'],
				'remote_pseudo_path' => trim($upload_dir['subdir'], '/')  . '/' . $size['file'],
				'action' => 'upload'
			);
		}
	}

	if ($meta_key == '_wp_attached_file') {
		$files_to_upload[] = array(
			'local_path' => $upload_dir['basedir'] . '/' . $_meta_value,
			'remote_pseudo_path' => $_meta_value,
			'action' => 'upload'
		);
	}

	if (count($files_to_upload)) {
		if (in_array('administrator', $current_user->roles)) {
			//uploads by admins get priority, PUT files immediately to CDN
			$cf_auth = new NMURackspaceAuth('cloudFiles', 'ORD', RS_INTERNAL);
			foreach ($files_to_upload as $f) {
				$ok = nmu_cf_put_file($f['local_path'], $f['remote_pseudo_path'], $cf_auth);
				if (!$ok) {
					error_log('direct PUT to CDN failed for ' . $f['local_path']);
				} 
			}
		} else {
			//regular user requests are queued
			foreach ($files_to_upload as $f) {
				nmu_queue_add_job(CDN_QUEUE, $f);
			}
		}
	}	
}
add_action('updated_post_meta', 'nmu_rs_cloudfiles_enqueue_update', 10, 4);

function nmu_rs_cloudfiles_enqueue_remove($postid) {
	global $wpdb;
	$files_to_delete = array();

	$sql = $wpdb->prepare(
		"SELECT meta_value 
		FROM " . $wpdb->prefix . "postmeta 
		WHERE post_id = %d AND (meta_key = '_wp_attached_file' OR meta_key = '_wp_attachment_metadata')",
		$postid
	);
	$results = $wpdb->get_results($sql);
	if ($wpdb->num_rows > 0) {
		foreach ($results as $row) {
			$attachment = maybe_unserialize($row->meta_value);
			if (is_array($attachment)) {
				if ($attachment['sizes']) {
					$attachment_folder = pathinfo($attachment['file']);
					$attachment_folder = ($attachment_folder['dirname'] != '') ? trim($attachment_folder['dirname'], '/') . '/' : '';
					//check the queue first, if job unclaimed delete record
					//if complete, enqueue a removal job
					foreach ($attachment['sizes'] as $size) {
						$files_to_delete[] = array(
							'remote_pseudo_path' => $attachment_folder . $size['file'],
							'action' => 'delete'
						);
					}
					//if claimed or error, do nothing
				}
			} else {
				$files_to_delete[] = array(
					'remote_pseudo_path' => $row->meta_value,
					'action' => 'delete'
				);
			}
		}
	}

	if ($original_image_path = get_post_meta($postid, 'nm_project_original_image', true)) {
		$files_to_delete[] = array(
			'remote_pseudo_path' => $original_image_path,
			'action' => 'delete'
		);
	}

	if ($original_image_path = get_post_meta($postid, 'nm_profile_original_image', true)) {
		$files_to_delete[] = array(
			'remote_pseudo_path' => $original_image_path,
			'action' => 'delete'
		);
	}


	if (count($files_to_delete)) {
		foreach ($files_to_delete as $f) {
			nmu_queue_add_job(CDN_QUEUE, $f);
		}
	}
}
//add_action('delete_attachment', 'nmu_rs_cloudfiles_enqueue_remove');

/**
 * replace attachment URLs with CDN URLs if items do not exist locally (covers post content and attachments)
 */
function nmu_rs_cloudfiles_set_cdn_url($content) {
	$upload_dir = wp_upload_dir();

	switch (current_filter()) {
		case 'wp_get_attachment_url':

			//first, check on local server (cheapest check?)
			if (file_exists(str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $content))) {
				return $content;
			}			
			
			if (LOADSHARING_ON) { //need to check on multiple nodes for local file
				$loadsharing_servers = unserialize(LOADSHARING_SERVER_IPS);
				foreach ($loadsharing_servers as $server) {
					//don't check on THIS server
					if ($server == $_SERVER['SERVER_ADDR']) continue;
					$server_url = 'http://' . $server . '/wp-content/uploads';
					$test_url = str_replace($upload_dir['baseurl'], $server_url, $content);
					$local_headers = @get_headers($test_url);
					$local_file_error = ((strpos($local_headers[0],'404') !== false) || (strpos($local_headers[0],'Not Found') !== false));
					if (!$local_file_error) {
						return $test_url;
					}
				}
			}

			//if still not found, convert to CDN url
			$cdn_url = str_replace($upload_dir['baseurl'], NMU_CDN_PUBLIC_URL, $content);

			//if mp3, check on CDN and if not found, force a 404 error to prevent unpredictable mp3 player behavior
			$ext = pathinfo(parse_url($cdn_url, PHP_URL_PATH), PATHINFO_EXTENSION); //get file extension from url
			if ($ext == 'mp3') { 
				$file_headers = @get_headers($cdn_url);
				$cdn_file_error = (strpos($file_headers[0],'200') === false);
				if ($cdn_file_error) { // file exists on CDN
					return $url = str_replace($upload_dir['baseurl'], $upload_dir['baseurl'] . '/404', $content);
				} 
			}

			//output the url on the CDN, handle 404 images via css
			return $cdn_url;
			break;

	default:
		preg_match_all('/"(http|https).*?\/wp\-content\/.*?\/\d{4}+\/\d{2}+\/.*?"/i', $content, $matches);
		foreach($matches[0] as $match) {
			if (!file_exists(str_replace($upload_dir['baseurl'], $upload_dir['basedir'], trim($match, '"')))) {
				$new_url = str_replace($upload_dir['baseurl'], NMU_CDN_PUBLIC_URL, $match);
				$content = str_replace($match, $new_url, $content);
			}
		}
		return $content;
	}
}
//add_filter('the_content', 'nmu_rs_cloudfiles_set_cdn_url');
//add_filter('richedit_pre', 'nmu_rs_cloudfiles_set_cdn_url');
//add_filter('wp_get_attachment_url', 'nmu_rs_cloudfiles_set_cdn_url');

/**
 * LEGACY SUPPORT
 * replace profile image urls with CDN URLs if items do not exist locally; our OLD notion of profile images
 * existed mostly outside the WP media framework and require this extra bit special handling
 */
function nmu_rs_cloudfiles_profile_image_urls($value, $object_id, $meta_key, $single) {
	if ($meta_key == 'profile_image_bio' || $meta_key == 'profile_image_org' || $meta_key == 'profile_image_48') {
		$upload_dir = wp_upload_dir();
		$meta_cache = wp_cache_get($object_id, 'user_meta');

		if ( !$meta_cache ) {
			$meta_cache = update_meta_cache( 'user', array( $object_id ) );
			$meta_cache = $meta_cache[$object_id];
		}

		if (isset($meta_cache[$meta_key])) {
			$meta_value = maybe_unserialize($meta_cache[$meta_key][0]);
			if (file_exists($upload_dir['basedir'] . '/' . $meta_value)) {
				return $upload_dir['baseurl'] . '/' . $meta_value;
			} else {
				return NMU_CDN_PUBLIC_URL . '/' . $meta_value;
			}	
		}
	}
}
add_filter('get_user_metadata', 'nmu_rs_cloudfiles_profile_image_urls', 10, 4);

/**
 * ensures existing files are not overwritten should another file
 * with the same name come along; can't rely on WP's default check of the local
 * filesystem, since successful transfer to CDN triggers deletion of local files
 * and a local filesystem check doesn't make sense in a distributed setup
 * ADDENDUM: the guid test isn't working as expected.  Adding the current logged in user id to beginning of file.
 */
function nmu_rs_cloudfiles_unique_filename($filename, $filename_raw = null) {
	global $current_user;
	global $wpdb;

	$current_user_id = $current_user->ID;

	$upload_info = pathinfo($filename);

	$filename =  $current_user_id . '-' . $upload_info['filename'] . '.' . $upload_info['extension'];

	$filenamewithuserappended  =  $current_user_id . '-' . $upload_info['filename'] ;


	$existing_files = $wpdb->get_results(
		"SELECT guid FROM " . $wpdb->prefix . "posts WHERE guid LIKE '%" . $filenamewithuserappended . "%" . $upload_info['extension'] . "'"
	);

	if (count($existing_files)) {
		$existing_files = array_map(function($f){ return basename($f->guid); }, $existing_files);
		$i = 1;
		while (in_array($filename, $existing_files)) {
			$filename = $upload_info['filename'] . $i . '.' . $upload_info['extension'];
			$i++;
		}
	}

	return $filename;
}
add_filter('sanitize_file_name', 'nmu_rs_cloudfiles_unique_filename', 10, 2);
