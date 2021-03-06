<?php
require_once 'common.php';
require_once 'common_chart.php';

// If they don't ask for indoor, they get outdoor.
$indoor = 0;
if( isset( $_GET['Indoor'] ) )
{
	$indoor = $_GET['Indoor'];
	if( $indoor < 0 || $indoor > 2)
	{ // If they hose the input, they get outdoor
		$indoor = 0;
	}
}

$to_date = date( 'Y-m-d' );
if( isset( $_GET['history_to_date'] ) )
{ // Use provided date
  $to_date = $_GET['history_to_date'];
}
if( ! validate_date( $to_date ) ) return;
// Verify that date is not future?

$from_date = date( 'Y-m-d' );
if( isset( $_GET['history_from_date'] ) )
{ // Use provided date
  $from_date = $_GET['history_from_date'];
}
if( ! validate_date( $from_date ) ) return;
// Verify that date is at least three DAYS before the to_date?


// If they don't ask for runtime, they don't get it.
$show_hvac_runtime = false;
if( isset( $_GET['show_hvac_runtime'] ) && ( $_GET['show_hvac_runtime'] == 1 || $_GET['show_hvac_runtime'] == 'true' ))
{
	$show_hvac_runtime = true;
}

$sql = "SELECT
					a.date,
					a.outdoor_max,
					a.outdoor_min,
					a.indoor_max,
					a.indoor_min,
					IFNULL(b.heat_runtime, 'VOID') AS heat_runtime,
					IFNULL(b.cool_runtime, 'VOID') AS cool_runtime
				FROM ( SELECT
								 DATE(date) AS date,
								 tstat_uuid,
								 MAX(outdoor_temp) AS outdoor_max,
								 MIN(outdoor_temp) AS outdoor_min,
								 MAX(indoor_temp) AS indoor_max,
								 MIN(indoor_temp) AS indoor_min
							 FROM {$dbConfig['table_prefix']}temperatures
							 WHERE tstat_uuid = ?
							 AND DATE(date) BETWEEN '$from_date' AND '$to_date'
							 GROUP BY DATE(date) ) a
				LEFT JOIN {$dbConfig['table_prefix']}run_times b
				ON a.date = DATE( b.date ) AND a.tstat_uuid = b.tstat_uuid";
$query = $pdo->prepare( $sql );
$query->execute( array( $uuid ) );
/**
	* This is a holdover concern from the HVAC Runtime chart code...
	*	It turns out that this charting code performs poorly when there is no data in the table. (it doesn't work!)
	*	Bug?	When it is between 00:00 and 00:30 the bars seem to be offset one position to the right?
	*/


// Create and populate the pData object
$MyData = new pData();

// Set default boundaries for chart
$chart_y_min = $normal_low;
$chart_y_max = $normal_high;
$chart_runtime_min = 0;
$chart_runtime_max = 1440;
/**
	* Set 24 hours (1440 miunutes in the day) as max run time.
	* Originally had 3 hours, but regularly saw 15+ hours (900 minutes) of A/C runtime in summer!
	*
	* The chart software rounds up to 1500.
	*/


$old_month = -1;
$days = $query->rowCount();	// Determine the number of days in the resultset.
$first = true;
while( $row = $query->fetch( PDO::FETCH_ASSOC ) )
{
	/**
		*	When there are too many dates the X-Axis labels turn into one ugly black line.
		*
		*	So use logic to alter the display depending on how many days/data points are in the resultset.
		*
		*	Sometimes there is NO data at all for a given date so look for month change as flag
		*/

  if( $first )
  { // Always show the WHOLE date for the first item.
    $MyData->addPoints( $row[ 'date' ], 'Labels' );
    $first = false;
  }
	else
	{
    if( $days > 120 )
		{	// For ultra-long date ranges, only show month changes in the X-Axis.  "Ultra long" is anything over 4 months.
			if( substr( $row['date'], 5, 2 ) != $old_month )
			{ // Thereafter show only MM-DD when you show anything at all
				// Show month name ala 'Dec'
				$MyData->addPoints( date('M', mktime( 0, 0, 0, substr( $row['date'], 5, 2 ), 1) ), 'Labels' );
			}
			else
			{ // Add a blank instead of text for some x-axis labels.
				$MyData->addPoints( VOID, 'Labels' );
			}
		}
    else if( $days > 14 )
		{	// For long date ranges, show the first day of each week in the X-Axis.  "Long" is 2 weeks to 4 months.
      if( date_format( date_create($row[ 'date' ]), 'N' ) == 7 )
      { // Show the date only for the first day of each week
        $MyData->addPoints( substr( $row[ 'date' ], 5, 5 ), 'Labels' );
      }
      else
      { // Placekeeper for non-shown dates
        $MyData->addPoints( VOID, 'Labels' );
      }
		}
		else
    { // For short date ranges show every day.  "Short" is two weeks or less
      $MyData->addPoints( substr( $row[ 'date' ], 5, 5 ), 'Labels' );
		}
	}
	$old_month = substr( $row['date'], 5, 2 );

	// The fake 1.5 degree difference forced into the data here is to prevent a charting bug from inverting the colors in some regions
	if( $indoor == 0 || $indoor == 2 )
	{
		$MyData->addPoints( $row[ 'outdoor_min' ] - 0.75, 'Outdoor Min' );
		$MyData->addPoints( $row[ 'outdoor_max' ] + 0.75, 'Outdoor Max' );
		if( $row[ 'outdoor_min' ] < $chart_y_min ) $chart_y_min = $row[ 'outdoor_min' ];
		if( $row[ 'outdoor_max' ] > $chart_y_max ) $chart_y_max = $row[ 'outdoor_max' ];
	}
	if( $indoor == 1 || $indoor == 2 )
	{
		$MyData->addPoints( $row[ 'indoor_min' ] - 0.75, 'Indoor Min' );
		$MyData->addPoints( $row[ 'indoor_max' ] + 0.75, 'Indoor Max' );
		if( $row[ 'indoor_min' ] < $chart_y_min ) $chart_y_min = $row[ 'indoor_min' ];
		if( $row[ 'indoor_max' ] > $chart_y_max ) $chart_y_max = $row[ 'indoor_max' ];
	}

	if( $show_hvac_runtime )
	{
		if( $row[ 'heat_runtime' ] != 'VOID' ) $MyData->addPoints( $row[ 'heat_runtime' ], 'Heat' );
		else $MyData->addPoints( VOID, 'Heat' );

		if( $row[ 'heat_runtime' ] != 'VOID' ) $MyData->addPoints( $row[ 'cool_runtime' ], 'Cool' );
		else $MyData->addPoints( VOID, 'Cool' );
	}
}

// Add a little padding so the chart doesn't butt into the edges
$chart_y_min -= 5;
$chart_y_max += 5;

// Attach the data series to the axis (by ordinal)
if( $indoor == 0 || $indoor == 2 )
{
	$MyData->setSerieOnAxis( 'Outdoor Min', 0 );
	$MyData->setSerieOnAxis( 'Outdoor Max', 0 );
}
if( $indoor == 1 || $indoor == 2 )
{
	$MyData->setSerieOnAxis( 'Indoor Min', 0 );
	$MyData->setSerieOnAxis( 'Indoor Max', 0 );
}

if( $show_hvac_runtime )
{	// Conditional code in case run time was requested.
	$MyData->setSerieOnAxis( 'Heat', 1 );									// Connect data series to secondary axis
	$MyData->setSerieOnAxis( 'Cool', 1 );

	// Set line style, color, and alpha blending level
	$MyData->setSerieTicks( 'Heat', 0 );	// 0 is a solid line
	$serieSettings = array( 'R' => 150, 'G' => 50, 'B' => 80, 'Alpha' => 90 );
	$MyData->setPalette( 'Heat', $serieSettings );

	$serieSettings = array( 'R' => 50, 'G' => 150, 'B' => 180, 'Alpha' => 90 );
	$MyData->setPalette( 'Cool', $serieSettings );

	$MyData->setAxisPosition( 1, AXIS_POSITION_RIGHT );		// Draw runtime axis on right hand side
	$MyData->setAxisName( 1, 'Minutes' );									// Set names for Y-axis labels

}

// Set names for Y-axis labels
$MyData->setAxisName( 0, 'Temperatures' );

// Set names for X-axis labels
$MyData->setSerieDescription( 'Labels', 'Days' );
$MyData->setAbscissa( 'Labels' );

$myPicture = new pImage( 900, 430, $MyData ); // Create the pChart object
$myPicture->Antialias = TRUE;								 // Turn on Antialiasing

// Draw the background
$Settings = array( 'R' => 170, 'G' => 183, 'B' => 87, 'Dash' => 1, 'DashR' => 190, 'DashG' => 203, 'DashB' => 107, 'Alpha' => 60 );
$myPicture->drawFilledRectangle( 0, 0, 900, 430, $Settings );

// Overlay with a gradient
$Settings = array( 'StartR' => 219, 'StartG' => 231, 'StartB' => 139, 'EndR' => 1, 'EndG' => 138, 'EndB' => 68, 'Alpha' => 50 );
$myPicture->drawGradientArea( 0, 0, 900, 430, DIRECTION_VERTICAL, $Settings );
$Settings = array( 'StartR' => 0, 'StartG' => 0, 'StartB' => 0, 'EndR' => 50, 'EndG' => 50, 'EndB' => 50, 'Alpha' => 80 );
$myPicture->drawGradientArea( 0, 0, 900,	20, DIRECTION_VERTICAL, $Settings );

// Add a border to the picture
$myPicture->drawRectangle( 0, 0, 899, 429, array( 'R' => 0, 'G' => 0, 'B' => 0 ) );

// Set font for all descriptive text
$myPicture->setFontProperties( array( 'FontName' => 'lib/fonts/Copperplate_Gothic_Light.ttf', 'FontSize' => 10 ) );

// Write the picture title
$myPicture->drawText( 10, 14, 'Show the historic temperatures', array( 'R' => 255, 'G' => 255, 'B' => 255) );

// Write the chart title
$myPicture->drawText( 60, 55, 'Min/Max for each day in the record', array( 'FontSize' => 12, 'Align' => TEXT_ALIGN_BOTTOMLEFT ) );

$myPicture->setGraphArea( 60, 60, 850, 390 );	 // Define the chart area

// Explicity set a scale for the drawing.
if( $show_hvac_runtime )
{	// Include temperature and runtime in this scale
	$AxisBoundaries = array( 0 => array ( 'Min' => $chart_y_min, 'Max' => $chart_y_max ), 1 => array ( 'Min' => $chart_runtime_min, 'Max' => $chart_runtime_max ) );
}
else
{	// Only include temeprature in this scale
	$AxisBoundaries = array( 0 => array ( 'Min' => $chart_y_min, 'Max' => $chart_y_max ) );
}

// Draw the scale
$myPicture->setFontProperties( array( 'FontName' => 'lib/pChart2.1.3/fonts/pf_arma_five.ttf', 'FontSize' => 6 ) );
$Settings = array( 'Mode' => SCALE_MODE_MANUAL, 'ManualScale' => $AxisBoundaries, 'GridR' => 200, 'GridG' => 200, 'GridB' => 200, 'LabelingMethod' => LABELING_DIFFERENT, 'DrawSubTicks' => TRUE, 'CycleBackground' => TRUE );
//$Settings = array( 'Pos' => SCALE_POS_LEFTRIGHT, 'Mode' => SCALE_MODE_MANUAL, 'ManualScale' => $AxisBoundaries, 'LabelingMethod' => LABELING_ALL, 'GridR' => 255, 'GridG' => 255, 'GridB' => 255, 'GridAlpha' => 50, 'TickR' => 0, 'TickG' => 0, 'TickB' => 0, 'TickAlpha' => 50, 'LabelRotation' => 0, 'CycleBackground' => 1, 'DrawXLines' => 1, 'DrawSubTicks' => 1, 'SubTickR' => 255, 'SubTickG' => 0, 'SubTickB' => 0, 'SubTickAlpha' => 50, 'DrawYLines' => array(0) );
$myPicture->drawScale( $Settings );

/**
	* Write the chart legend
	*
	* Frustrating that there is no actual auto-position function for this legend.
	* Since the 'indoor' and 'outdoor' texts are different lengths they have to be manually positioned.
	* The right hand end of the legend is aligned between these two charts and all the others too.
	*/
$myPicture->setFontProperties( array( 'FontName' => 'lib/pChart2.1.3/fonts/pf_arma_five.ttf', 'FontSize' => 6 ) );
$myPicture->setShadow( TRUE, array( 'X' => 1, 'Y' => 1, 'R' => 0, 'G' => 0, 'B' => 0, 'Alpha' => 10 ) );
/*
if( $show_hvac_runtime )
{	// Conceal the runtime data that will be displayed as bar chart from the temperature graph code
	$MyData->setSerieDrawable( 'Heat', FALSE );
	$MyData->setSerieDrawable( 'Cool', FALSE );
}
*/
if( $indoor == 0 || $indoor == 2 )
{
	$Settings = array( 'AreaR' => 200, 'AreaG' => 100, 'AreaB' => 100, 'AreaAlpha' => 80 );
	$myPicture->drawZoneChart( 'Outdoor Min', 'Outdoor Max', $Settings );
}
if( $indoor == 1 || $indoor == 2 )
{ // Each letter in the font I've picked is 10 pixels wide.
	$Settings = array( 'AreaR' => 100, 'AreaG' => 100, 'AreaB' => 200, 'AreaAlpha' => 80 );
	$myPicture->drawZoneChart( 'Indoor Min', 'Indoor Max', $Settings );
}

if( $show_hvac_runtime )
{	// If the runtimes were requested and data loaded then
	// draw the run times as bar charts
	$MyData->setSerieDrawable( 'Heat', TRUE );
	$MyData->setSerieDrawable( 'Cool', TRUE );

	if( $indoor == 0 || $indoor == 2 )
	{
		$MyData->setSerieDrawable( 'Outdoor Min', FALSE );
		$MyData->setSerieDrawable( 'Outdoor Max', FALSE );
	}
	if( $indoor == 1 || $indoor == 2 )
	{
		$MyData->setSerieDrawable( 'Indoor Min', FALSE );
		$MyData->setSerieDrawable( 'Indoor Max', FALSE );
	}

	$myPicture->drawBarChart( array( 'Gradient' => 1, 'AroundZero' => TRUE, 'Interleave' => 2 ) );

	$myPicture->drawThreshold( 300, array( 'AxisID' => 1, 'WriteCaption' => TRUE, 'Caption' => ' 5 Hours', 'BoxAlpha' => 90, 'BoxR' => 255, 'BoxG' => 40, 'BoxB' => 70, 'Alpha' => 100, 'Ticks' => 1, 'R' => 255, 'G' => 40, 'B' => 70 ) );
	$myPicture->drawThreshold( 600, array( 'AxisID' => 1, 'WriteCaption' => TRUE, 'Caption' => '10 Hours', 'BoxAlpha' => 90, 'BoxR' => 255, 'BoxG' => 40, 'BoxB' => 70, 'Alpha' => 100, 'Ticks' => 1, 'R' => 255, 'G' => 40, 'B' => 70 ) );
	$myPicture->drawThreshold( 900, array( 'AxisID' => 1, 'WriteCaption' => TRUE, 'Caption' => '15 Hours', 'BoxAlpha' => 90, 'BoxR' => 255, 'BoxG' => 40, 'BoxB' => 70, 'Alpha' => 100, 'Ticks' => 1, 'R' => 255, 'G' => 40, 'B' => 70 ) );
}


//$myPicture->setShadow( TRUE, array( 'X' => 1, 'Y' => 1, 'R' => 0, 'G' =>0 , 'B' =>0 , 'Alpha' => 10 ) );
//$myPicture->setFontProperties( array( 'FontName' => 'lib/pChart2.1.3/fonts/Forgotte.ttf', 'FontSize' => 11 ) );

// Render the picture
$myPicture->autoOutput( 'images/weekly_chart.png' );
?>