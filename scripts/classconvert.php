<?php
//
// Created on: <15-Jun-2007 ar@ez>
//
// SOFTWARE NAME: eZChangeclass
// SOFTWARE RELEASE: 1.0
// COPYRIGHT NOTICE: Copyright (C) 2007-2013 Bartek Modzelewski
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//


if( !file_exists( 'extension/ezchangeclass/scripts' ) || !is_dir( 'extension/ezchangeclass/scripts' ) )
{
    echo "Please run this script from the root document directory!\n";
    echo 'Current working directory is: ' . getcwd() . "\n";
    exit;
}

include_once( 'autoload.php' );

$cli = eZCLI::instance();

$script = eZScript::instance( array( 'description' => ( "\nThis script performs batch conversion of objects of a specific class!\n" .
                                                         "\nBefore running this you should use the gui part of this extension, witch will create a conversion file if you select:\n 'Generate parameters for converting all instances of this class'.\n"  ),
                                      'use-session' => false,
                                      'use-modules' => true,
                                      'use-extensions' => true ) );

$script->startup();

$options = $script->getOptions( "[db-host:][db-user:][db-password:][db-database:][db-type:][param-file:][sub-tree:]",
                                "",
                                array( 'db-host' => "Database host",
                                       'db-user' => "Database user",
                                       'db-password' => "Database password",
                                       'db-database' => "Database name",
                                       'db-type' => "Database type, e.g. mysql or postgresql",
                                       'param-file' => "Parameter filename",
                                       'sub-tree' => "Restrict by Subtree Node Id"
                                       ) );
$script->initialize();

$dbUser = $options['db-user'];
$dbPassword = $options['db-password'];
$dbHost = $options['db-host'];
$dbName = $options['db-database'];
$dbImpl = $options['db-type'];
$paramFile = $options['param-file'];
$subTree = (int) $options['sub-tree'];
$isQuiet = $script->isQuiet();

if ( $dbHost or $dbName or $dbUser or $dbImpl )
{
    $params = array( 'use_defaults' => false );
    if ( $dbHost !== false )
    {
        $params['server'] = $dbHost;
    }

    if ( $dbUser !== false )
    {
        $params['user'] = $dbUser;
        $params['password'] = '';
    }

    if ( $dbPassword !== false )
    {
        $params['password'] = $dbPassword;
    }

    if ( $dbName !== false )
    {
        $params['database'] = $dbName;
    }

    $db = eZDB::instance( $dbImpl, $params, true );
    eZDB::setInstance( $db );
}
else
{
    $db = eZDB::instance();
}

if ( !$db->isConnected() )
{
    $cli->notice( "Can't initialize database connection.\n" );
    $script->shutdown( 1 );
}

//$paramFile
$file_path = eZSys::cacheDirectory();
$file_name = $file_path . '/' . $paramFile;

if( !file_exists( $file_name ) )
{
    $cli->notice( "File $paramFile not found!" );
    $script->shutdown( 1 );
}


$handle       = fopen( $file_name, "r" );
$line         = 1;
$class_array  = 0;
$mapping      = array();

//Expected file format in $paramFile
//source_class_identifier:dest_class_identifier
//source_attribute_identifier_1:dest_attribute_identifier_1
//source_attribute_identifier_2:dest_attribute_identifier_2
//and so on

if ( $handle )
{
    while ( !feof( $handle ) )
    {
        $buffer = trim( fgets( $handle, 1024 ) );
        if ( $line === 1)
        {
            $class_array = explode(':', $buffer);
        }
        else
        {
            $temp = explode(':', $buffer);
            $mapping[$temp[1]] = $temp[0];
        }
        $line++;
    }
    fclose($handle);
}
else
{
    $cli->notice( "File $paramFile could not be opened!");
    $script->shutdown( 1 );
}


if ( !$class_array || !$mapping )
{
    $cli->notice( "Didn't find class identifiers or no attributes where found from $paramFile!" );
    $script->shutdown( 1 );
}

if (!$subTree) $subTree = 1;

//start feching objects of class: $class_array[0]
$offset = 0;
$limit = 100;
$line = 0;
$debug = array();

$nodeCount = eZContentObjectTreeNode::subTreeCountByNodeID( array( 'ClassFilterType' => 'include',
                                                           'ClassFilterArray' => array( $class_array[0] ),
                                                           'Limitation' => array(),
                                                           'MainNodeOnly' => true ),
                                                    $subTree );

if ( !$isQuiet )
{
    $cli->notice( 'Number of objects found: ' .$nodeCount );
}

do
{
	$nodeArray = eZFunctionHandler::execute( 'content', 'list', array(
																'parent_node_id' => $subTree,
																'depth' => 99,
																'limitation' => array(),
																'offset' => 0,
																'limit' => $limit,
																'ignore_visibility' => true,
																'class_filter_type' => 'include',
																'class_filter_array' => array( $class_array[0] )
															));

    if ( !$nodeArray ) {
	    break;
    }
    foreach ( $nodeArray as $node )
    {
        $temp = conversionFunctions::convertObject( $node->attribute('contentobject_id'), $class_array[1], $mapping );

        if ( !$temp )
        {
            $temp_string = 'Error: ObjectId ' . $node->attribute('contentobject_id') . ' with class ' . $class_array[1] . ' conversion returned false!';
            $cli->notice( $temp_string);
            $debug[] = $temp_string . "\n";
        }
        else
        {
            $line++;
	        $cli->output( "+", false );
            $debug[] = $node->attribute('name') . ',' . $node->attribute('node_id') . ',' . $node->attribute('contentobject_id') . "\n";
        }
    }
    if ( !$isQuiet && $nodeArray )
    {
        $cli->notice( $line . ' objects converted, ' . ($nodeCount - $line) . ' left.' );
	    //$cli->notice( "Memory usage:" . memory_get_usage() );
	    clearCache();
    }
    $offset += count( $nodeArray );

} while ( count( $nodeArray ) );


$ini = eZINI::instance( 'changeclass.ini' );
if ( $ini->variable( 'General', 'ScriptLog' ) == 'enabled' )
{
    $file_path = eZSys::cacheDirectory();
    $fp = fopen($file_path.'/logEzChangeClass.txt', "a+");
    if ( $fp )
    {
        foreach( $debug as $debug_line)
        {
            fputs($fp, $debug_line);
        } 
        fclose($fp);
    }
    else
    {
        $cli->notice( "Could not open log file for writing! \n"  . $file_path.'/logEzChangeClass.txt' );
        $script->shutdown(1);
    }
}

if ( !$isQuiet )
{
    $cli->notice( "Done. $line objects converted!" );
	//$cli->notice( "Max memory usage:" . memory_get_peak_usage() );

}

$script->shutdown();



function clearCache()
{
	eZContentObject::clearCache();
	unset( $GLOBALS['eZContentObjectContentObjectCache'] );
	unset( $GLOBALS['eZContentObjectDataMapCache'] );
	unset( $GLOBALS['eZContentObjectVersionCache'] );
	unset( $GLOBALS['eZContentClassAttributeCache'] );
}