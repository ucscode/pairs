<?php 
/**
 * Meta Table Generator
 *
 * The pairs class creates a meta table for data references.
 * The table create may (or may not) implement foreign key that references another table.
 * The columns contained in the meta table includes:
 *
 * - id: unique id for a row
 * - _ref: A reference id (may also be null)
 * - _key: The key of the data
 * - _value: The value of the data
 * - epoch: The time at which the data was inserted
 *
 * @author ucscode <uche23mail@gmail.com>
 * @link http://github.com/ucscode
 * @version 1.1.3
 * @copyright Copyright (c) 2023
 * @package pairs
 */

class pairs {
	
	/**
	 * @var string $tablename
	 */
	private $tablename;


	/**
	 * @var string $mysqli
	 */
	private $mysqli;
	
	
	/**
	 * Constructor Method
	 *
	 * @param MYSQLI $mysqli
	 * @param string $tablename
	 * @throws Exception If sQuery class is not found
	 */
	public function __construct( MYSQLI $mysqli, string $tablename ) {
		
		// This class required sQuery to work;
		
		if( !class_exists('sQuery') ) throw new Exception( __CLASS__ . "::__construct() relies on class `sQuery` to operate" );
		
		$this->tablename = $tablename;
		$this->mysqli = $mysqli;
		
		// Create The Meta Table;
		
		$created = $this->createTable();
		
	}
	
	/**
	 * Create a meta table
	 *
	 * @return boolean
	 * 
	 */
	protected function createTable() {

		$SQL = "
			CREATE TABLE IF NOT EXISTS `{$this->tablename}` (
				`id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
				`_ref` INT,
				`_key` VARCHAR(255) NOT NULL,
				`_value` TEXT,
				`epoch` BIGINT NOT NULL DEFAULT UNIX_TIMESTAMP()
			);
		";
		
		return $this->mysqli->query( $SQL );
		
	}
	
	/*
		Link the meta table to it's parent table
	*/
	
	/**
	 * Link to parent table 
	 * 
	 * Applies a foreign key to the `_ref` column. 
	 * The foreign references the parent table.
	 * The `ON DELETE` action can be specified on the 3rd parameter
	 *
	 * @param string $parent_table The name of the parent table
	 * @param string $constraint The unique constaint of the foreign key
	 * @param string $primary_key The primary key (or reference column) of the parent table
	 * @param string $action The action to take on delete (CASCADE|RESTRICT|SET NULL)
	 * 
	 * @return boolean
	 * 
	 */
	public function linkParentTable( string $parent_table, string $constraint, string $primary_key = 'id', string $action = 'CASCADE' ) {
		
		$SQL = "
			IF NOT EXISTS (
				SELECT NULL 
				FROM information_schema.TABLE_CONSTRAINTS
				WHERE
					CONSTRAINT_SCHEMA = DATABASE() AND
					CONSTRAINT_NAME   = '{$constraint}' AND
					CONSTRAINT_TYPE   = 'FOREIGN KEY' AND
					TABLE_NAME = '{$this->tablename}'
			)
			THEN
				ALTER TABLE `{$this->tablename}`
				MODIFY `_ref` INT NOT NULL,
				ADD CONSTRAINT `{$constraint}`
				FOREIGN KEY (`_ref`)
				REFERENCES `{$parent_table}`(`{$primary_key}`)
				ON DELETE {$action};
			END IF
		";
		
		return $this->mysqli->query( $SQL );

	}
	
	/**
	 * Check comparism type
	 * 
	 * When executing SQL Query, linear values can be tested as `number = 3`, but `NULL` is tested as `number IS NULL`.
	 * Therefore, the method checks for `NULL` type and derive the appropriate test query
	 *
	 * @param int|null $ref
	 * 
	 * @return string 
	 * 
	 */
	private function test( ?int $ref = null ) {
		if( is_null($ref) ) $test = " IS " . sQuery::val( $ref );
		else $test = " = " . sQuery::val( $ref );
		return trim($test);
	}
	
	/**
	 * Add or update a reference data
	 * 
	 * This method adds a new reference data if it does not exists.
	 * Else, it updates the reference data
	 * 
	 * A reference data is considered unique if both the key and the reference id exists and does not match any other key/ref id on the table.
	 * 
	 * #### Example:
	 * - `_ref = NULL` &amp; `key = name` &mdash; unique
	 * - `_ref = 1` &amp; `key = name` &mdash; unique
	 * - `_ref = 2` &amp; `key = name` &mdash; unique
	 *
	 * Every data saved in the reference table are stored in json format.
	 * Therefore, it accepts a wide range of argument including array and objects.
	 * However, you should be aware that if an object is passed as an argument, it will be returned as an array when retreived.
	 * Also, it is not advisable to pass argument such as `function` to this method as it will never return the expected output
	 * 
	 * Note: Do not use `mysqli::real_escape_string` on the `$value` being passed to this method as it already contains one. Otherwise, it may return values containing unexpected multiple backslashes that are not required.
	 * 
	 * @param string $key The key of the reference data
	 * @param mixed $value The value of the reference data
	 * @param int|null $ref The id of the reference data
	 * 
	 * @return mixed
	 * 
	 */
	public function set(string $key, $value, ?int $ref = null) {
		
		$value = json_encode($value);
		$value = $this->mysqli->real_escape_string($value);
		
		$SQL = sQuery::select( $this->tablename, "_key = '{$key}' AND _ref " . $this->test( $ref ) );
		
		if( !$this->mysqli->query( $SQL )->num_rows ) {
			$method = "insert";
			$condition = null;
		} else {
			$method = "update";
			$condition = "_key = '{$key}' AND _ref " . $this->test($ref);
		};
		
		$Query = sQuery::{$method}( $this->tablename, array( 
			"_key" => $key, 
			"_value" => $value,
			"_ref" => $ref
		), $condition );
		
		$result = $this->mysqli->query( $Query );
		
		return $result;
		
	}
	
	/**
	 * Get the value of a reference data based on key &amp; reference id
	 * 
	 * If reference id is not given, it will default to `NULL`
	 * Reference ID is essential to distinguish data.
	 * 
	 * For example: If the pairs class is used to create a meta table for a set of registered users, then, the reference id can be used to indicate which user is associated to the inserted data.
	 *
	 * If parameter 3 is set to true, then it will return the timestamp (the unix time at which the data was inserted) rather than the value of the key provided
	 * 
	 * @param string $key The key of the reference data
	 * @param int|null $ref The id of reference data
	 * @param bool $epoch
	 * 
	 * @return mixed
	 * 
	 */
	public function get( string $key, ?int $ref = null, bool $epoch = false ) {
		
		$Query = sQuery::select( $this->tablename, "_key = '{$key}' AND _ref " . $this->test( $ref ) );
		
		$result = $this->mysqli->query( $Query )->fetch_assoc();
		
		if( $result ) {
			$value = json_decode($result[ $epoch ? 'epoch' : '_value' ], true);
			return $value;
		}
		
	}
	
	/**
	 * Remove | Delete a data
	 *
	 * If any data matches both the key and reference id, the data will be removed from the meta table
	 * 
	 * @param string $key
	 * @param int|null $ref
	 * 
	 * @return boolean
	 * 
	 */
	public function remove(string $key, ?int $ref = null) {
		
		$Query = "DELETE FROM `{$this->tablename}` WHERE _key = '{$key}' AND _ref " . $this->test( $ref );
		
		$result = $this->mysqli->query( $Query );
		
		return $result;
		
	}
	
	/**
	 * Return all the data that matches a reference id (or/and) a particular pattern
	 * 
	 * ##### Example:
	 * If the meta table is used to store additional detail of a set registered users, then you can get all the data associated to a particular user by passing only the reference id of the user.
	 * 
	 * You can also pass `regular expression` string as the second argument to get only values that matches a particular key. 
	 * 
	 * Note: Regular expression should not begin with a delimeter. By default, all expressions are case insensitive
	 * 
	 * ```php
	 * $pairs->get(1, "/^wallet[\w+]$/i"); // Wrong
	 * $pairs->get(1, "^wallet[\w+]$"); // Right
	 * ```
	 *
	 * @param int|null $ref The reference id
	 * @param string|null $regex A regular expression of matching keys
	 * 
	 * @return mixed
	 * 
	 */
	public function all(?int $ref = null, ?string $regex = null) {
		
		$data = array();
		
		if( empty($regex) ) $xpr = null;
		else {
			$regex = str_replace("\\", "\\\\", $regex);
			$xpr = " AND _key REGEXP '{$regex}'";
		};
		
		$Query = sQuery::select( $this->tablename, "_ref " . $this->test($ref) . $xpr );
		
		$result = $this->mysqli->query( $Query );
		
		if( $result->num_rows ) {
			while( $pair = $result->fetch_assoc() ) {
				$key = $pair['_key'];
				$value = json_decode($pair['_value'], true);
				$data[$key] = $value;
			}
		};
		
		return $data;

	}
	
}
