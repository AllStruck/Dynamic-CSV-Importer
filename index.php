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
	add_submenu_page( 'tools.php', 'Dynamic CSV Importer', 'CSV Importer', 'manage_options', 'dynamic_csv_importer', 'upload_csv_file');
	add_menu_page('CSV importer settings', 'CSV Importer', 'manage_options',  
		'upload_csv_file', 'upload_csv_file');
}
add_action("admin_menu", "dcsvi_add_admin_interface");

// Add JavaScript file to head:
function LoadWpScript() {
	wp_deregister_script( 'dynamic_csv_importer_scripts' );
	wp_register_script('dynamic_csv_importer_scripts', DCSVI_PLUGIN_DIR_URL . '/dynamic_csv_importer.js', array('jquery'));
	wp_enqueue_script('dynamic_csv_importer_scripts');

}
add_action('admin_enqueue_scripts', 'LoadWpScript');



function description() {
	// Return description of plugin:
	_e('<p>Dynamic CSV Importer will add new posts, pages, or custom posts of a custom type from a CSV file. You do not need to change the CSV file; select the CSV file and map each field to the post field (custom meta fields are supported).</p>');
}

// Get all data from the CSV and set $headers to first line (keys):
function get_csv_file_data($file,$delimiter) {
	// Set this to avoid missing lines on files with bad carriage return encoding:
	ini_set('auto_detect_line_endings', true);

	global $data_rows, $headers, $delimiter;
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
}

// Move file
function move_file() {
	$upload_dir = wp_upload_dir();
	$uploads_dir  = $upload_dir['basedir'] . '/import_temp/';
	if ($_FILES['csv_import']['error'] == 0) {
		$tmp_name = $_FILES['csv_import']['tmp_name'];
		$name = $_FILES['csv_import']['name'];
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
function upload_csv_file() {
	global $headers, $data_rows, $default_fields, $keys, $custom_array, $delimiter, $twig;

	$upload_dir = wp_upload_dir();
	$import_dir  = $upload_dir['basedir'] . '/import_temp/';
	$custom_array = array();

	$post_as_status = isset($_POST['status']) ? $_POST['status'] : "draft";
	
	if (isset($_POST['Import'])) { // File has been submitted for import:
		get_csv_file_data($_FILES['csv_import']['tmp_name'],$delimiter);
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
					'previousuploadfile' => $_FILES['csv_import']['name'],
					'defaultfieldscount' => count($default_fields)+2,
					'header_count' => count($headers),
					'default_count' => count($default_fields),
					'results' => description(),
					'deliminator' => $delimiter,
					'header_array' => $headers,
					));

		} else { // File appears to have less than one row or less than one field:
			echo $twig->render('admin-import-error.html', 
				array(
					'cache' => FALSE,
					'debug' => TRUE,
					));
		}
	} elseif (isset($_POST['post_csv'])) { // File has been uploaded and fields have been mapped, start importing the file into posts and meta:
		$upload_dir = wp_upload_dir();
		$dir  = $upload_dir['basedir']."/import_temp/";
		get_csv_file_data($dir.$_POST['filename'],$delimiter);
		foreach ($_POST as $postkey=>$postvalue) {
			if ($postvalue != '-- Select --') {
				$ret_array[$postkey]=$postvalue;
			}
		}
		foreach($data_rows as $key => $value) {
			for ($i=0;$i<count($value) ; $i++) {
				if (array_key_exists('mapping'.$i,$ret_array)) {
					if ($ret_array['mapping'.$i]!='add_custom'.$i) {
						$new_post[$ret_array['mapping'.$i]] = $value[$i];
					} else {
						$new_post[$ret_array['textbox'.$i]] = $value[$i];
						$custom_array[$ret_array['textbox'.$i]] = $value[$i];
					}
				}
			}
			for ($inc=0;$inc<count($value);$inc++) {
				foreach($keys as $k => $v) {
					if (array_key_exists($v,$new_post)) {
						$custom_array[$v] =$new_post[$v];
					}
				}
			}
			foreach ($new_post as $post_key => $cval) {
				if ($post_key!='post_category' && $post_key!='post_tag' && $post_key!='featured_image') {
					if (array_key_exists($post_key,$custom_array)) {
						$darray[$post_key]=$new_post[$post_key];
				   	} else {
				   		$data_array[$post_key]=$new_post[$post_key];
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
			$data_array['post_status']='publish';
			if (isset($_POST['csv_importer_import_as_draft'])) {
				$data_array['post_status']='draft';
			}
			$data_array['post_type']=$_POST['csv_importer_cat'];
			$post_id = wp_insert_post( $data_array );
			if (!empty($custom_array)) {
				foreach($custom_array as $custom_key => $custom_value) {
					add_post_meta($post_id, $custom_key, $custom_value);
				}
			}

			// Create/Add tags to post
			if (!empty($tags)) { // We have tags to add:
				foreach($tags as $tag_key => $tag_value) {
					wp_set_post_tags( $post_id, $tag_value );
				}
			}  // End of code to add tags

				// Create/Add category to post
			if (!empty($categories)) { // We have categories to add:
				$split_line = explode('|',$categories['post_category']);
				wp_set_object_terms($post_id, $split_line, 'category');

			}

			if (!empty($file)) { // We have a file to 
				$file_name=$dirname.'/'.$img_title.'.'.$type;
				$attach_id = wp_insert_attachment($file, $file_name, $post_id);
				require_once(ABSPATH . 'wp-admin/includes/image.php');
				$attach_data = wp_generate_attachment_metadata( $attach_id, $fileurl );
				wp_update_attachment_metadata( $attach_id, $attach_data );
				//add_post_meta($post_id, '_thumbnail_id', $attach_id, true);
				set_post_thumbnail( $post_id, $attach_id );
			}
		}
		?>
		<div style="background-color: #FFFFE0;border-color: #E6DB55;border-radius: 3px 3px 3px 3px;border-style: solid;border-width: 1px;margin: 5px 15px 2px; padding: 5px;text-align:center"><b> Successfully Imported ! </b></div>
		<div style="margin-top:30px;margin-left:10px">
			<form class="add:the-list: validate" method="post" enctype="multipart/form-data">
				<input type="submit" id="goto" name="goto" value="Continue" />
			</form>
		</div>
		<?php 
		// Remove CSV file
		$upload_dir = wp_upload_dir();
		$csvdir  = $upload_dir['basedir']."/import_temp/";
		$CSVfile = $_POST['filename'];
		if (file_exists($csvdir.$CSVfile)) {
			chmod("$csvdir"."$CSVfile", 755);
			fileDelete($csvdir,$CSVfile);
		}
	} else { // File not submitted for import:
		?>
		<div class="wrap">
			<div style="min-width:45%;float:left;height:500px;">
				<h2>Dynamic CSV Importer</h2>
				<form class="add:the-list: validate" method="post" enctype="multipart/form-data" onsubmit="return file_exist();">

				<!-- File input -->
				<p><label for="csv_import">Upload file:</label><br/>
					<input name="csv_import" id="csv_import" type="file" value="" aria-required="true" /></p><br/>
					<p><label>Delimiter</label>&nbsp;&nbsp;&nbsp;
						<select name="delim" id="delim">
							<option value=",">,</option>
							<option value=";">;</option>
						</select>
					</p>
					<p class="submit"><input type="submit" class="button" name="Import" value="Import" /></p>
				</form>
			</div>
			<div style="min-width:45%;">
				<?php $result = description(); print_r($result); ?>
			</div>
		</div><!-- end wrap -->
		<?php
	}
}

function add_new_post($post, $mapping) {
	// 
	$custom_array = array();
	
	$upload_dir = wp_upload_dir();
	$import_dir  = $upload_dir['basedir'] . '/import_temp/';
	

	get_csv_file_data($dir.$_POST['filename'],$delimiter);
	
	foreach ($_POST as $postkey=>$postvalue) {
		if ($postvalue != '-- Select --') {
			$ret_array[$postkey]=$postvalue;
		}
	}
	
	foreach($data_rows as $key => $value) {
		for ($i=0;$i<count($value) ; $i++) {
			if (array_key_exists('mapping-'.$i,$ret_array)) {
				$mapping_val = $ret_array['mapping-'.$i];
				$textbox_val = $ret_array['textbox-'.$i];
				if ($mapping_val!='add_custom-'.$i) {
					$new_post[$mapping_val] = $value[$i];
				} else {
					$new_post[$textbox_val] = $value[$i];
					$custom_array[$textbox_val] = $value[$i];
				}
			}
		}
		for ($inc=0;$inc<count($value);$inc++) {
			foreach($keys as $k => $v) {
				if (array_key_exists($v,$new_post)) {
					$custom_array[$v] = $new_post[$v];
				}
			}
		}
		foreach ($new_post as $post_key => $cval) {
			if ($post_key!='post_category' && $post_key!='post_tag' && $post_key!='featured_image') {
				if (array_key_exists($post_key,$custom_array)) {
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
		$data_array['post_status']='publish';
		if (isset($_POST['csv_importer_import_as_draft'])) {
			$data_array['post_status']='draft';
		}
		$data_array['post_type']=$_POST['csv_importer_cat'];
		$post_id = wp_insert_post( $data_array );
		if (!empty($custom_array)) {
			foreach($custom_array as $custom_key => $custom_value) {
				add_post_meta($post_id, $custom_key, $custom_value);
			}
		}

		// Create/Add tags:
		if (!empty($tags)) { // We have tags to add:
			foreach($tags as $tag_key => $tag_value) {
				wp_set_post_tags( $post_id, $tag_value );
			}
		} 

		// Create/Add category:
		if (!empty($categories)) { // We have categories to add:
			$split_line = explode('|',$categories['post_category']);
			wp_set_object_terms($post_id, $split_line, 'category');
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

// Adds tags, categories, or other post meta:
function dcsvi_add_post_meta() {

}

function job_schedule_handler() {

}