<?php
  require 'voiDtoLDA.class.php';

  if(isset($_GET['dataset'])){
    logMessage("Dataset URI: {$_GET['dataset']}");
    $g = new voiDtoLDA($_GET['dataset']);
    if(!empty($g->errors)){
    	echo "\nThere were errors generating your API Configuration\n";
    	foreach ($g->errors as $error) {
		    echo "\n----------\n $error";
    	}
    } else {
      $turtle = $g->to_turtle();
      require 'results.html';
//        header("Content-type: text/turtle");
 //       echo $g->to_turtle();
    }

  } else {
    require 'form.html';
  }
?>
