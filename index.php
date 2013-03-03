<?php
/*
*	Plugin Name: Dynamic CSV Importer
*	Plugin URI: http://dynamic-csv-importer.allstruck.org/
*	Description: Import to any post type from any CSV, including custom fields.
*	Version: 1.0
*	Author: allstruck
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

$upload_dir = wp_upload_dir();
$import_dir  = $upload_dir['basedir']."/import_temp/";
if(!is_dir($import_dir)) {
	wp_mkdir_p($import_dir);
}

// Global variable declaration
global $data_rows, $headers, $defaults, $wpdb, $keys, $delim;
$data_rows = array();
$headers = array();

// Set the delimiter:
$delim = empty($_POST['delim']) ? '' : $_POST['delim'];
// Get the custom fields
$limit = (int) apply_filters( 'postmeta_form_limit', 30 );
$keys = $wpdb->get_col( "
	SELECT meta_key
	FROM $wpdb->postmeta
	GROUP BY meta_key
	HAVING meta_key NOT LIKE '\_%'
	ORDER BY meta_key
	LIMIT $limit" );
// Default header array
$defaults = array(
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
	$defaults[$val]=$val;
}
// Admin menu settings
function dynamic_csv_importer() {  
	add_submenu_page( 'tools.php', 'Dynamic CSV Importer', 'CSV Importer', 'manage_options', 'dynamic_csv_importer', 'upload_csv_file');
	add_menu_page('CSV importer settings', 'CSV Importer', 'manage_options',  
		'upload_csv_file', 'upload_csv_file');
}  


function LoadWpScript() {
	wp_register_script('dynamic_csv_importer_scripts', site_url()."/wp-content/plugins/wp-ultimate-csv-importer/dynamic_csv_importer.js", array("jquery"));
	wp_enqueue_script('dynamic_csv_importer_scripts');
}
add_action('admin_enqueue_scripts', 'LoadWpScript');


add_action("admin_menu", "dynamic_csv_importer");  

// Plugin description details
function description() {
	_e('<p>Dynamic CSV Importer will add new posts, pages, or custom posts of a custom type from a CSV file. You do not need to change the CSV file; select the CSV file and map each field to the post field (custom meta fields are supported).</p> 
	<p>');
}

// CSV File Reader
function csv_file_data($file,$delim) {
	ini_set('auto_detect_line_endings', true);
	global $data_rows;
	global $headers;
	global $delim;
	$c = 0;
	$resource = fopen($file, 'r');
	while ($keys = fgetcsv($resource,'',$delim,'"')) {
		if ($c == 0) {
			$headers = $keys;
		} else {
			array_push($data_rows, $keys);
		}
		$c ++;
	}
	fclose($resource);
	ini_set('auto_detect_line_endings', false);
}

// Move file
function move_file() {
	$upload_dir = wp_upload_dir();
	$uploads_dir  = $upload_dir['basedir'] . '/import_temp/';
	if ($_FILES['csv_import']['error'] == 0) {
		$tmp_name = $_FILES['csv_import']['tmp_name'];
		$name = $_FILES['csv_import']['name'];
		move_uploaded_file($tmp_name, '$uploads_dir/$name');
	}
}

// Remove file
function fileDelete($filepath,$filename) {
	$success = FALSE;
	if (file_exists($filepath.$filename)&&$filename!=""&&$filename!="n/a") {
		unlink ($filepath.$filename);
		$success = TRUE;
	}
	return $success;	
}

// Map the fields and upload data:
function upload_csv_file() {
	global $headers, $data_rows, $defaults, $keys, $custom_array, $delim;
	
    $upload_dir = wp_upload_dir();
	$import_dir  = $upload_dir['basedir'] . '/import_temp/';
	$custom_array = array();
	if(isset($_POST['Import'])) {
		csv_file_data($_FILES['csv_import']['tmp_name'],$delim);
		move_file();
		?>
		<?php if ( count($headers)>=1 &&  count($data_rows)>=1 ){?>
		<div style="float:left;min-width:45%">
			<form class="add:the-list: validate" method="post" onsubmit="return import_csv();">
				<h3>Import Data Configuration</h3>
				<div style="margin-top:30px;>
					<input name="_csv_importer_import_as_draft" type="hidden" value="publish" />
					<label><input name="csv_importer_import_as_draft" type="checkbox" <?php if ('draft' == $opt_draft) { echo 'checked="checked"'; } ?> value="draft" /> Import as drafts </label>&nbsp;&nbsp;
    				</p>
    				<label> Select Post Type </label>&nbsp;&nbsp;
    				<select name='csv_importer_cat'>
    					<?php
    					$post_types=get_post_types();
    					foreach($post_types as $key => $value){
    						if(($value!='featured_image') && ($value!='revision') && ($value!='nav_menu_item')){ ?>
    						<option id="<?php echo($value);?>" name="<?php echo($value);?>"> <?php echo($value);?> </option>
    						<?php   }
    					}
    					?>
    					<select>
    						<br/></div><br/>
    						<h3>Mapping the Fields</h3>
    						<div id='display_area'>
    							<?php $cnt =count($defaults)+2; $cnt1 =count($headers); ?>
    							<input type="hidden" id="h1" name="h1" value="<?php echo $cnt; ?>"/>
    							<input type="hidden" id="h2" name="h2" value="<?php echo $cnt1; ?>"/>
    							<input type="hidden" id="delim" name="delim" value="<?php echo $_POST['delim']; ?>" />
    							<input type="hidden" id="header_array" name="header_array" value="<?php print_r($headers);?>" />
    							<table style="font-size:12px;">
    								<?php
    								$count = 0;
    								foreach($headers as $key=>$value){ 
    									?>
    									<tr>
    										<td>
    											<label><?php print($value);?></label>
    										</td>
    										<td>
    											<select  name="mapping<?php print($count);?>" id="mapping<?php print($count);?>" class ='uiButton' onchange="addcustomfield(this.value,<?php echo $count; ?>);">
    												<option id="select" name="select">-- Select --</option>
    												<?php 
    												foreach($defaults as $key1=>$value1){
    													?>
    													<option value ="<?php print($key1);?>"><?php print($key1);?></option>
    													<?php }
    													?>
    													<option value="add_custom<?php print($count);?>">Add Custom Field</option>
    												</select>
    												<input type="text" id="textbox<?php print($count); ?>" name="textbox<?php print($count); ?>" style="display:none;"/>
    											</td>
    										</tr>
    										<?php
    										$count++; } 
    										?>
    									</table>
    								</div><br/> 
    								<input type='hidden' name='filename' id='filename' value="<?php echo($_FILES['csv_import']['name']);?>" />
    								<input type='submit' name= 'post_csv' id='post_csv' value='Import' />
    							</form>
    						</div>
    						<div style="min-width:45%;">
    							<?php $result = description(); print_r($result); ?>
    						</div>
    						<?php
    					}
    					else { ?>
    					<div style="font-size:16px;margin-left:20px;">Your CSV file cannot be processed. It may contains wrong delimiter or please choose the correct delimiter.
    					</div><br/>
    					<div style="margin-left:20px;">
    						<form class="add:the-list: validate" method="post" action="">
    							<input type="submit" class="button" name="Import Again" value="Import Again"/>
    						</form>
    					</div>
    					<div style="margin-left:20px;margin-top:30px;">
    						<b>Note :-</b>
    						<p>1. Your CSV should contain "," or ";" as delimiters.</p>
    						<p>2. In CSV, tags should be seperated by "," to import mutiple tags and categories should be seperated by "|" to import multiple categories.</p>
    					</div>
    					<?php	}
    				}
    				else if(isset($_POST['post_csv']))
    				{
    					$upload_dir = wp_upload_dir();
    					$dir  = $upload_dir['basedir']."/import_temp/";
    					csv_file_data($dir.$_POST['filename'],$delim);
    					foreach($_POST as $postkey=>$postvalue){
    						if($postvalue != '-- Select --'){
    							$ret_array[$postkey]=$postvalue;
    						}
    					}
    					foreach($data_rows as $key => $value){
    						for($i=0;$i<count($value) ; $i++)
    						{
    							if(array_key_exists('mapping'.$i,$ret_array)){
    								if($ret_array['mapping'.$i]!='add_custom'.$i){
    									$new_post[$ret_array['mapping'.$i]] = $value[$i];
    								}
    								else{
    									$new_post[$ret_array['textbox'.$i]] = $value[$i];
    									$custom_array[$ret_array['textbox'.$i]] = $value[$i];
    								}
    							}
    						}
    						for($inc=0;$inc<count($value);$inc++){
    							foreach($keys as $k => $v){
    								if(array_key_exists($v,$new_post)){
    									$custom_array[$v] =$new_post[$v];
    								}
    							}
    						}
    						foreach($new_post as $ckey => $cval){
			   if($ckey!='post_category' && $ckey!='post_tag' && $ckey!='featured_image'){ // Code modified at version 1.0.2 by fredrick
			   	if(array_key_exists($ckey,$custom_array)){
			   		$darray[$ckey]=$new_post[$ckey];
			   	}
			   	else{
			   		$data_array[$ckey]=$new_post[$ckey];
			   	}
			   }
			   else{
			   	if($ckey == 'post_tag'){
			   		$tags[$ckey]=$new_post[$ckey];
			   	}
			   	if($ckey == 'post_category'){
			   		$categories[$ckey]=$new_post[$ckey];
			   	}
				if($ckey == 'featured_image'){ // Code added at version 1.1.0 by fredrick
					$file_url=$filetype[$ckey]=$new_post[$ckey];
					$file_type = explode('.',$filetype[$ckey]);
					$count = count($file_type);
					$type= $file_type[$count-1];
					if($type == 'png'){
						$file['post_mime_type']='image/png';
					}
					else if($type == 'jpg'){
						$file['post_mime_type']='image/jpeg';
					}
					else if($type == 'gif'){
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
					if(is_dir($full_path)){
						copy($file_url,$filepath);
					}
					else{
						wp_mkdir_p($full_path);
						copy($file_url,$filepath);
					}
					$file['guid']=$fileurl;
					$file['post_title']=$img_title;
					$file['post_content']='';
					$file['post_status']='inherit';
				}
			}
		}
		$data_array['post_status']='publish';
		if(isset($_POST['csv_importer_import_as_draft'])){
			$data_array['post_status']='draft';
		}
		$data_array['post_type']=$_POST['csv_importer_cat'];
		$post_id = wp_insert_post( $data_array );
		if(!empty($custom_array)){
			foreach($custom_array as $custom_key => $custom_value){
				add_post_meta($post_id, $custom_key, $custom_value);
			}
		}

			// Create/Add tags to post
		if(!empty($tags)){
			foreach($tags as $tag_key => $tag_value){
				wp_set_post_tags( $post_id, $tag_value );
			}
			}  // End of code to add tags

			// Create/Add category to post
			if(!empty($categories)){
				$split_line = explode('|',$categories['post_category']);
				wp_set_object_terms($post_id, $split_line, 'category');

			}  // End of code to add category

			// Code added to import featured image at version 1.1.0 by fredrick
			if(!empty($file)){
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
		if(file_exists($csvdir.$CSVfile)){
			chmod("$csvdir"."$CSVfile", 755);
			fileDelete($csvdir,$CSVfile); 
		}
	} else {
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