<?php
/*
*	Plugin Name: Dynamic CSV Importer
*	Plugin URI: http://dynamic-csv-importer.allstruck.org/
*	Description: Import to any post type from any CSV, including custom fields.
*	Version: 1.0
*	Author: ALLSTRUCK
*	Author URI: http://allstruck.com/
*
* Copyright (C) 2013 AllStruck (email : dynamic-csv-importer@allstruck.com)
*
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Define constants and globals for main plugin stuff:
define('DCSVI_PREFIX', 'dcsvi_');
define('DCSVI_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__) );
define('DCSVI_PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ));
global $data_rows, $headers, $default_fields, $wpdb, $keys, $delimiter;
$data_rows = array();
$headers = array();

// HTML Template library:
require_once DCSVI_PLUGIN_DIR_PATH . '/library/Twig/Autoloader.php';
Twig_Autoloader::register();
$html_template_directory = DCSVI_PLUGIN_DIR_PATH . '/view/template';
$html_template_cache_directory = DCSVI_PLUGIN_DIR_PATH . '/view/template_cache';
$template_loader = new Twig_Loader_Filesystem( $html_template_directory );
$twig = new Twig_Environment($template_loader, array('cache' => FALSE));


//echo $twig->render('index.html', array('name' => 'Fabien'));

// Set up folder in standard WP Uploads folder for temporary uploading of CSV file:
$upload_dir = wp_upload_dir();
$import_dir  = $upload_dir['basedir']."/import_temp/";
if (!is_dir($import_dir)) {
	wp_mkdir_p($import_dir);
}

// Set up folder for HTML Tempalte cache:
if (!is_dir($html_template_cache_directory)) {
	wp_mkdir_p($html_template_cache_directory);
}

// Set the delimiter:
$delimiter = empty($_POST['delim']) ? '' : $_POST['delim'];

// Set a limit for max number of allowable post meta fileds:
$limit = (int) apply_filters( 'postmeta_form_limit', 30 );

// Get key values for existing meta (custom fields):
$keys = $wpdb->get_col( 
	"SELECT meta_key
	FROM $wpdb->postmeta
	GROUP BY meta_key
	HAVING meta_key NOT LIKE '\_%'
	ORDER BY meta_key
	LIMIT $limit"
	);
// Build a list of default WordPress fields with empty default mapping:
$default_fields = array(
	'post_title'      => null,
	'post_content'    => null,
	'post_excerpt'    => null,
	'post_date'       => null,
	'post_tag'        => null,
	'post_category'	  => null,
	'post_author'     => null,
	'featured_image'  => null,
	'post_parent'     => 0,
	);

foreach($keys as $val) {
	$default_fields[$val]=$val;
}
// Add menu and interface to back-end (admin):
function dcsvi_add_admin_interface() {
	add_submenu_page( 'tools.php', 'Dynamic CSV Importer', 'CSV Importer', 'manage_options', 'dynamic_dcsvi_importer', 'upload_dcsvi_file');
	add_menu_page('CSV importer settings', 'CSV Importer', 'manage_options',  
		'upload_dcsvi_file', 'upload_dcsvi_file');
}
add_action("admin_menu", "dcsvi_add_admin_interface");

// Add JavaScript file to head:
function LoadWpScript() {
	wp_deregister_script( 'dynamic_dcsvi_importer_scripts' );
	wp_register_script('dynamic_dcsvi_importer_scripts', DCSVI_PLUGIN_DIR_URL . '/dynamic_dcsvi_importer.js', array('jquery'));
	wp_enqueue_script('dynamic_dcsvi_importer_scripts');

}
add_action('admin_enqueue_scripts', 'LoadWpScript');



function description() {
	// Return description of plugin:
	__('<p>Dynamic CSV Importer will add new posts, pages, or custom posts of a custom type from a CSV file. You do not need to change the CSV file; select the CSV file and map each field to the post field (custom meta fields are supported).</p>');
}

// Get all data from the CSV and set $headers to first line (keys):
function dcsvi_get_dcsvi_file_data($file,$delimiter) {
	// Set this to avoid missing lines on files with bad carriage return encoding:
	ini_set('auto_detect_line_endings', true);

	$data_rows = array();
	$headers = array();

	$resource = fopen($file, 'r');

	// Run through every row of CSV and add first row to $headers, all others to $data_rows:
	$first_round = TRUE;
	while ($keys = fgetcsv($resource,'',$delimiter,'"')) {
		if ($first_round) {
			$headers = $keys;
			$first_round = FALSE;
		} else {
			array_push($data_rows, $keys);
		}
	}
	fclose($resource);

	return array($headers, $data_rows, $delimiter);
}

// Move file
function move_file() {
	$upload_dir = wp_upload_dir();
	$uploads_dir  = $upload_dir['basedir'] . '/import_temp/';
	if ($_FILES['dcsvi_import']['error'] == 0) {
		$tmp_name = $_FILES['dcsvi_import']['tmp_name'];
		$name = $_FILES['dcsvi_import']['name'];
		move_uploaded_file($tmp_name, "$uploads_dir/$name");
	}
}

// Remove file
function fileDelete($filepath,$filename) {
	if (file_exists($filepath.$filename)&&$filename!=""&&$filename!="n/a") {
		unlink ($filepath.$filename);
		return TRUE;
	}
	return FALSE;
}

// Map the fields and upload data:
function upload_dcsvi_file() {
	global $headers, $data_rows, $default_fields, $keys, $custom_fields, $delimiter, $twig;

	$upload_dir = wp_upload_dir();
	$import_dir  = $upload_dir['basedir'] . '/import_temp/';
	$custom_fields = array();

	$post_as_status = isset($_POST['status']) ? $_POST['status'] : "draft";
	
	if (isset($_POST['Import'])) { // File has been submitted for import:
		list($headers, $data_rows, $delimiter) = dcsvi_get_dcsvi_file_data($_FILES['dcsvi_import']['tmp_name'],$delimiter);
		move_file();
		
		if ( count($headers)>=1 &&  count($data_rows)>=1 ) { 
			// File has at least one row and at least one field.
			
			// Show HTML template for mapping fields.
			echo $twig->render('admin-import-map.html', 
				array(
					'cache' => FALSE,
					'debug' => TRUE,
					'post_as_status' => $post_as_status,
					'draft_checked' => ($post_as_status == 'draft') ? TRUE : FALSE,
					'post_types' => get_post_types(array('public' => TRUE), 'objects'),
					'headers' => $headers,
					'defaults' => $default_fields,
					'previousuploadfile' => $_FILES['dcsvi_import']['name'],
					'defaultfieldscount' => count($default_fields)+2,
					'header_count' => count($headers),
					'default_count' => count($default_fields),
					'results' => description(),
					'deliminator' => $delimiter,
					'header_array' => $headers,
					'post_type' => $_POST['dcsvi-post-type'],
					'post_status' => $_POST['dcsvi-post-status']
					));

		} else { // File appears to have less than one row or less than one field:
			echo $twig->render('admin-import-error.html', 
				array(
					'cache' => FALSE,
					'debug' => TRUE,
					));
		}
	} elseif (isset($_POST['post_csv'])) { // File has been uploaded and fields have been mapped, start importing the file into posts and meta:
		// $upload_dir = wp_upload_dir();
		// $dir  = $upload_dir['basedir']."/import_temp/";
		// dcsvi_get_dcsvi_file_data($dir.$_POST['filename'],$delimiter);
		// foreach ($_POST as $postkey=>$postvalue) {
		// 	if ($postvalue != '-- Select --') {
		// 		$ret_array[$postkey]=$postvalue;
		// 	}
		// }
		// foreach($data_rows as $key => $value) {
		// 	for ($i=0;$i<count($value) ; $i++) {
		// 		if (array_key_exists('mapping'.$i,$ret_array)) {
		// 			if ($ret_array['mapping'.$i]!='add_custom'.$i) {
		// 				$new_post[$ret_array['mapping'.$i]] = $value[$i];
		// 			} else {
		// 				$new_post[$ret_array['textbox'.$i]] = $value[$i];
		// 				$custom_fields[$ret_array['textbox'.$i]] = $value[$i];
		// 			}
		// 		}
		// 	}
		// 	for ($inc=0;$inc<count($value);$inc++) {
		// 		foreach($keys as $k => $v) {
		// 			if (array_key_exists($v,$new_post)) {
		// 				$custom_fields[$v] =$new_post[$v];
		// 			}
		// 		}
		// 	}
		// 	foreach ($new_post as $post_key => $cval) {
		// 		if ($post_key!='post_category' && $post_key!='post_tag' && $post_key!='featured_image') {
		// 			if (array_key_exists($post_key,$custom_fields)) {
		// 				$darray[$post_key]=$new_post[$post_key];
		// 		   	} else {
		// 		   		$data_array[$post_key]=$new_post[$post_key];
		// 		   	}
  //  				} else {
		// 	   		if ($post_key == 'post_tag') {
		// 	   			$tags[$post_key]=$new_post[$post_key];
  //  					}
  //  					if ($post_key == 'post_category') {
  //  						$categories[$post_key]=$new_post[$post_key];
  //  					}
		// 			if ($post_key == 'featured_image') {
		// 				$file_url=$filetype[$post_key]=$new_post[$post_key];
		// 				$file_type = explode('.',$filetype[$post_key]);
		// 				$count = count($file_type);
		// 				$type= $file_type[$count-1];
		// 				if ($type == 'png') {
		// 					$file['post_mime_type']='image/png';
		// 				}
		// 				else if ($type == 'jpg') {
		// 					$file['post_mime_type']='image/jpeg';
		// 				}
		// 				else if ($type == 'gif') {
		// 					$file['post_mime_type']='image/gif';
		// 				}
		// 				$img_name = explode('/',$file_url);
		// 				$imgurl_split = count($img_name);
		// 				$img_name = explode('.',$img_name[$imgurl_split-1]);
		// 				$img_title = $img_name = $img_name[0];
		// 				$dir = wp_upload_dir(); 
		// 				$dirname = 'featured_image';
		// 				$full_path = $dir['basedir'].'/'.$dirname;
		// 				$baseurl = $dir['baseurl'].'/'.$dirname;
		// 				$filename = explode('/',$file_url);
		// 				$file_split = count($filename);
		// 				$filepath = $full_path.'/'.$filename[$file_split-1];
		// 				$fileurl = $baseurl.'/'.$filename[$file_split-1];
		// 				// Make directory for image if one doesn't exist:
		// 				if (!is_dir($full_path)) {
		// 					wp_mkdir_p($full_path);
		// 				}
		// 				copy($file_url,$filepath);
		// 				$file['guid']=$fileurl;
		// 				$file['post_title']=$img_title;
		// 				$file['post_content']='';
		// 				$file['post_status']='inherit';
		// 			}
		// 		}
		// 	}
		// 	$data_array['post_status']='publish';
		// 	if (isset($_POST['dcsvi_importer_import_as_draft'])) {
		// 		$data_array['post_status']='draft';
		// 	}
		// 	$data_array['post_type']=$_POST['dcsvi_importer_cat'];
		// 	$post_id = wp_insert_post( $data_array );
		// 	if (!empty($custom_fields)) {
		// 		foreach($custom_fields as $custom_key => $custom_value) {
		// 			add_post_meta($post_id, $custom_key, $custom_value);
		// 		}
		// 	}

		// 	// Create/Add tags to post
		// 	if (!empty($tags)) { // We have tags to add:
		// 		foreach($tags as $tag_key => $tag_value) {
		// 			wp_set_post_tags( $post_id, $tag_value );
		// 		}
		// 	}  // End of code to add tags

		// 		// Create/Add category to post
		// 	if (!empty($categories)) { // We have categories to add:
		// 		$split_line = explode('|',$categories['post_category']);
		// 		wp_set_object_terms($post_id, $split_line, 'category');

		// 	}

		// 	if (!empty($file)) { // We have a file to 
		// 		$file_name=$dirname.'/'.$img_title.'.'.$type;
		// 		$attach_id = wp_insert_attachment($file, $file_name, $post_id);
		// 		require_once(ABSPATH . 'wp-admin/includes/image.php');
		// 		$attach_data = wp_generate_attachment_metadata( $attach_id, $fileurl );
		// 		wp_update_attachment_metadata( $attach_id, $attach_data );
		// 		//add_post_meta($post_id, '_thumbnail_id', $attach_id, true);
		// 		set_post_thumbnail( $post_id, $attach_id );
		// 	}
		// }

		echo $twig->render('admin-import-finish.html', 
			array(
				'cache' => FALSE,
				'debug' => TRUE,
				'main_status_message' => __('Import Status'),
				));


		// Remove CSV file
		$upload_dir = wp_upload_dir();
		$csvdir  = $upload_dir['basedir']."/import_temp/";
		$CSVfile = $_POST['filename'];
		if (file_exists($csvdir.$CSVfile)) {
			chmod("$csvdir"."$CSVfile", 755);
			fileDelete($csvdir,$CSVfile);
		}
	} else { // File not submitted for import:
		echo $twig->render('admin-import-start.html', 
			array(
				'cache' => FALSE,
				'debug' => TRUE,
				'page_title' => __('Upload CSV File'),
				'delimiter_label' => __('Delimiter'),
				'post_type_label' => __('Post Type'),
				'upload_file_label' => __('Upload File'),
				'post_status_label' => __('Post Status'),
				'post_status_list' => array(
					'Published' => 'published' , 
					'Draft' => 'draft'),
				'post_as_status' => $post_as_status,
				'draft_checked' => ($post_as_status == 'draft') ? TRUE : FALSE,
				'post_types' => get_post_types(array('public' => TRUE), 'objects'),
				'description_text' => description(),
				'deliminator' => $delimiter,
				'header_array' => $headers,
				));
	}
}

// Pull data from CSV file and mapping from POST vars...
// Merge data into one $post variable, then save that to the options table serialized.
function dcsvi_preprocess_dcsvi_to_post() {
	$post = array();
	$custom_fields = array();
	$upload_dir = wp_upload_dir();
	$import_dir  = $upload_dir['basedir'] . '/import_temp/';
	
	list($headers, $dcsvi_rows, $delimiter) = dcsvi_get_dcsvi_file_data($dir.$_POST['filename'],$delimiter);
	
	// Put our mapping data (user input) into $mapping_data:
	foreach ($_POST as $postkey=>$postvalue) {
		if ($postvalue != '-- Select --') {
			$mapping_data[$postkey] = $postvalue;
		}
	}
	
	// Start building up $new_post based on mapping data.
	foreach($dcsvi_rows as $key => $value) {
		for ($i=0;$i<count($value) ; $i++) {
			if ( array_key_exists('mapping-'.$i, $mapping_data) ) {
				$mapping_val = $mapping_data['mapping-'.$i];
				$textbox_val = $mapping_data['textbox-'.$i];
				if ( $mapping_val != 'add_custom-' . $i ) {
					$new_post[$mapping_val] = $value[$i];
				} else {
					$new_post[$textbox_val] = $value[$i];
					$custom_fields[$textbox_val] = $value[$i];
				}
			}
		}
		for ( $inc=0; $inc<count($value); $inc++ ) {
			foreach($keys as $k => $v) {
				if (array_key_exists($v,$new_post)) {
					$custom_fields[$v] = $new_post[$v];
				}
			}
		}
		foreach ($new_post as $post_key => $cval) {
			if ($post_key!='post_category' && $post_key!='post_tag' && $post_key!='featured_image') {
				if (array_key_exists($post_key,$custom_fields)) {
					$darray[$post_key] = $new_post[$post_key];
			   	} else {
			   		$data_array[$post_key] = $new_post[$post_key];
			   	}
				} else {
		   		if ($post_key == 'post_tag') {
		   			$tags[$post_key]=$new_post[$post_key];
				}
				if ($post_key == 'post_category') {
					$categories[$post_key]=$new_post[$post_key];
				}
				if ($post_key == 'featured_image') {
					$file_url=$filetype[$post_key]=$new_post[$post_key];
					$file_type = explode('.',$filetype[$post_key]);
					$count = count($file_type);
					$type= $file_type[$count-1];
					if ($type == 'png') {
						$file['post_mime_type']='image/png';
					}
					else if ($type == 'jpg') {
						$file['post_mime_type']='image/jpeg';
					}
					else if ($type == 'gif') {
						$file['post_mime_type']='image/gif';
					}
					$img_name = explode('/',$file_url);
					$imgurl_split = count($img_name);
					$img_name = explode('.',$img_name[$imgurl_split-1]);
					$img_title = $img_name = $img_name[0];
					$dir = wp_upload_dir(); 
					$dirname = 'featured_image';
					$full_path = $dir['basedir'].'/'.$dirname;
					$baseurl = $dir['baseurl'].'/'.$dirname;
					$filename = explode('/',$file_url);
					$file_split = count($filename);
					$filepath = $full_path.'/'.$filename[$file_split-1];
					$fileurl = $baseurl.'/'.$filename[$file_split-1];

					// Make directory for image if one doesn't exist:
					if (!is_dir($full_path)) {
						wp_mkdir_p($full_path);
					}
					
					copy($file_url,$filepath);
					$file['guid']=$fileurl;
					$file['post_title']=$img_title;
					$file['post_content']='';
					$file['post_status']='inherit';
				}
			}
		}
		
		$data_array['post_status'] = $_POST['dcsvi_import_status'];
		$data_array['post_type']=$_POST['dcsvi_importer_cat'];
		
		//$post_id = wp_insert_post( $data_array );
		if (!empty($custom_fields)) {
			foreach($custom_fields as $custom_key => $custom_value) {
				$post[$custom_key] = $custom_value;
				//add_post_meta($post_id, $custom_key, $custom_value);
			}
		}

		// Create/Add tags:
		if (!empty($tags)) { // We have tags to add:
			foreach($tags as $tag_key => $tag_value) {
				//wp_set_post_tags( $post_id, $tag_value );
			}
		} 

		// Create/Add category:
		if (!empty($categories)) { // We have categories to add:
			$split_line = explode('|',$categories['post_category']);
			//wp_set_object_terms($post_id, $split_line, 'category');
		}

		if (!empty($file)) {
			$file_name=$dirname.'/'.$img_title.'.'.$type;
			$attach_id = wp_insert_attachment($file, $file_name, $post_id);
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			$attach_data = wp_generate_attachment_metadata( $attach_id, $fileurl );
			wp_update_attachment_metadata( $attach_id, $attach_data );
			set_post_thumbnail( $post_id, $attach_id );
		}
	}
}

// Posts are added from the CSV file by:
//	- Converting the mapping specified by the user into a $post variable.
//	- Adding the actual post data from the CSV file into $post.
//	- Batching together 10 posts into a "job".
//	- Sending this job to WP-Cron with a scheduling handler function.
//	- Scheduling handler will run every five seconds doing the following:
//		- If all five posts have been added:
//			- Remove this job from cron.
//			- Batch together the next five in a new job and send to cron.
//		- Else:
//			- Run the insert function again for each of the posts.
//		- Update the status message for this job.

// Adds one post including tags, categories, or other post meta:
function dcsvi_add_post($input_data) {
	$post = array(
	'ID' => $input_data[$input_data['ID_field']], 
	'menu_order' => $input_data[$input_data['menu_order_field']], 
	'comment_status' => $input_data[$input_data['comment_status_field']], 
	'ping_status' => $input_data[$input_data['ping_status_field']], 
	'pinged' => $input_data[$input_data['pinged_field']], 
	'post_author' => $input_data[$input_data['post_author_field']], 
	'post_category' => $input_data[$input_data['post_category_field']], 
	'post_content' => $input_data[$input_data['post_content_field']], 
	'post_date' => $input_data[$input_data['post_date_field']], 
	'post_date_gmt' => $input_data[$input_data['post_date_gmt_field']], 
	'post_excerpt' => $input_data[$input_data['post_excerpt_field']], 
	'post_name' => $input_data[$input_data['post_name_field']], 
	'post_parent' => $input_data[$input_data['post_parent_field']], 
	'post_password' => $input_data[$input_data['post_password_field']], 
	'post_status' => $input_data[$input_data['post_status_field']], 
	'post_title' => $input_data[$input_data['post_title_field']], 
	'post_type' => $input_data[$input_data['post_type_field']], 
	'tags_input' => $input_data[$input_data['tags_input_field']], 
	'to_ping' => $input_data[$input_data['to_ping_field']], 
	'tax_input' => $input_data[$input_data['tax_input_field']]);
	
	// Insert the post
	if ($wp_insert == wp_insert_post( $post, $wp_error = TRUE )) {
		// TODO: Insert tags, categories, and custom fields:

		return TRUE;
	}
	if ($error) {
		return $wp_insert;
	}
	return FALSE;
}

// Save post and mapping data in one options field of the database.
function dcsvi_save_post_data_to_options($input_data, $job_id) {
	if (!get_option("dcsvi_post_data_$job_id")) {
		add_option( "dcsvi_post_data_$job_id", $input_data, '', 'no' );
		return TRUE;
	}
	return FALSE;
}

// Start a new job (batch of 10 posts)
function dcsvi_new_job($posts) {
	$new_job_id = uniqid();
	dcsvi_save_post_data_to_options($posts, $new_job_id);
	// Start WP-Cron job to make sure this finishes and handle moving on to next parts:
	wp_schedule_event( time(), 'five_seconds', 'dcsvi_job_scheduler', array($new_job_id) );
	if (!get_option("dcsvi_running_job_$new_job_id")) {
		add_option( "dcsvi_running_job_$new_job_id", 'Started...', '', 'no');
	}
}

function dcsvi_complete_job($job_id) {
	if (!get_option("dcsvi_running_job_$job_id")) {
		add_option( "dcsvi_running_job_$job_id", 'Started...', '', 'no');
	}
}

function dcsvi_abort_job($job_id, $message) {
}

function dcsvi_job_scheduler($job_id) {
	$completed = (array)get_option("dcsvi_job_completed_$job_id");
	$active = (array)get_option("dcsvi_job_active_$job_id");

	// Check to see if there are still posts to add:
	if (count($active) > 0) {
		// For each post still waiting to be added:
		foreach ($active as $post) {
			// Add post:
			dcsvi_add_new_post($post);
		}
	} else {
		// Our $active queue is empty, 
		//  let's make sure everything in $completed is there first:
		foreach ($completed as $post) {

		}
		dcsvi_complete_job($job_id);
	}

	if (!get_option( "dcsvi_job_status_$job_id", $default = false )) {
		add_option( "dcsvi_job_status_$job_id", 'Currently working on ', '', 'no' );
	} else {
		update_option( "dcsvi_job_status_$job_id", '' );
	}
	if (!get_option( "dcsvi_job_part_$job_id", $default = false )) {
		add_option( "dcsvi_job_part_$job_id", "", '', 'no' );
	}
}

// Add "five_seconds" as a time supported by WP-Cron:
add_filter( 'cron_schedules', 'dcsvi_cron_add_five_seconds' );
function dcsvi_cron_add_five_seconds( $schedules ) {
	// Adds once weekly to the existing schedules.
	$schedules['five_seconds'] = array(
		'interval' => 5,
		'display' => __( 'Every Five Seconds' )
	);
	return $schedules;
}