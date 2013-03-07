
// Function for add custom field input 
function toggle_custom_field(value, select_id, input_id) {
	var selected_element = document.getElementById(select_id);
		custom_input = document.getElementById(input_id);
	if (selected_element.options[selected_element.selectedIndex].text == 'Add Custom Field' && custom_input.style.display=="none") {
		custom_input.style.display="visible";
	} else {
		custom_input.style.display="none";
	}
}


// Check if CSV file exists:
function file_exist(){
	if(document.getElementById('csv_import').value==''){
		return false;
	}
	else{
		return true;
	}
}

// Function for import csv
function import_csv(){
	var header_count = document.getElementById('h2').value;
	var array = new Array();
	var val1,val2;
	val1 = val2 = 'Off';
	for(var i=0;i<header_count;i++){
		var e = document.getElementById("mapping-"+i);
		var value = e.options[e.selectedIndex].value;
		array[i] = value;
	}
	for(var j=0;j<array.length;j++){
		if(array[j] == 'post_title'){
			val1 = 'On';	
		}
		if(array[j] == 'post_content'){
			val2 = 'On';
		}
	}
	if(val1 == 'On' && val2 == 'On') {
   	 return true;
	}
	else{
	 alert('"post_type" and "post_content" should be mapped.');
	 return false;
	}
}