<?php 

//============================================================+
// Norm - Not an ORM                 
//-------------------------------------
// Version          : 1.1
// Author           : Matthew Frederico          
// License          : Whichever GPL works best for you
//-------------------------------------
// Copyright (c) 2010 Matthew Frederico
// 
// NORM is free software: you can redistribute it and/or modify it
// under the terms of the GNU Lesser General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// NORM is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with NORM.  If not, see <http://www.gnu.org/licenses/>.
//
// See LICENSE.TXT file for more information.
// -------------------------------------------------------------------
// Description      : Dynamic storage/retrieval of objects in a database
// Main features    :
// * Single class file
// * Object properties can be dynamically allocated
// * You don't have to create the database tables
// * 3 Main functions - store,get,del.  Norm takes care of the rest
// * Automatic table creation 
/**
 * NORM is a PHP class for storing and retrieving PHP objects to and from a database
 * <ul><li>3 main public methods - store,get,del</li>
 * <li>Creates your database tables on the fly as needed</li>
 * <li>Does it's best to maintian hierarchy</li></ul>
 * @package ultrize.norm
 */

/**
 * @author Matthew Frederico
 * @link http://www.ultrize.com/norm/
 * @license http://www.gnu.org/copyleft/lesser.html LGPL
 * @version 1.1
 * @copyright Copyright 2010 Matthew Frederico - ultrize.com
 * @package Norm
 */
class Norm
{
	/**
	 * Single only returns a single object's data
	 * @var SINGLE how to return data 
	 */
	const SINGLE	= 0;
	const FULL		= 1;

	/**
	 * @var user The user name used to authenticate into the database (if applicable)
	 * @access protected
	 */
	protected $user = '';

	/**
	 * @var pass The password used to authenticate into the database (if applicable)
	 * @access protected
	 */
	protected $pass = '';

	/**
	 * @var dsna This is the DSN string e.g. mysql:host=localhost;dbname=database
	 * @access protected
	 */
	protected $dsna = '';

	/**
	 * @var tableList This is the internal table list pointer for NORM
	 * @access protected
	 */
	protected $tableList		= array();

	/**
	 * @var tableSchema This is the internal table schema for NORM
	 * @access protected
	 */
	protected $tableSchema	= array();

	/**
	 * @var relatedTables Keeps a hierchical relationship of tables / objects
	 * @access protected
	 */
	protected $relatedTables	= array();

	/**
	 * @var maps creates the mapping for all created objects
	 * @access protected
	 */
	protected $maps			= array();

	/**
	 * @var link This is the actual PDO link to the database
	 * @access protected
	 */
	private static $link = null;
	

	/**
 	 * @param string $dsn the database dsn connection
	 * @param string $user username to connect to the database
	 * @param string $pass password to connect to the database
	 * @param string $attr extra PDO attributes passable
	 * @returns object 
	 * @access public 
	 */
	public function __construct($dsn,$user = null,$pass = null,$attr = null) 
	{
		$this->dsna = self::parseDsn($dsn);

		$this->user = $user;
		$this->pass = $pass;

        if ( self :: $link ) {
            return self;
        }

        self :: $link = new PDO ( $dsn, $user, $pass, $attr ) ;

        return $this;
    }

	/**
	 * Think: my $iceCream_obj1 has "N" $flavors_obj2
     * for now, just references tieMany
	 * @param object $obj1 this is the parent object
	 * @param object $obj2 this is the object that the parent will 
	 * @access public
	 * @returns object 
	 * @see tieMany() store()
	 */
	public function tie($obj1,$obj2,$opt='')
	{
		return($this->tieMany($obj1,$obj2,$opt));

		// This fails with current implementation .. 
		// Default behavior to tie many if we have an array of objects
		/*
		if (is_array($obj2))  return($this->tieMany($obj1,$obj2,$opt));
		
		$t1	= get_class($obj1);
		$t2	= get_class($obj2);

		$this->store($obj2);
		$var = "{$t2}_id";
		$obj1->$var = $obj2->id;

		$this->store($obj1);

		if (!strlen($opt)) $opt = "{$t1}.id={$t2}.id";

		return($this);
		*/
	}


	/**
	 * Ties an array of objects together
	 * @param object $obj1 this is the parent object
	 * @param array $objArrays this is the array of objects 
	 * @access public
	 * @returns object 
	 * @see tie()
	 */
	public function tieMany($obj1,$objArrays = array(),$opt='')
	{
		// Make sure we have an array of objects
		if (!is_array($objArrays)) 
		{
			$nobj[] = $objArrays;
			$objArrays = $nobj;
		}

		$t1	= self::getClass($obj1);

		// Build the lookup table
		foreach($objArrays as $idx=>$nextObj)
		{
			$t2 = self::getClass($nextObj);

			$tableName = "{$t1}_{$t2}";

			$var1 = "{$t1}_id";
			$var2 = "{$t2}_id";

			$table[$var1] = 0;
			$table[$var2] = 0;

			// Create the structure of the lookup table
			$Q = $this->buildSet($tableName,$table);
			if (strlen($Q))
			{
				$data = self::$link->prepare($Q);
				$data->execute();

				// Create unique index for this lookup
				$Q = "ALTER TABLE {$tableName} ADD unique index({$tableName}_{$var1},{$tableName}_{$var2})";
				$data = self::$link->prepare($Q);
				$data->execute();
			}
		}

		// Now store the data into the lookup table for each object
		foreach($objArrays as $obj2)
		{
			$tmp = $this->store($obj2);
			if (isset($obj1->id) && isset($obj2->id))
			{
				$Q="INSERT INTO {$tableName} SET `{$tableName}_{$var1}`='{$obj1->id}', `{$tableName}_{$var2}`='{$obj2->id}'";
				$data = self::$link->prepare($Q);
				$data->execute();
			}
			else 
			{
				trigger_error('Trying to tie objects that have no id '.self::getClass($obj1).' -> '.self::getClass($obj2).' - cannot save to database!',E_USER_NOTICE);
			}
		}
	}

	/**
	 * pushes array key=>value pairs of data into my object, 
	 * creating new object vars (e.g. $obj->key = value) where necessary.
     * great for stuffing objects with data from form fields:<Br />
     * <code>Norm::stuff($_REQUEST['user'],$user,'id,login,password');</code>
	 * @param array $array array containing key=>value pairs
	 * @param array $obj object to "stuff" into
	 * @param string $fields a csv of fieldnames to "stuff" into the object
	 * @access public
	 * @returns object 
	 * @see store() tie()
	 */
	public function stuff($array,$obj,$fields = '')
	{
		// Get this objects name
		$n = self::getClass($obj);
		// convert me to an array!	
		$fields = explode(',',$fields);
		if (!empty($array))
		{
			foreach($array as $k=>$v)
			{
				$k = str_replace($n.'_','',$k);
				$obj->$k = $v;
			}
		}
		return($this);
	}

	/**
	 * Deletes an object hierarchy from the database - Norm does it's best to 
	 * delete all references to this object as well.
	 * @param object $obj This is the object to delete.  
	 * @access public
	 * @returns object
	 * @see get()
	 */
	public function del($obj)
	{
		//go through each populated obj var and peform multi where clauses against it
		$tableName	= self::getClass($obj);
		$objVars	= get_object_vars($obj);

		$ts		= $this->getTableSchema($tableName);

		// Delete all my relationships
		$this->getRelatedTables($tableName);
		if (!empty($this->relatedTables[$tableName]))
		{
			foreach(array_keys($this->relatedTables[$tableName]) as $joinTable)
			{
				$Q = "DELETE FROM {$joinTable}_{$tableName} WHERE {$joinTable}_{$tableName}_{$tableName}_id='{$obj->id}'";
				$data = self::$link->prepare($Q);
				$data->execute();
			}
		}
		$Q="DELETE FROM {$tableName} WHERE {$tableName}_id='{$obj->id}'";
		$data = self::$link->prepare($Q);
		$data->execute();
		return($this);
	}

	/**
	 * Returns an object hierarchy from the database - Norm does it's best to 
	 * return all references to this object as well.
	 * @param object $fromObj This is the main object to return
	 * @param string $cols CSV of column names - in the format "classname_column1,classname_column2 .. "
	 * @param array $whereObjs array of objects to apply to WHERE clause.  Norm will use any fields that are populated in these objects as part of the where clause.  E.g.: <br/><code>$user->id=1<br/>$auth->level=1<br/>Norm::get($user,'user_name,user_pass',$auth);</code>
	 * @param bool $getSet Whether or not to return the ENTIRE hierarchical structure
	 * @access public
	 * @returns array
     * @see del() reduceTables() condense()
	 */
	public function get($fromObj,$cols = '*',$whereObjs = array(),$getSet = 1)
	{
		$cols=strtolower($cols);
		$getCols = explode(',',$cols);
		
		$tableName	= self::getClass($fromObj);
		$objVars	= get_object_vars($fromObj);

		$Q="SELECT ".join(',',$getCols)." FROM {$tableName}";

		if ($getSet)
		{
			$joins = $this->reduceTables($tableName);
			if (!empty($joins))
			{
				$joins = array_reverse($joins);
				foreach($joins as $joinTable=>$qrys)
				{
					foreach($qrys as $qry) 
					{
						$Q .= " INNER JOIN {$qry['table']}_{$qry['mapTo']} ON ({$qry['table']}_{$qry['mapTo']}_{$qry['table']}_id={$qry['table']}_id) INNER JOIN {$qry['mapTo']} ON ({$qry['mapTo']}_id={$qry['table']}_{$qry['mapTo']}_{$qry['mapTo']}_id) ";
					}
				}
			}
		}
	
		// This develops our WHERE clause
		if (!empty($objVars)) foreach($objVars as $k=>$v) 
			if (!empty($v))
			{
				if (strlen($WHERE)) $WHERE .= " AND ";
				$WHERE .= "{$tableName}_{$k}='{$v}' ";
			}

		if (is_array($whereObjs)) foreach($whereObjs as $whereObj) $whereVars[self::getClass($whereObj)] = get_object_vars($whereObj);
		// This builds any extra AND clauses
		if (!empty($whereVars)) foreach($whereVars as $k=>$v) 
		{
			foreach($v as $kn=>$vl) 
			{
				if (!empty($vl)) $AND .= "AND {$k}_{$kn}='{$vl}' ";
			}
		}

		// Put it all together
		$Q .= " WHERE {$WHERE}";
		if (empty($WHERE)) $Q .= '1';
		$Q .= " {$AND}";

		$data = self::$link->prepare($Q);
		$data->execute();

		$data->setFetchMode(PDO::FETCH_ASSOC);

		return(self::condense($data->fetchAll(),$tableName));	
	}

	/**
	 * Stores the data objects and any relationships into the database use it for both inserts and updates.  Norm will decide.
	 * @param object $obj this is the object with any arrays of objects connected to it
	 * @param bool $ignoreNull if a field is null, don't assign it's value to the database when updating
	 * @access public
	 * @returns object
     * @see get() del() tie()
	 */
	public function store($obj,$ignoreNull = 1)
	{
		$tableName	= self::getClass($obj);
		if (!strlen($tableName)) 
		{
			trigger_error('Cannot store object without name!',E_USER_NOTICE);
			return(false);
		}
		$objVars	= get_object_vars($obj);
		$tieThese	= array();

		if (!empty($objVars))
		{
			foreach($objVars as $k=>$val)
			{
				if ($ignoreNull && $val == null) { unset($objVars[$k]); continue; }
				// perhaps change this to mean 1:1?
				// Allows me to store just direct object
				if (is_object($objVars[$k]))
				{
					$this->store($obj->$k);
					$tieThese[] = array($obj,$obj->$k);
					unset($objVars[$k]);
					unset($obj->$k);
				}
				// perhaps change this to mean 1:many?
				// Allows me to store an array of objects
				else if (is_array($objVars[$k]))
				{
					foreach($objVars[$k] as $storeMe)
					{
						$tieThese[] = array($obj,$storeMe);
					}
					unset($objVars[$k]);
					unset($obj->$k);
				}
			}
		}

		// Auto calibrate the database
		$set = self::buildSet($tableName,$objVars);
		if (strlen($set))
		{
			$schema = self::$link->prepare($set);
			$schema->execute();
		}

		if (isset($obj->id))
			$Q="UPDATE `{$tableName}` SET";
		else
			$Q="INSERT INTO `{$tableName}` SET";

		if (!empty($objVars))
		{
			foreach($objVars as $k=>$v)
			{
				$Q.=" `{$tableName}_{$k}`='{$v}',";
			}
			$Q = rtrim($Q,',');
		}

		$storage = self::$link->prepare($Q);
		$storage->execute();

		if (!isset($obj->id))
		{
			$lid = self::$link->lastInsertId();
			if ($lid) $obj->id = $lid;
		}

		if (!empty($tieThese)) foreach ($tieThese as $objs) 
		{
			if (is_object($objs[0]) && is_object($objs[1]))
				$this->tie($objs[0],$objs[1]);
		}
		if (!empty($objArrays)) $this->tie($obj,$objArrays);

		return($this);
	}

	/**
	 * Returns the class name of the object - lowercase.  <em>(windows compatability)</em>
	 * @param object $obj 
	 * @access private
	 * @returns string
	 */
	private function getClass($obj)
	{
		return(strtolower(get_class($obj)));
	}

	/**
	 * Condenses the results from the database into a usable assoc array (tried to do in a non-recursive way)
	 * @param array $dataset This is the returned data from PDO fetchAll()
	 * @param string $tableName the "root" table name.  The first assoc name of the array
	 * @param bool $reindex (true) reindex the array at 0 (false) keep the indexes as the database id column of each object
	 * @access private
	 * @returns array
     * @see get() 
	 */
	//  If I don't reindex, then it will keep id as the index of the array
	private function condense($dataset,$tableName,$reindex = 1)
	{
		if (empty($dataset)) 
		{
			trigger_error('Results Empty',E_USER_NOTICE);
			return(null);
		}
		// Build my dataset KVP
		foreach($dataset as $idx=>$data)
		{
			foreach($data as $k=>$values)
			{
				$pointers	= explode('_',$k);
				$valVar		= $pointers[count($pointers)-1];
				$keyVar		= $pointers[count($pointers)-2];
				$attrs[$keyVar][$idx][$valVar] = $values;

				if (count($pointers) == 4) 
				{
					if ($pointers[0] == $pointers[2]) $lastId = $values;
					if ($pointers[0] != $pointers[2]) $map[$pointers[0]][$lastId][$pointers[2]][$values] = $pointers[3];
				}
			}
		}

		// Now condense down to array of tables / objects
		foreach($attrs as $table=>$array)
		{
			foreach($array as $idx=>$v)
				foreach($v as $col=>$val)
				{
					$final[$table][$v['id']][$col] = $val;
				}
		}

		// put array together based on mapping
		if (!empty($map))
		{
			// This allows us to condense from the greatest to the least
			$map = array_reverse($map);
			foreach($map as $root=>$array)
			{
				foreach($array as $rootIdx=>$dataField)
				{
					$newFinal[$root][$rootIdx] = array();
					foreach($dataField as $key=>$id)
					{
						foreach($id as $idx=>$colname)
						{
							if ($reindex) $final[$root][$rootIdx][$key][] = $final[$key][$idx];
							else $final[$root][$rootIdx][$key][$idx] = $final[$key][$idx];
						}
					}
				}
			}
			foreach(array_keys($final) as $k) if ($k != $tableName) unset($final[$k]);
		}

		return($final);
	}


	/**
	 * parses the DSN string into usable parts
	 * @param string $dsn the DSN string for database connection
	 * @returns array
	 * @access private
	 */
	private function parseDsn($dsn)
	{
		list($dbType,$str) = explode(':',$dsn);
		$dsnParts = explode(';',$str);
		foreach($dsnParts as $p)
		{
			list($k,$v) = explode('=',$p);
			$dsna[$k] = $v;
		}
		return($dsna);
	}

	/**
	 * Maps the database tables into a hierarchy
	 * @access private
	 * @returns array or false
	 */
	private function getMaps()
	{
		if (!empty($this->maps)) return($this->maps);
		$tableList = $this->getTableList();
		if (!empty($tableList))
		{
			foreach($tableList as $tbl)
			{
				@list($main,$has) = explode('_',$tbl);
				if (!empty($has))
				{
					$this->maps[$main][] = $has;
				}
			}
			return($this->maps);
		}
		return(false);
	}

	/**
	 * Reduces the table structure of an object into its mapping
	 * @param object $obj the object to reduce
	 * @returns array or false
	 * @access private
	 */
	private function reduceTables($obj)
	{
		if (is_object($obj)) $table = self::getClass($table);
		else $table = $obj;
		$this->getMaps();

		if (!empty($this->maps[$table]))
		{
			foreach($this->maps[$table] as $idx=>$mapToTable)
			{
				if (isset($this->maps[$mapToTable])) $Q = $this->reduceTables($mapToTable);
				$Q[$table][] = array('mapTo'=>$mapToTable,'table'=>"{$table}");
			}
			return($Q);
		}
		return(false);
	}

	/**
	 * Finds any of the tables that are associated with a particular table
	 * @param string $table the name of the table to get associations
	 * @returns array or null
	 * @access private
	 */
	private function getRelatedTables($table)
	{
		$related	= array();
		$i			= 0;
		$tableList	= $this->getTableList();

		if (!empty($tableList))
		{
			// get initial lookp tables
			foreach($tableList as $idx=>$tbl)
			{
				@list($thisObj,$has) = explode('_',$tbl);
				if (in_array($has,$this->getTablelist())) $related[$has][$thisObj] = 1;
			}
		}
		$this->relatedTables = $related;
		
		if (!empty($this->relatedTables[$table])) return($this->relatedTables[$table]);
		else return(null);
	}

	/**
	 * Gets a list of tables from this database connection
	 * @returns array
	 * @access private
	 */
	private function getTableList()
	{
		if (empty($this->tableList))
		{
			$Q="SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$this->dsna['dbname']}'"; 
			$dbSchema = self::$link->prepare($Q);
			$dbSchema->execute();

			$ts = $dbSchema->fetchAll(PDO::FETCH_COLUMN);
			if (!count($ts)) $ts = false;
			$this->tableList  = $ts;
		}
		//asort($this->tableList);
		return($this->tableList);
	}
	
	/**
	 * Gets a the schema for a particular table
	 * @param string $tableName the name of the table to get the schema for
	 * @returns array
	 * @access private
	 */
	private function getTableSchema($tableName)
	{
		if (!strlen($tableName)) return(false);
		if (empty($this->tableSchema[$tableName]))
		{
			$Q="SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME='{$tableName}' AND TABLE_SCHEMA='{$this->dsna['dbname']}'"; 
			$dbSchema = self::$link->prepare($Q);
			$dbSchema->execute();

			$ts = $dbSchema->fetchAll(PDO::FETCH_COLUMN);
			if (!count($ts)) $ts = false;

			$this->tableSchema[$tableName] = $ts;
		}
		return($this->tableSchema[$tableName]);
	}

	/**
	 * Compare 2 schemas to see what the diff is (for automatic colum creation) additive only
	 * @param array $schema1 the first  schema from to compare
	 * @param array $schema2 the second schema from to compare
	 * @access private
	 * @returns array
     * @see getTableSchema()
	 */
	private function compareSchemas($schema1,$schema2,$ignore = array())
	{
		$schema1 = array_flip($schema1);
		$schema2 = array_flip($schema2);

		foreach($ignore as $k=>$v)
		{
			unset($schema1[$v]);
			unset($schema2[$v]);
		}

		$schema1 = array_keys($schema1);
		$schema2 = array_keys($schema2);

		return(array_diff($schema1,$schema2));
	}

	/**
	 * figure out if a field is a datetime field by returning a valid unix timestamp
	 * @param string $dt a string containing a date or a time parsable by php
	 * @returns int 
	 * @access private
	 */
	private function is_datetime($dt)
	{
		return (strtotime($dt));
	}

	/**
	 * Figures out the data type to store in the database for table creation / alteration
	 * @param string $table the name of the table
     * @param string $col name of the column
	 * @access private
	 * @returns string
	 * @see buildSet()
	 */
	private function buildType($table,$col,$v)
	{
		$Q = '';
		if (is_int($v)) 								$Q .= "`{$table}_{$col}` int not null,";
		else if (is_float($v)) 							$Q .= "`{$table}_{$col}` float not null,";
		//else if (self::is_datetime($v)) 				$Q .= "`{$table}_{$col}` timestamp not null,";
		else if (is_string($v) && strlen($v) <= 255)	$Q .= "`{$table}_{$col}` varchar(255) not null,";
		else if (is_string($v) && strlen($v) > 255)		$Q .= "`{$table}_{$col}` text default(''),";
		else if (is_object($v)) 						
		{
			$tableName	= self::getClass($v);
														$Q .= "`{$tableName}_{$col}` int unsigned not null,";
		}
		else $Q .= "`{$table}_{$col}` varchar(255) not null,"; // Kinda generic type / catch all.

		return($Q);
	}

	/**
	 * actually builds the ALTER TABLE and CREATE TABLE for the database.
	 * @param string $tableName the name of the table to create
     * @param object $objVars the object containing variables to create
	 * @access private
	 * @returns string
	 * @see getTableSchema() compareSchemas() buildType()
	 */
	private function buildSet($tableName,$objVars)
	{
		if (!strlen($tableName)) return(false);

		$Q = null;
		$dbSchema = $this->getTableSchema($tableName);

		// check if we need to alter tables
		if (!empty($dbSchema))
		{
			$v = array_keys($objVars);

			// Get my last found column from db schema
			$lastCol = $dbSchema[count($dbSchema)-2];

			$diff = $this->compareSchemas($v,$dbSchema,array($tableName.'_id',$tableName.'_updated'));
			foreach($diff as $x=>$k)
			{
				$Q="ALTER TABLE `{$tableName}` ADD ".$this->buildType($tableName,$k,$objVars[$k]);
				$Q = rtrim($Q,',');
				if (strlen($lastCol)) $Q.=" AFTER `{$lastCol}`";
			}
		}
		// Do we need to create new data table?
		else
		{
			$Q="CREATE TABLE IF NOT EXISTS `{$tableName}` (`{$tableName}_id` int(11) unsigned not null primary key auto_increment,";
			foreach($objVars as $k=>$v)
			{
				$Q .= $this->buildType($tableName,$k,$v);
			}
			$Q .= $tableName.'_updated timestamp not null default now())';
		}
		return($Q);
	}
}

?>