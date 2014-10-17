<?php

namespace Bespired\ToPdf;

//use Fpdf;
use Illuminate\Support\Facades\View;


/*
defines:
	path: testpdf
	size: 960px, 1030px
:defines

page:
	block: [ 20px, 20px, 120px, 100%, top, left, #fff ]
		Beste $firstname,
		Het is ons ter ore gekomen dat u graag mail van ons krijg.
		Nou bij deze...
	:block
	image: [ logo.png, 20px, 20px, img, img ] :image
:page
*/

/*
+-------------+--------------+-----+
|   level 2   |              |     |
|   of lvl 3  |              |     |
+-------------+              |     |
|                            |     |
|                   +--------+     |
|                   |        |     |
|         level 2   |  lvl 3 |     |
+-------------------+--------+     |
|                                  |
|              level 1             |
+----------------------------------+ 
*/




class Mark
{

	public $clean = '';
	public $defines;
	public $pages;

	private static $defs  = [ 
		'path',
		'size',
		'dpi',
		'orientation', 
		'red', 'orange', 'green', 'blue', 'white', 'black', 'gray', 'light', 'dark', 'accent',
		'style',
		'font-1','font-2','font-3','font-4','font-5','font-6','font-7','font-8','font-9',
		'h1', 'h2', 'h3'
 		];
 	private static $classdefs = [ 
 		'background-color',
 		'font-style',
 		'font-color',
 		'black', 'white', 'red', 'green', 'blue'
 		];

	private static $marks = [ 
		'block', 
		'rect',
		'image'
		];

	private static $colors = [
		'red'    => '255,0,0', 
		'orange' => '255,127,0', 
		'green'  => '0,255,0', 
		'blue'   => '0,0,255',
		
		'white'  => '255,255,255', 
		'black'  => '0,0,0', 
		'gray'   => '127,127,127', 
		'light'  => '192,192,192', 
		'dark'   => '32,32,32', 
		'accent' => '255,0,0', 
		];


	public function view( $view, $data )
	{
		ini_set('xdebug.var_display_max_depth', '10');    		
		ini_set('xdebug.var_display_max_data' , '10000');    		
		set_time_limit ( 0 );

		self::newlog();
		self::log( "\nmark\n====\n" );
		
		setlocale( LC_ALL, 'nl_NL' );
	
		$view = \View::make( $view, $data )->render();
		$clean = self::cleanup( $view );

		$this->clean   = $clean;

		self::validate( $clean );

		// defines
		$define_string = self::getByTag( $clean ,'defines', 0 );
		$this->defines = self::markDefDecode( $define_string );

		// replace predef colors with doc define.
		foreach (self::$colors as $key => $dummy) 
		{
			if ( isset($this->defines[$key]) ){
				self::$colors[ $key ] = $this->defines[$key];
			}
		}

		// pages
		$pages_array   = self::getPages($clean);
		//$pages_array   = self::getByTag( $clean ,'page' );
		foreach ($pages_array as $key => $page_string) {
			$this->pages[] = self::markPageDecode( $page_string, $this->defines );
		}

//		self::log( $this->defines );
//		self::log( $this->pages );

	}


	private static function validate( $clean )
	{
		// all open tags should also be closed
		foreach (self::$marks as $mark) 
		{
			if ( substr_count($clean, "$mark:") != substr_count($clean, ":$mark" ) )
			{
				self::log( "Tag miscount $mark, more details need to be implemented." );
			}
		}

	}


	private static function markDefDecode( $string )
	{
		$defines = [];
		foreach (self::$defs as $key => $find) {
			$define = self::getDefinition( $string, $find );

			if ( $define != '' )
			{
				switch( $find )
				{
					case 'style':
						$defines[$find] = self::getClasses( $define );
					break;

					default:
						if ( $define[0] == "#" ) $define = self::colorUnit( $define );
						if ( substr_count( $define, "," ) == 2 ) $define = self::colorUnit( $define );
						$defines[$find] = $define;
				}
				
			}
		}

		return $defines;
	}

	private static function markPageDecode( $string , $defines )
	{

		$page = [];

		self::log( "\npage\n====\n$string\n" );
		// replace singleline :image: and :rect: into image: * :image and rect: * :rect 
		$string = self::expandSingles($string);
		


		// replace single line :block: with multiline block: :block
		$re = "/(:block:)((.?\\[[^\\]]*\\])|())([^\\n]*)/"; 
		$subst = "block:$2\n$5\n:block"; 
		$string = preg_replace($re, $subst, $string );
		// and do the block: * :block
		$string = self::expandSingleBlocks($string);

		$string = self::blockifyImage($string);	// replace image: :image  to  block: *image :block
		$string = self::blockifyRect($string);  // replace rect: :rect  to  block: *rect :block

		$levels = explode('block:', $string );
		$levelcount = 0;
		foreach ($levels as $key => $level) {
			$levelcount = max ( $levelcount, substr_count( $level, ':block' ) );
		}

		// Markup allows multi depth blocks.
		// But I want only absolute position blocks.
		// So I need to decompose all blocks to main level with correct x, y, w, h 

		// number all the blocks
		$blocks = explode('block:', $string );
		foreach ($blocks as $key => &$block) {
			if ( $key > 0 ) $block = "{ $key } " . trim( $block );
		}
 		$string = trim( implode("\nblock:", $blocks ) );
 		
 		self::log( "\nrollout\n====\n$string\n" );

 		// find hiarchy
 		$hiarchyAndDepth = self::calculateHiarchyAndDepth($string);
 		self::log( $hiarchyAndDepth);

 		// now we have this array, lets find the absolute positions and sizes
 		$blockdata = self::calculateBlockdata($hiarchyAndDepth['hiarchy'], $string, $defines );

 		// flatten the blocks by depth
 		for( $depth = $levelcount; $depth >= 0; $depth-- )
 		{

 			foreach ($hiarchyAndDepth['depth'] as $key => $value) {
 				if ( $value == $depth )
 				{
 					self::log( "$key is of depth $depth \n" );
 					$re = "/(block:{ $key }).?(\\[.*\\])(.*)((\\n.*?)*)(:block)/"; 
 					preg_match($re, $string, $matches);

 					$single = $matches[0] . "\n";
 					$string = str_replace($single, '', $string);
 					// by-the-way--
 					// fill the content with the real content...
 					$blockdata[$key]['class']   = trim( $matches[3] );
 					$blockdata[$key]['content'] = self::correctContent( $matches[4], $blockdata[$key]['ctype'], $defines );
 				}
 			}
 		}
 		// find draw order by top first
 		$order= [];
 		for( $depth = 0; $depth < $levelcount;  $depth++ )
 		{
 			foreach ($hiarchyAndDepth['depth'] as $key => $value) {
 				if ( $value == $depth ) $order[] = $key;
 			}
 		}

 		self::log( $order );
		foreach ($order as $idx) {
			$page[] = $blockdata[$idx];
		}
		self::log( $page );


		return $page;
	}

	

	private static function cleanup($view)
	{
		$lines = explode( "\n", $view );
		if ( count($lines) == 1 ) $lines = explode( "\r", $view );
		if ( count($lines) == 1 ) $lines = explode( "\r\n", $view );
		if ( count($lines) == 1 ) dd( 'Not a mrk file.' );

		foreach ($lines as $ln => &$line) {
			$line = trim( $line );
			if ( $line == '' ) unset( $lines[$ln] );
			if ( substr($line,0,2) == '//' ) unset( $lines[$ln] );
		}
		return implode( "\n", $lines );
	}


	private static function correctContent( $string, $ctype, $defines )
	{
		switch( $ctype )
		{
			case 'image': // let's include the path to this image

				$path = app_path( 'views/' . $defines['path'] );
				if ( substr($path, -1) != "/" ) $path .= '/';
				return trim( $path . trim( $string ));

			break;
			default:
				return str_replace( "\n", '[br]', trim($string) ) ;
		}
	}

	private static function expandSingleBlocks($string)
	{
		mb_internal_encoding("UTF-8");
		mb_regex_encoding("UTF-8");
		$re = "/(block:).?(\\[.+?\\]) (\\.[a-zA-Z0-9]+)?(.+)?(\\n)/"; 
		$subst = "block: $2 $3\n$4\n";
		$result = preg_replace($re, $subst, $string);
		$string = str_replace( ':block', "\n:block", $result);
		$string = self::cleanup( $string );
		return $string;
	}

	private static function expandSingles($string)
	{
		mb_internal_encoding("UTF-8");
		mb_regex_encoding("UTF-8");
		// replace singleline :image: and :rect: into image: * :image and rect: * :rect 
		foreach ( ['image','rect'] as $mark) {		
			$re = "/(:$mark:).?(\\[.*\\]).?(.*)/"; 
			$subst = "$mark: $2 $3 \n:$mark"; 
			$string = preg_replace($re, $subst, $string);
		}
		return $string;
	}

	// replace image: :image  to  block: *image :block
	private static function blockifyImage($string)
	{
		// image: [ logo.png, 20px, 20px, img, img, 96dpi ] .class :image
		// $1       $2        $3    $4    $5   $6    $7 
		$re = "/(image:).?\\[(.*(.jpg|.png)),(.*),(.*|.*dpi)\\](.*)(\\n?)(:image)/"; 
		$subst = "block:[ $4,$5,$6, *image ]$7\n$2\n:block"; 
		return preg_replace($re, $subst, $string);
		// block: [ 20px, 20px, img, img, 96dppi, *image ] .class .. logo.png .. :block
	}

	// replace rect: :rect  to  block: *rect :block
	private static function blockifyRect($string)
	{
		// image: [ 0px, 0px, 100%, 100%, color ] .class :image
		$re = "/(rect:).?\\[(.*),(.*),(.*),(.*),(.*)\\](.*)(\\n?)(:rect)/"; 
		$subst = "block:[$2,$3,$4,$5,$6, *rect ]$7\n:block";
		return preg_replace($re, $subst, $string);
		// block: [ 0px, 0px, 100%, 100%, color, *rect ] .class .. :block
	}

	public static function getPages( $clean )
	{
		$pages = [];
		$parts = explode( ':page', $clean );
		foreach ($parts as $key => $part) {
			$parts[$key] = trim( $part );
		}
		foreach ($parts as $key => $part) {
			if ( strtolower(substr($part, 0, 5 )) == 'page:' ) $pages[] = substr($part, 5);
		}
		//var_dump( $pages );
		return $pages;
	}

	public static function getByTag( $clean, $tag , $idx = -1 )
	{
		$re = "/($tag:)((\\n*.*?)*)(:$tag)/";
		preg_match_all($re, $clean, $matches);
		if ( $idx >= 0 ) return $matches[2][$idx]; 
		return $matches[2];
	}

	private static function getDefinition( $clean, $tag )
	{
		// try single line
		$re = "/(:$tag:)(.*?\\n)/";
		preg_match_all($re, $clean, $matches);

		if ( count($matches[1]) > 0 )
		{
			if ( $matches[1][0] == ":$tag:" ){
				return trim( $matches[2][0] );	
			} 
		}
		// try multi line
		// /(block:)([^\\0]*)(:block)/
		$re = "/($tag:)([^\\0]*)(:$tag)/";
		// $re = "/($tag:)((\\n*.*?)*)(:$tag)/";
		preg_match_all($re, $clean, $matches);
		if ( count($matches[1]) > 0 )
		{
			if ( $matches[1][0] == "$tag:" ){
				return trim( $matches[2][0] );	
			} 
		}
		// nope, not in this define
		return '';
	}

	private static function getClasses( $clean )
	{
		$re = "/(\\.([a-z\\-]+))\\{((\\n*.*?)*)\\}/";
		preg_match_all($re, $clean, $matches);
		if ( count($matches[2]) == 0 ) return [];

		$classes = [];
		foreach ($matches[2] as $idx => $key) {

			$string = $matches[3][$idx]; // fetch the body of the class definition, this can be multiple tags.

			// find what class definitions are used in this class
			$class  = [];
			foreach (self::$classdefs as $find) {	
				$define = self::getDefinition( $string, $find );
				if ( $define != '' )
				{
					$class[ $find ] = $define;
				}
			}
			// put the found class definitions in the class
			$classes[ $key . '-class' ] = $class;

		}
		return $classes;
	}


	private static function calculateHiarchyAndDepth($string)
	{
		// remove all but the block tags
		$re = "/(block:{ \\d* }|:block)/";
 		preg_match_all($re, $string, $matches);
 		$order = $matches[0];

 		// then find the depths of the children
		$hiarchy   = [];
		$depth     = [];
      	$parent_id = [0];
      	$level     =  0;
      	foreach ($order as $key => $entry) 
      	{
      		if ( $entry[0] != ":" )
      		{	// opening tag, more depth...
      			preg_match_all("/{ ([0-9][0-9]*)* }/", $entry, $matchid);	// get tag id
				$id = intval( $matchid[1][0] );
      			$hiarchy[$id] = $parent_id[$level];
      			$depth[$id]   = $level;
      			$level++;
      			$parent_id[$level] = $id;
      		}else{	 // closing tag
      			$level--;
      		}
      	}
      	return [ 
      		'hiarchy' => $hiarchy, 
      		'depth'   => $depth
      	];
    }

	private static function calculateBlockdata($hiarchy, $string, $defines)
	{

		// First, Is this thing Landscape or Portrait and is that defined by P,L or sizes?
		if ( !empty( $defines['orientation'] )) $orientation = $defines['orientation']; else $orientation = 'P';
		if ( $orientation == 'P' ) $size = '795px, 1123px'; else $size = '1123px, 795px';
		if ( !empty( $defines['size'] )) $size = $defines['size'];
		$sizes  = explode( ',', $size );
		$width  = self::dimensionUnit( $sizes[0] );
		$height = self::dimensionUnit( $sizes[1] );

		$blockdata = [];
		$blockdata[0] = [
			'absposx' => 0,
			'absposy' => 0,
 			'width'   => $width,
			'height'  => $height,
			];

 		foreach ($hiarchy as $key => $parent) {
 			
 			preg_match("/((block:{ $key }).*(\\[.*\\]))(.*)/", $string, $match);	// get tag id
// 			if ( empty( $match[2] ) ) exit;
 			$tag    = $match[2];
 			$attrb  = trim( str_replace( ['[',']'], '', $match[3]) );
 			$class  = $match[4];
 			
 			$parentwidth  = $blockdata[$parent]['width'];
	 		$parentheight = $blockdata[$parent]['height'];

 			$attrs  = explode( "," , $attrb );
 			$content_type = 'block';
 			if ( strpos( $attrb , '*image' ) !== false ) $content_type = 'image';
			if ( strpos( $attrb , '*rect' )  !== false ) $content_type = 'rect';

			// fill with defaults
			if ( empty($attrs[0]) ) $attrs[0] = '0';
			if ( empty($attrs[1]) ) $attrs[1] = '0';
			if ( empty($attrs[2]) ) $attrs[2] = '100%';
			if ( empty($attrs[3]) ) $attrs[3] = '100%';

 			$posx      = self::dimensionUnit( $attrs[0], $parentwidth , $defines ); // 0|%|px|mm
 			$posy      = self::dimensionUnit( $attrs[1], $parentheight, $defines ); // 0|%|px|mm
 			$width     = self::dimensionUnit( $attrs[2], $parentwidth , $defines ); // 0|%|px|mm
 			$height    = self::dimensionUnit( $attrs[3], $parentheight, $defines ); // 0|%|px|mm
 			$dpi       = 96;
 			$vertside  = 'top';
 			$horside   = 'left';
 			$bgcolor   = '255,255,255';
 			$fontcolor = '0,0,0';
 			$fontname  = 'arial';
			$fontsize  = 12;
			$textalign = 'left-align';

 			switch( $content_type )
 			{
 				case "image":
 					$vertside  = 'top';
		 			$horside   = 'left';
		 			$bgcolor   = 'inherit';
		 			if ( !empty($attrs[4]) ) $dpi = self::dimensionUnit( $attrs[4], 96 ); // dpi
 				break;

 				case "rect":
 					// var_dump( $attrs );
 					$bgparent = '255,255,255';
 					if ( !empty($blockdata[$parent]['bgcolor']) ) $bgparent = $blockdata[$parent]['bgcolor'];

 					$color   = self::findShorthandColor( $attrs , 1 , $bgparent );
 					$bgcolor = self::colorUnit( $color );  // #fff|#ffffff|r,g,b|color

 				break;

 				default:
 					$parentcolor = '0,0,0';
					$parentsize  = 12;
					$parentname  = 'arial';
					if ( !empty($blockdata[$parent]['fontcolor']) ) $parentcolor = $blockdata[$parent]['fontcolor'];
					if ( !empty($blockdata[$parent]['fontsize']) )  $parentsize  = $blockdata[$parent]['fontsize'];
					if ( !empty($blockdata[$parent]['fontname']) )  $parentname  = $blockdata[$parent]['fontname'];

					if ( empty($attrs[4]) ) $attrs[4] = 'top';
 					if ( empty($attrs[5]) ) $attrs[5] = 'left';
 					if ( empty($attrs[6]) ) $attrs[6] = $parentsize;
 					if ( empty($attrs[7]) ) $attrs[7] = $parentcolor;
 					if ( empty($attrs[8]) ) $attrs[8] = 'left-align';
 					if ( empty($attrs[9]) ) $attrs[9] = $parentname;
 				
	 				$vertside  = trim( $attrs[4] ); // top|center|bottom
		 			$horside   = trim( $attrs[5] ); // left|center|right
		 			$fontsize  = self::DimensionUnit( $attrs[6] ); // value
		 			$fontcolor = self::colorUnit( $attrs[7] ); // #fff|#ffffff|r,g,b|color|inherit
		 			$textalign = self::colorUnit( $attrs[8] ); // left-align|center-align|right-align
		 			$fontname  = trim( $attrs[9] ); // name
 			}

 			$blockdata[$key] = [
 				'note' 		=> 'all sizes are in mm',
 				'id'        => $key,
 				'parent'    => $parent,
				'posx'      => $posx,
				'posy'      => $posy,
				'absposx'   => $posx + $blockdata[$parent]['absposx'],
				'absposy'   => $posy + $blockdata[$parent]['absposy'],
				'width'     => $width,
				'height'    => $height,
				'dpi'		=> $dpi,
				'horside'   => $horside,
				'vertside'  => $vertside,
				'bgcolor'   => $bgcolor,
				'fontsize'  => $fontsize,
				'fontcolor' => $fontcolor,
				'textalign' => $textalign,
				'fontname'  => $fontname,
				'content'   => '',
				'ctype'     => $content_type,
 			];
 		}

 		return $blockdata;

	}


	// find the first value that is a color, 
	private static function findShorthandColor( $attrs , $first , $bgparent )
	{

		$id = 3;
		$next = true;
		while ( $next == true )
		{
			$id++;
			$check = trim( $attrs[$id] );
			if ( $check[0] == '#' ) return $check;
			if ( strpos( 'top|center|bottom', $check ) >-1 ){ $next= true; }
			if ( strpos( 'left|center|right', $check ) >-1 ){ $next= true; }
		}
		return $attrs[ $id + $first - 1 ];

	}


	// returns value in mm
	public static function dimensionUnit( $input , $size = 100, $defines = null ) 
	{

		// One millimetre is 2.83464567 PostScript points.
		// One point is 0.352777778 millimetres.

		$input = trim( $input );
		if ( $input == ''  ) return 0;
		if ( $input == '0' ) return 0;
		if ( $input[0] == '*' ) return 0;
		
		$mode = '';
		if ( substr( $input , -1 ) == '%' )   $mode ='%';
		if ( substr( $input , -2 ) == 'mm' )  $mode ='mm';
		if ( substr( $input , -2 ) == 'px' )  $mode ='px';
		if ( substr( $input , -2 ) == 'pt' )  $mode ='pt';
		if ( substr( $input , -3 ) == 'img' ) $mode ='img';
		if ( substr( $input , -3 ) == 'dpi' ) $mode ='dpi';

		$float = floatval( substr( $input, 0, strlen($input) - strlen($mode) ) );
	
		switch( $mode )
		{
			case "dpi": return $float;
			break; 
			case "img": return -1;
			break;
			
			case "%":   return ($float / 100) * $size; 
			break;		
			case "mm":  return $float;
			break;
			case "pt":  return $float * 0.352777778;
			break;
			case "px":
				$dpi = $defines['dpi'];  // Should be the value in define...
				if ( $dpi == 0 ) $dpi = 96;
				$i2m = 25.4; // inch to mm 
				return $float / $dpi * $i2m; 
			break;
		}

		return $float;

	}

	// returns value in r,g,b
	private static function colorUnit( $input ) 
	{
		// #fff|#ffffff|r,g,b|color
		$input = trim( $input );
		if ( $input == '' ) return null;
		
		if ( substr_count( $input, ',' ) == 2 ){
			if ( $input[0] == '(' ) $input = trim( substr( $input, 1, strlen($input)-2 ) );
			return $input;	
		} 
	
		if ( array_key_exists( $input, self::$colors ) ) return self::$colors[ $input ];

		if ( $input[0] == "#" )
		{
			$hex = trim(substr($input, 1));
			
			if ( strlen( $hex ) == 3 ){
				$r= hexdec( substr( $hex, 0, 1 ).substr( $hex, 0, 1 ) );
				$g= hexdec( substr( $hex, 1, 1 ).substr( $hex, 1, 1 ) );
				$b= hexdec( substr( $hex, 2, 1 ).substr( $hex, 2, 1 ) );
				return $r.','.$g.','.$b;
			} 
			$r= hexdec( substr( $hex, 0, 2 ) );
			$g= hexdec( substr( $hex, 2, 2 ) );
			$b= hexdec( substr( $hex, 4, 2 ) );
			
			return $r.','.$g.','.$b;
		}


		return $input;

	}


	private static function newlog()
	{
		$fp = fopen( storage_path('logs/mark.txt'), 'w');
		fwrite($fp, date("Y-m-d H:i:s") . "\n" );
		fclose($fp);
	}

	public static function log( $array ) 
	{
		// echo nl2br( print_r ( $array , true ));
		$fp = fopen( storage_path('logs/mark.txt'), 'a');
		fwrite($fp, print_r($array, TRUE) . "\n" );
		fclose($fp);
	}


}