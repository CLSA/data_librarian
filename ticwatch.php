<?php
require_once 'common.php';

$base_dir = sprintf( '%s/%s', DATA_DIR, TEMPORARY_DIR );
$study_uid_lookup = get_study_uid_lookup( TICWATCH_IDENTIFIER_NAME );

// Process all Ticwatch files
// Each site has their own directory, and in each site directory there are sub-directories for
// each modality (actigraph, ticwatch, etc).  Within the ticwatch directory there are directories
// named after the participant's study_id, and another sub-directory with the serial number.
// For example: "temporary/XXX/ticwatch/<study_id>/<serial>"
output( sprintf( 'Processing ticwatch directories in "%s"', $base_dir ) );
$dir_count = 0;
$file_count = 0;
foreach( glob( sprintf( '%s/[A-Z][A-Z][A-Z]/ticwatch/*/*', $base_dir ), GLOB_ONLYDIR ) as $serial_dirname )
{
  $study_dirname = preg_replace( '#/[^/]+$#', '', $serial_dirname );
  $matches = [];
  if( false === preg_match( '#/([^/]+)/([^/]+)$#', $serial_dirname, $matches ) )
  {
    fatal_error( sprintf( 'Error while processing directory "%s"', $serial_dirname ), 4 );
  }

  $original_study_id = $matches[1];
  $study_id = strtoupper( trim( $original_study_id ) );
  if( !array_key_exists( $study_id, $study_uid_lookup ) )
  {
    if( VERBOSE ) output( sprintf(
      'Cannot transfer ticwatch directory due to missing UID lookup for study ID "%s"',
      $study_id
    ) );
    if( !TEST_ONLY && !KEEP_FILES ) move_from_temporary_to_invalid( $study_dirname ); 
    continue;
  }
  $uid = $study_uid_lookup[$study_id];

  $destination_directory = sprintf(
    '%s/raw/%s/%s/ticwatch/%s',
    DATA_DIR,
    TICWATCH_STUDY_NAME,
    TICWATCH_STUDY_PHASE,
    $uid
  );

  // make sure the directory exists (recursively)
  if( !TEST_ONLY && !is_dir( $destination_directory ) ) mkdir( $destination_directory, 0755, true );

  // make a list of all files to be copied and note the latest date
  $latest_date = NULL;
  $file_pair_list = [];
  foreach( glob( sprintf( '%s/*', $serial_dirname ) ) as $filename )
  {
    $destination_filename = substr( $filename, strrpos( $filename, '/' )+1 );

    // remove any identifiers from the filename
    $destination_filename = preg_replace(
      sprintf( '/^%s_/', $study_id ),
      '',
      $destination_filename
    );

    // see if there is a date in the filename that comes after the latest date
    if( preg_match( '#_(20[0-9]{6})\.#', $destination_filename, $matches ) )
    {
      $date = intval( $matches[1] );
      if( is_null( $latest_date ) || $date > $latest_date ) $latest_date = $date;
    }

    // remove the unneeded filename details
    $destination = str_replace( 'TicWatch Pro 3 Ultra GPS_', '', $destination_filename );
    $destination = str_replace( sprintf( '%s_', $original_study_id ), '', $destination );
    $destination = sprintf( '%s/%s', $destination_directory, $destination );

    $file_pair_list[] = [
      'source' => $filename,
      'destination' => $destination
    ];
  }

  // only copy files if they are not older than any files in the destination directory
  $latest_existing_date = NULL;
  foreach( glob( sprintf( '%s/*', $destination_directory ) ) as $filename )
  {
    $existing_filename = substr( $filename, strrpos( $filename, '/' )+1 );
    
    // see if there is a date in the filename that comes after the latest date
    if( preg_match( '#_(20[0-9]{6})\.#', $existing_filename, $matches ) )
    {
      $date = intval( $matches[1] );
      if( is_null( $latest_existing_date ) || $date > $latest_existing_date )
      {
        $latest_existing_date = $date;
      }
    }
  }

  // delete the local files if they are not newer than existing files
  if( !is_null( $latest_existing_date ) && $latest_date <= $latest_existing_date )
  {
    if( VERBOSE ) output( sprintf(
      'Ignoring files in %s as there already exists more recent files',
      $study_dirname
    ) );
    if( !TEST_ONLY && !KEEP_FILES ) exec( sprintf( 'rm -rf %s', $study_dirname ) );
  }
  else
  {
    // otherwise remove any existing files
    if( !TEST_ONLY ) exec( sprintf( 'rm -rf %s/*', $destination_directory ) );

    // then copy the local files to their destinations (deleting them as we do)
    $success = true;
    foreach( $file_pair_list as $file_pair )
    {
      $copy = TEST_ONLY ? true : copy( $file_pair['source'], $file_pair['destination'] );
      if( $copy )
      {
        if( VERBOSE ) output( sprintf( '"%s" => "%s"', $file_pair['source'], $file_pair['destination'] ) );
        if( !TEST_ONLY && !KEEP_FILES ) unlink( $file_pair['source'] );
        $file_count++;
      }
      else
      {
        output( sprintf(
          'Failed to copy "%s" to "%s"',
          $file_pair['source'],
          $file_pair['destination']
        ) );
        $success = false;
      }
    }

    if( !TEST_ONLY && !KEEP_FILES )
    {
      if( $success )
      {
        // we can now delete the directory as all files were successfully moved
        remove_dir( $study_dirname );
      }
      else
      {
        // move the remaining files to the invalid directory
        move_from_temporary_to_invalid( $study_dirname ); 
      }
    }
  }
  $dir_count++;
}
output( sprintf(
  'Done, %d files %stransferred from %d directories',
  $file_count,
  TEST_ONLY ? 'would be ' : '',
  $dir_count
) );

exit( 0 );
