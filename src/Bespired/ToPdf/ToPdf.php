<?php

namespace Bespired\ToPdf;

//use Fpdf;
use Anouar\Fpdf\Fpdf;
use Illuminate\Support\Facades\View;



class ToPdf{

	
	// Ja Sorry, nog niet recursive...
	public static function translate( $content, $data ){

// 		header('Content-type: text/html; charset=utf-8');

		// plat slaan
 		$label = [];
 		foreach ($data as $key1 => $value1) {
 			$type = gettype( $data[$key1] );
 			if ( $type != 'array' ) $label[$key1] = $value1;
			if ( $type == 'array' )
			{
				foreach ($value1 as $key2 => $value2) {
		 			$type = gettype( $data[$key1][$key2] );
		 			if ( $type != 'array' ) $label["$key1.$key2"] = $value2;
					if ( $type == 'array' )
					{
						foreach ($value2 as $key3 => $value3) {
			 				$type = gettype( $data[$key1][$key2][$key3] );
			 				if ( $type != 'array' ) $label["$key1.$key2.$key3"] = $value3;
						}
			 		}
		 		}
			} 	
		} 

		// langste eerst graag
		uksort( $label,  function($a, $b)
			{
				if (strlen($a) == strlen($b)) return 0;
    			if (strlen($a) >  strlen($b)) return -1;
    			return 1;
			}
		);

		// zoek en vervang
		$str_content = json_encode( $content );
		foreach ($label as $key => $value) {
			$str_content = str_replace( ":$key", trim($value), $str_content );
		}
		$content = json_decode( $str_content , true );


		return $content;

	}


	public static function dirCleanup( $path )
	{
		if ($handle = opendir($path)) {
			while (false !== ($file = readdir($handle))) {
				if ((time()-filectime($path.$file)) > 60 * 10 ) {  
					if (preg_match('/\.pdf$/i', $file)) {
						@unlink( $path.$file );
					}
				}
			}
		}
	}


	public static function create( $mark, $txtfile = [], $dataJSON = '{}', $lang = 'nl' )
	{
		
		$ccrm = json_decode( $dataJSON , true );
		
	 	$data = json_decode( $ccrm['data'], true );
	 	$data = array_merge( $ccrm, $data );
	 	
	 	$data['day']   = date( 'j' );
	 	$data['month'] = trans( 'date.month.' . date( 'n' ) );
	 	$data['year']  = date( 'Y' );

	 	$content= trans( $txtfile , $data, $lang );  	// Array met zinnetjes uit de 'juiste' taal bak. 

	 	$content= ToPdf::translate( $content, $data );  // Vertaal alle :attributes 
	 	
	 	$data = array_merge( $data, $content );
	 	$data['data'] = '';

	 	$stamp = strtolower( substr($data['lastname'],0,12 )) . date('U');
	 	$stamp = preg_replace("/[^A-Za-z0-9 ]/", '', $stamp );

	 	@mkdir( storage_path( 'media/pdf/') );
	 	self::dirCleanup( storage_path( 'media/pdf/') );

		$filename = storage_path( 'media/pdf/' . $stamp . '.pdf' );


		self::mark( $mark, $data , $filename );
//		self::mark( $mark, $data  );


		return $filename;

	}

	public static function preview( $mark, $txtfile = [], $dataJSON = '{}', $lang = 'nl' )
	{
		
		$ccrm = json_decode( $dataJSON , true );
		
	 	$data = json_decode( $ccrm['data'], true );
	 	$data = array_merge( $ccrm, $data );
	 	
	 	$data['day']   = date( 'j' );
	 	$data['month'] = trans( 'date.month.' . date( 'n' ) );
	 	$data['year']  = date( 'Y' );

	 	$content= trans( $txtfile , $data, $lang );  	// Array met zinnetjes uit de 'juiste' taal bak. 

	 	$content= ToPdf::translate( $content, $data );  // Vertaal alle :attributes 
	 	
	 	$data = array_merge( $data, $content );
	 	$data['data'] = '';


		self::mark( $mark, $data  );


		return $filename;

	}


	public static function mark( $view, $data , $filename ='' )
	{
		
		$mark = new Mark();
		$mark->view( $view, $data );

		$orientation = "P";
		$black = '0,0,0';
		$white = '255,255,255';
		$fontname = 'Arial';
		$fontsize = 12;
		
		foreach (['orientation','white','black','fontname','fontsize','size'] as $variable) {
			if ( isset( $mark->defines[$variable] ) )
			{
				$$variable = $mark->defines[$variable];
			} 	
		}

		$sizes = explode( ',', $size );
		foreach ($mark->pages as $key => $page) {
			$mark->pages[$key][0]['width']  = Mark::dimensionUnit( $sizes[0] );
			$mark->pages[$key][0]['height'] = Mark::dimensionUnit( $sizes[1] );
		}


//		dd( $mark->pages );

		$pdf = new Cpdf(  $orientation , 'mm', 'A4' );
		
		$pdf->setDefines( $mark->defines );


		$pdf->SetFontName( $fontname );
		$pdf->SetFontSize( $fontsize );
		
		$pdf->SetRectColor( $white );
		$pdf->SetFontColor( $black );
		
        foreach ($mark->pages as $page => $block) {
		
			$pdf->AddPage();
        	$pdf->SetAutoPageBreak(false, 0);
        	$pdf->SetMargins(0,0);
        
        	foreach( $block as $array )
        	{
        		$data = self::object( $array );
        		
        		switch( $data->ctype )
        		{

        			
        			case 'image':
        				$pdf->Image( $data->content, $data->absposx, $data->absposy );
        				Mark::log( 'image ' . $data->content );

        			break;

        			case 'rect':
        				$pdf->SetRectColor( $data->bgcolor );
    					$pdf->Rect( $data->absposx , $data->absposy, $data->width , $data->height , 'DF' );
    					Mark::log( 'rect ' . $data->id );
        			
        			break;

        			case 'block':

        				// Ja links lijnend is leuk...
        				// Maar centreren of rechts lijnen is dus helemaal niet leuk...
        				// Dan moeten we eerst de width per regel gaan bepalen en [br] invoegen...

        				$pdf->SetFontColor( $data->fontcolor );
        				$pdf->SetFontName( $data->fontname );
        				$pdf->SetFontSize( $data->fontsize );

        				$pdf->ClearFontStyle();

        				if ( $data->content == '' ) break;
        				
        				$rows  = [];
        				$defs  = [];
        				$content  = str_replace( '[BR]', '[br]', $data->content );
						$content  = str_replace( '[p:]', '[br][br]', $content );
        				$lines = explode( '[br]', $content );

        				$linesize = 1.3 * $data->fontsize;

        				foreach ($lines as $key => $line) {
        					$rows = self::appendRows( $line , $rows , $pdf, $data );
        				}

        				$def_align = 'left';
        				$align     = 'left';
        				$mode      = '';

        				foreach ($rows as $key => $row) {
        					$row = strtolower( $row );
        					if ( strpos( $row, '[right:]'  ) > -1 ) $align = 'right';
        					if ( strpos( $row, '[left:]'   ) > -1 ) $align = 'left';
        					if ( strpos( $row, '[center:]' ) > -1 ) $align = 'center';
        					$defs[$key] = $align;
        					if ( strpos( $row, '[:center]' ) > -1 ) $align = $def_align;
        					if ( strpos( $row, '[:right]'  ) > -1 ) $align = $def_align;
        					if ( strpos( $row, '[:left]'   ) > -1 ) $align = $def_align;
        				}
						
					//	dd( $defs );

        				$pdf->SetXY( $data->absposx , $data->absposy );
        				$px = $data->absposx;

						foreach ($rows as $key => $row) {

							$newline = false;

							// remove tags from row for width
							$re = "/(\\[(.*?)\\])/"; 
							$line= preg_replace($re, '', $row);

							$rowwidth = $pdf->GetStringWidth( $line );
							if ( strpos( $defs[$key], 'left'   ) > -1 ) { $px = $data->absposx; }
							if ( strpos( $defs[$key], 'center' ) > -1 ) { $px = $data->absposx + ( $data->width / 2 ) - ( $rowwidth / 2 ); }
							if ( strpos( $defs[$key], 'right'  ) > -1 ) { $px = $data->absposx + $data->width - $rowwidth; }

							$pdf->SetX( $px );

							$link = '';
							$linedata = self::lineSplitter( $row, $mark->defines );
							foreach ($linedata->parts as $idx => $part) {
								
								$pdf->setStyle( $linedata->class[$idx] , $mark->defines );
								$mode = self::setMode( $linedata->class[$idx] , $mode );

								Mark::log( "class: " . $linedata->class[$idx] );
								
								if ( $part != '' ){

									$str = iconv('UTF-8', 'windows-1252', $part );

									if ( strpos( $mode, 'C' ) >-1 ) $str = strtoupper( $str );

									$pdf->Write( $linesize, $str , $link );
									$newline = true;
									
									Mark::log( "mode:  $mode write: $str" );
								} 

							}
							if ( ( $linedata->class[$idx] == '' ) or ( $newline = true ) )
							{
								$pdf->SetY( $pdf->GetY() + $linesize );
							}
							
        				}
        								
						Mark::log( 'block ' . $data->id . ' x:' . $data->absposx . ', y:' . $data->absposy );
						Mark::log( 'bg:' . $data->bgcolor . ', fg:' . $data->fontcolor . ' content ' . $data->content );
        			break;

        		}
        		
        	}
			
		}

		if ( $filename != '' )
		{
			$pdf->Output( $filename, 'F' );
		}else{
			$pdf->Output();
		}

		return;
	}

	private static function setMode( $style, $mode )
	{

		switch( $style )
		{
			case 'cap:' : $mode .= 'C'; break;
			case ':cap' : $mode = str_replace( 'C', '', $mode ); break;
		}

		return $mode;

	}

	private static function appendRows( $line, $rows, $pdf, $data )
	{
		if ( $line == '' ) { $rows[] = ''; return $rows; }

		// remove tags from width test...
		$re = "/(\\[(.*?)\\])/"; 
		foreach ( str_split('~^%@!`*') as $delimit)
		{	
			if ( strpos( $line, $delimit ) == -1 ) break;   
		}
		$result= preg_replace($re, '', $line);


		$pdf->SetFontSize( $data->fontsize );
		$width = $pdf->GetStringWidth( $result );

		if ( $width < $data->width )
		{
			$rows[] = $line;
			return $rows;			
		}

		$words = explode( " ", $line );
		$build = '';
		foreach ($words as $key => $word) {
			
			$result= preg_replace($re, '', $build .' '. $word );
			$width = $pdf->GetStringWidth( $result );
			if ( $width > $data->width )
			{
				$rows[] = trim( $build );
				$build = $word;
			}else{
				$build .= ' ' . $word;
			}
		}
		$rows[] = trim( $build );

		return $rows;
	}

	private static function appendDefs( $line, $defs )
	{
		if ( $line == '' ) return $defs;
		$defs[] = '-';
		return $defs;
	}



	private static function lineSplitter( $string, $defines )
	{
		$object = new \stdClass();

		if ( strpos($string,'[') === false ){
			$object->class[] = '';
			$object->parts[] = $string;
			return $object;
		} 

		$re = "/(\\[(.*?)\\])/"; 
		// $str = "Beste {{ \$firstname }},[br]Het is ons ter ore [bold:][red:]gekomen[:red] dat u graag[:bold] mail van ons krijg.[br]Nou bij deze..."; 
		foreach ( str_split('~^%@!`*') as $delimit)
		{
			if ( strpos( $string, $delimit ) == -1 ) break;
		}
		
 		preg_match_all($re, $string, $matches);
		$result = explode( $delimit, preg_replace($re, $delimit, $string));

		$object->class = $matches[2];
		array_unshift( $object->class, '' );
		$object->parts = $result;

		return $object;
	}


	public static function object( $array )
	{
		$object = new \stdClass();
        foreach ($array as $key => $value) 
        { 
        	$object->$key = $value; 
        }
        return $object;
    }

	public static function rgb( $string , $default = null )
	{
		if ( strpos($string, ',') === false )
		{
			if ( $default !== null ) return $default;
			$string = '255,255,255';
		} 
		$comp = explode( ',', $string );
		$colors= new \stdClass();
		$colors->r = $comp[0];
		$colors->g = $comp[1];
		$colors->b = $comp[2];

		return $colors;
	}

}


class Cpdf extends Fpdf
{
	protected $font = [
		'size'  => '',
		'color' => '',
		'name'  => '',
		'style' => '',
		'keepfont' => '',
		'keepsize' => '',
		'keepname' => '',
	];

	protected $bgcolor;
	protected $fgcolor;
	
	protected $defines;

	function Header(){}

	function setDefines( $defines )
	{
		$this->defines = $defines;
		
		// ToPdf font Path
		$this->fontpath = __DIR__ . '/fonts/' ;

		foreach ($defines as $key => $define) {
			if ( substr( $key, 0, 5 ) == 'font-' )
			{	
				$delimit = ' ';
				$parts = explode( $delimit, $define );
				$modes = strtolower( $parts[0] );
				$style = '';
				if ( strpos( $modes, 'i' ) >-1 ) { array_shift( $parts ); $style .= "I"; }
				if ( strpos( $modes, 'b' ) >-1 ) { array_shift( $parts ); $style .= "B"; }
				// var_dump( $parts );
				if ( !isset($parts[1]) ) $parts[1] = strtolower( $fontname . $style ) ;
				$fontname  = $parts[0];
				$phpzfile  = $parts[1];

				$this->AddFont( $fontname, $style, $phpzfile.'.php' );
			}
		}
		
		
	}

	function SetFontSize( $size )
	{
		// input as mm
		// but pdf wants pt
		// One millimetre is 2.83464567 PostScript points.
		$size *= 2.83464567;
		if ( $size == $this->font['size'] ) return;
		$this->font['size'] = $size;
		$this->SetFont( $this->font['name'], $this->font['style'], $this->font['size'] );
	}

	function SetFontName( $name )
	{
		if ( $name == $this->font['name'] ) return;
		$this->font['name'] = $name;
		$this->SetFont( $this->font['name'], $this->font['style'], $this->font['size'] );
	}

	function SetFontStyle( $style )
	{
		if ( $style == $this->font['style'] ) return;
		$this->font['style'] = $style;
		$this->SetFont( $this->font['name'], $this->font['style'], $this->font['size'] );
	}
	function AddFontStyle( $style )
	{
		$fontstyle = $this->font['style'];
		if ( strpos( $fontstyle , $style[0] ) === false  ) $fontstyle .= $style[0];
		if ( $style[0] == 'c' ) $fontstyle = '';
		$this->SetFontStyle( $fontstyle );
	}

	function ClearFontStyle()
	{
		$this->font['style'] = '';
		$this->SetFontStyle( '' );

	}

	function DelFontStyle( $style )
	{
		$fontstyle = $this->font['style'];
		$fontstyle = str_replace( $style[0], '', $fontstyle );
		if ( $style[0] == 'c' ) $fontstyle = '';
		$this->SetFontStyle( $fontstyle );
	}

	function SetFontColor( $color )
	{
		if ( $color == $this->font['color'] ) return;
		$this->font['color'] = $color;
		$this->SetTextColor( $this->rgb($color)->r, $this->rgb($color)->g, $this->rgb($color)->b );
	}

	function SetRectColor( $color )
	{
		if ( ( $color == $this->fgcolor )&&( $color == $this->bgcolor ) ) return;
		$this->bgcolor = $color; $this->fgcolor = $color;
		$this->SetDrawColor( $this->rgb($color)->r, $this->rgb($color)->g, $this->rgb($color)->b );
    	$this->SetFillColor( $this->rgb($color)->r, $this->rgb($color)->g, $this->rgb($color)->b );
	}

	function SetStyle( $style )
	{
		if ( $style == '' ) return;

		if ( $style[0] == ':' )
		{ 
			$this->styleEnd( substr($style, 1 ));
			return; 
		}
		if ( $style[0] == '.' )
		{ 
			$this->styleClass( substr($style, 1 )); 
			return; 
		}
		if ( substr( $style, -1 ) == ':' )
		{
			$this->styleStart( substr($style, 0, -1 )); 
			return;
		}
		return;
	}

	function styleStart( $style )
	{
		$style = strtolower( $style );
		if ( in_array( $style, ['b','i','u','c','bold','italic','underline','clear'] ) === true )
		{
			$this->AddFontStyle( $style );
		}
		if ( in_array( $style, ['blue', 'green', 'red', 'black'] ) === true )
		{
			$this->font['keepfont'] = $this->font['color'];
			$this->SetFontColor( $this->defines[$style] );
		}
		if ( in_array( $style, ['h1', 'h2', 'h3'] ) === true )
		{
			// var_dump( $this->defines[$style] );
			$this->font['keepsize'] = $this->font['size'];
			$this->font['keepname'] = $this->font['name'];

			$defs = explode( " ", trim( $this->defines[$style] ));
			$fontsize = $defs[0] / 2.83464567;
			$fontname = $defs[1];

			$this->SetFontSize( $fontsize );
			$this->SetFontName( $fontname );
		}


	}

	function styleEnd( $style )
	{
		$style = strtolower( $style );
		if ( in_array( $style, ['b','i','u','c','bold','italic','underline','clear'] ) === true )
		{
			$this->DelFontStyle( $style );
		}

		if ( in_array( $style, ['blue', 'green', 'red', 'black'] ) === true )
		{
			$this->SetFontColor( $this->font['keepfont'] );
		}
		if ( in_array( $style, ['h1', 'h2', 'h3'] ) === true )
		{
			$this->SetFontSize( $this->font['keepsize'] / 2.83464567 );
			$this->SetFontName( $this->font['keepname'] );
		}
	}


	function styleClass( $style )
	{
		dd( 'class' );
	}

	function rgb($color)
	{
		$comp = explode( ',', $color );
		$colors= new \stdClass();
		$colors->r = $comp[0];
		$colors->g = $comp[1];
		$colors->b = $comp[2];
		return $colors;
	}

}
