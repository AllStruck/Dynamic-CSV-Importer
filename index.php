
// Function for add customfield

function toggle_custom_field(value, select_id, input_id) {
	var selected_element = document.getElementById(select_id);
		custom_input = document.getElementById(input_id);
	if (selected_element.options[selected_element.selectedIndex].text == 'Add Custom Field' && custom_input.style.display=="none") {
		custom_input.style.display=""
	} else {
		custom_input.style.display="none"
	}
}

// function addcustomfield(myval,selected_id){
// 	var a = document.getElementById('h1').value;
// 	var aa = document.getElementById('h2').value;
// 	var selected_value;// added at version 1.0.2 by fredrick
// 	for(var i=0;i<aa;i++){ 
// 		var b = document.getElementById('mapping-'+i).value;
// Uncaught TypeError: Cannot read property 'value' of null
// 		if(b=='add_custom-'+i){
// 			document.getElementById('textbox-'+i).style.display="";
// 		}
// 		else{
// 			document.getElementById('textbox-'+i).style.display="none";
// Uncaught TypeError: Cannot read property 'style' of null
// 		}
// 	}
//  	var header_count = document.getElementById('h2').value;
// 	for(var j=0;j<header_count;j++){
// 		var selected_value = document.getElementById('mapping-'+j);
// 		var value1 = selected_value.options[selected_value.selectedIndex].value;
// Uncaught TypeError: Cannot read property 'options' of null
// 		if(j != selected_id){
// 			if(myval == value1 && myval != '-- Select --'){
// 				var selected_dropdown = document.getElementById('mapping-'+selected_id);
// 				selected_dropdown.selectedIndex = '-- Select --';
// Uncaught TypeError: Cannot set property 'selectedIndex' of null
// Uncaught TypeError: Cannot set property 'selectedIndex' of null
// 				alert(myval+' is already selected!');
// 			}
// 		}
// 	}
// }

// Function for check file exist

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