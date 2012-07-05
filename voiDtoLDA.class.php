<?php
define('DCT_DESCRIPTION', 'http://purl.org/dc/terms/description');

define('MORIARTY_ARC_DIR', 'arc/');
define('MORIARTY_ALWAYS_CACHE_EVERYTHING', 'cache');
define('MORIARTY_HTTP_CACHE_DIR', 'cache');
define('VOID_NS', 'http://rdfs.org/ns/void#');
define('VOID_SPARQL', VOID_NS.'sparqlEndpoint');
define('VOID_EXAMPLE', VOID_NS.'exampleResource');
define('VOID_CLASS_PARTITION', VOID_NS.'classPartition');
define('API','http://purl.org/linked-data/api/vocab#' );

#set_include_path(get_include_path().':/Users/keith/dev/');

require 'moriarty/moriarty.inc.php';
require 'moriarty/sparqlservice.class.php';
require 'moriarty/simplegraph.class.php';

function logMessage($m){
  $date = date('c');  
  $msg = "{$date}\t{$m}\n";
  file_put_contents('log.txt', $msg, FILE_APPEND);
}



class voiDtoLDA extends SimpleGraph {

	var $voidUri ;
	var $sparqlEndpointUri ;
	var $baseUri ;
	var $sparql ;
  var $errors=array();
  var $slugMappings = array(
  RDF_TYPE => 'type',
  );

  function __construct($voidUri, $sparqlEndpointUri=false, $baseUri=false){
    logMessage("Creating config for {$voidUri}");
		$this->voidUri = $voidUri;
		$this->voiD = new SimpleGraph();
		$this->sparqlEndpointUri = $this->getSparqlEndpoint($sparqlEndpointUri );
    if(!$baseUri) $baseUri=$voidUri.'/';
		$this->baseUri = $baseUri;
		$this->apiUri = $this->baseUri.'api';
		$this->sparql = new SparqlService($this->sparqlEndpointUri);
		$this->getVoiDGraph();
		$this->makeAPI();
		$this->makeItemEndpoints();
    $this->makeListEndpoints();
    $this->addApiLabels();
		parent::__construct();
	}


  function addApiLabels(){
    $this->getExampleProperties();
    foreach ($this->slugMappings as $uri => $name) {
      $this->add_literal_triple($uri, API.'label', $name);
    }

  }

	function getSparqlEndpoint($uri){
    logMessage("Getting SPARQL Endpoint for {$this->voidUri}");
		if($uri) return $uri;

		$this->voiD->read_data($this->voidUri);

		if($endpoint = $this->voiD->get_first_resource($this->voidUri, VOID_SPARQL)){
			return $endpoint;
		}	else {
			$this->errors[]="Couldn't find SPARQL endpoint. You should link to one in the voiD description, or provide one as the second parameter";
		}
	}

	#
	# getVoiDGraph
	#
	#
	public function getVoiDGraph(){
    $this->voiD->read_data($this->voidUri);
    #logMessage(print_r($this->voiD, true));

    /*
		$query = "DESCRIBE <{$this->voidUri}> ?example { <{$this->voidUri}> <http://rdfs.org/ns/void#exampleResource> ?example .  }";
		$response = $this->sparql->graph($query);		
		if($response->is_success()){
		
			$this->voiD->add_rdf($response->body);

		} else {
			$this->errors[]='Failed to retrieve voiD from sparql endpoint: '.$response->body."\n\n---------\n\n {$query}";
    }
     */  
	}
	# makeAPI
	#
	#
	public function makeAPI(){
	  logMessage("Making API");
		$apiSparqlUri = $this->voiD->get_first_resource($this->voidUri, VOID_SPARQL);
		$this->add_resource_triple($this->apiUri, RDF_TYPE, API.'API');
		$this->add_literal_triple($this->apiUri, DCT_DESCRIPTION, $this->voiD->get_description($this->voidUri));
    $this->add_resource_triple($this->apiUri, API.'sparqlEndpoint', $this->voiD->get_first_resource($this->voidUri, VOID_SPARQL));
    $this->add_resource_triple($this->apiUri, API.'defaultFormatter', API.'JsonFormatter');
    $this->add_resource_triple($this->apiUri, API.'defaultViewer', API.'labelledDescribeViewer');
    $this->add_literal_triple($this->apiUri, API.'defaultPageSize', '20');
    if($vocabularies = $this->voiD->get_resource_triple_values($this->voidUri, VOID_NS.'vocabulary')){
      foreach($vocabularies as $vocab){
         $this->add_resource_triple($this->apiUri, API.'vocabulary', $vocab);
      }
    }


	}
	# makeItemEndpoints
	#
	#
	public function makeItemEndpoints(){

    logMessage("Adding Void Examples from Class Partitions");
    # from classPartitions
    foreach($this->voiD->get_resource_triple_values($this->voidUri, VOID_CLASS_PARTITION) as $classPartition){
      logMessage("Fetching Class Partition: {$classPartition}");
      
      $class = $this->voiD->get_resource_triple_values($classPartition, VOID_NS.'class');
      $classExample = $this->sparql->select_to_array("SELECT ?s where { ?s a <{$class[0]}>} LIMIT 1");
      
      $this->voiD->add_resource_triple($this->voidUri, VOID_EXAMPLE, $classExample[0][s][value]);
      logMessage(print_r($classExample, true));



    }


    logMessage("Making Item Endpoints");
    $no = 1;

		foreach($this->voiD->get_resource_triple_values($this->voidUri, VOID_EXAMPLE) as $exampleUri){
      logMessage("Fetching Example: {$exampleUri}");
      $this->voiD->read_data($exampleUri);
      logMessage("fetched {$exampleUri}");
      if(!$type = $this->voiD->get_first_resource($exampleUri, RDF_TYPE)){
        $type = RDFS_RESOURCE;
      }      
      $typeSlug = $this->getSlug($type);
			$endpointUri = $this->baseUri.$typeSlug.'_ItemEndpoint';
      if($this->has_triples_about($endpointUri)){
        $endpointUri.='_1';
      }
			$this->add_resource_triple($this->apiUri, API.'endpoint', $endpointUri);
			$this->add_resource_triple($endpointUri, RDF_TYPE, API.'ItemEndpoint');
			$this->add_literal_triple($endpointUri, API.'uriTemplate', $this->getUriTemplateFromUri($exampleUri));
			$this->add_literal_triple($endpointUri, API.'itemTemplate', $this->getItemTemplateFromUri($exampleUri));

      logMessage("Added Endpoint definition to graph");
				
    }


  }

  function getExampleProperties(){
    logMessage("Getting example properties");
    $properties = array();
    foreach($this->voiD->get_resource_triple_values($this->voidUri, VOID_EXAMPLE) as $exampleUri){
        foreach ($this->voiD->get_subject_properties($exampleUri) as $property) {
          foreach($this->voiD->get_subject_property_values($exampleUri, $property) as $object){
            if(!empty($object['datatype'])){
              $this->add_resource_triple($property, RDFS_RANGE, $object['datatype']);
            }
          }
          $properties[]= $property;
          $this->getSlug($property);
        }
    }
    return array_unique($properties);
  }

	function getItemTemplateFromUri($uri){
		if(preg_match('@^(.+[/#])[^/#]+/*$@', $uri, $m)){
  		return $m[1].'{localname}';	
    } else {
      logMessage("Couldn't generate Item Template from {$uri}");
    }
	}
	function getUriTemplateFromUri($uri){
		$path = parse_url($uri, PHP_URL_PATH);
		if(preg_match('@^(.+[/#])[^/#]+/*$@', $path, $m)){
      return $m[1].'{localname}';	
    } else {
      logMessage("Couldn't generate uri template from {$uri}");
    }
	}

	# makeListEndpoints
	#
	#
	public function makeListEndpoints(){
	
    logMessage("making List Endpoints");
		foreach($this->getExampleTypes() as $exampleType){
      $slug = $this->getSlug($exampleType);
			$endpointUri = $this->baseUri.ucwords($slug).'_ListEndpoint';
			$this->add_resource_triple($this->apiUri, API.'endpoint', $endpointUri);
			$this->add_resource_triple($endpointUri, RDF_TYPE, API.'ListEndpoint');
      $uriTemplate =  '/'.$this->pluralise($slug);
			$this->add_literal_triple($endpointUri, API.'uriTemplate', $uriTemplate);
      
      $selectorUri = $endpointUri.'/selector';

      $this->add_literal_triple($selectorUri, API.'filter', "type={$slug}");
      $this->add_resource_triple($endpointUri, API.'selector', $selectorUri);
				
		}
	}

	function getExampleTypes(){
    logMessage("Get Example Types");
		$types = array();
		foreach($this->voiD->get_resource_triple_values($this->voidUri, VOID_EXAMPLE) as $exampleUri){
			$types = array_merge($this->voiD->get_resource_triple_values($exampleUri, RDF_TYPE), $types);					
		}
		return array_filter(array_unique($types));
	}

	function getSlug($uri){
    logMessage("Getting Slug from {$uri}");
    if(isset($this->slugMappings[$uri])){
      return $this->slugMappings[$uri];
    } else {
      $slugs = array_values($this->slugMappings);
      if(!preg_match('@[^/#]+$@', $uri, $m)){
        die($uri);
      }
      $localname = $m[0];
      $slug = $localname;
      $no=1;
      while(in_array($slug, $slugs)){
        $slug = $localname.$no++;
      }
      $this->slugMappings[$uri] = $slug;
      return $slug;
    }
  }

  function pluralise($in){
    $in = strtolower($in);
    $exceptions = array(
      'person' => 'people',
    );

      if(isset($exceptions[$in])){
        return $exceptions[$in];
	    } else {
        return $in.'s';
      }
  }

}
/*
$dsURI = $_SERVER['argv'][1];
$dsSPARQL = isset($_SERVER['argv'][2] )? $_SERVER['argv'][2] : false;
$apiBaseUri = isset($_SERVER['argv'][3] )? $_SERVER['argv'][3] : false;
$g = new voiDtoLDA($dsURI, $dsSPARQL, $apiBaseUri);
if(!empty($g->errors)){
	echo "\nThere were errors generating your API Configuration\n";
	foreach ($g->errors as $error) {
		echo "\n----------\n $error";
	}
} else echo $g->to_turtle();
 */
?>
