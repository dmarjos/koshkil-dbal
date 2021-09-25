<?php 
namespace Koshkil\DBAL;

use Koshkil\DBAL\Exceptions\QueryBuilderException;

class QueryBuilder {

	private $_parentModel=null;
	private $db;
	private $_compiled=false;
	private $_distinct=false;
	private $_fields="";
	private $_table="";
	private $_joins=array();
	private $_joinedTables="";
	private $_where=array();
	private $_or_where=array();
	private $_having=array();
	private $_or_having=array();
	private $_orderBy="";
	private $_groupBy="";
	private $_pageSize=20;
	private $_offset=-1;
	private $_limit=-1;
	private $_className = null;
	private $_calcFoundRows=true;
	private $_triggerEvents=true;
	public $compiledSQL="";
	public $totalRecords=0;
	public $affectedRecords=0;

	private $debug=false;

	private $_collectionClass="TCollection";

	public function __construct($parentModel=null) {
		$this->_parentModel=$parentModel;
	}

	public function getQuery() {
		return $compiledSQL;
	}
	public function clear() {
		$this->_compiled=false;
		$this->_distinct=false;
		$this->_fields=array();
		$this->_table="";
		$this->_joinedTables="";
		$this->_debug=false;
		$this->_joins=array();
		$this->_where=array();
		$this->_having=array();
		$this->_or_where=array();
		$this->_or_having=array();
		$this->_groupBy="";
		$this->_orderBy="";
		$this->_offset=-1;
		$this->_limit=-1;
		$this->_instance=null;
		$this->_minRecords=-1;
		$this->totalRecords=0;
		$this->affectedRecords=0;
		$this->_triggerEvents=true;
		return $this;
	}

	public function hasEventsEnabled() {
		return $this->_triggerEvents;
	}
	public function noEvents($disabled=true,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;
		$this->_triggerEvents=!$disabled;
		return $this;
	}
	public function minimumRecords($records,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;
		$this->_minRecords=intVal($records);
		return $this;
	}

	public function pageSize($pageSize,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;
		$this->_pageSize=$pageSize;
		return $this;
	}


	public function page($pageNumber){
		$this->offset(($pageNumber-1)*$this->_pageSize)
			->take($this->_pageSize);
		return $this;
	}

	public function truncate() {
		Application::$db->execute("truncate {$this->_table}");
		return $this;
	}
	public function noCalcRows() {
		$this->_calcFoundRows=false;
		return $this;
	}
	public function select($fields,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (!is_array($this->_fields) && !empty($this->_fields)) {
			$this->_fields=array($this->_fields);
		}

		if (is_array($fields)) {
			$_fields=array();
			if (count($fields)==2 && is_object($fields[0]) && is_string($fields[1])) {
				if (is_a($fields[0],"TQueryBuilder")) {
					$fields[0]->noCalcRows();
					$sql=$fields[0]->compile();
					$_fields[]="({$sql}) as {$fields[1]}";
				}
			} else {
				foreach($fields as $field) {
					if (is_array($field) && count($field)==2 && is_object($field[0]) && is_string($field[1])) {
						if (!is_a($field[0],"TQueryBuilder")) continue;
						$field[0]->noCalcRows();
						$sql=$field[0]->compile();
						$_fields[]="({$sql}) as {$field[1]}";
					} else $_fields[]=$field;
				}
			}

			$this->_fields=array_merge($this->_fields, $_fields);
		} else
			$this->_fields[]=$fields;
		return $this;
	}

	public function distinct($className = null) {
		if (is_string ( $className ) && class_exists ( $className, false ))
			$this->_className = $className;

		$this->_distinct = true;
		return $this;

	}

	public function withCollection($collectionClass,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;
		if (is_string($collectionClass) && class_exists($collectionClass,false))
			$this->_collectionClass=$collectionClass;
		return $this;
	}

	public function alias($tableAlias,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (is_array($this->_table) && !empty($this->_table)) {
			$this->_table[0].=" as {$tableAlias}";
		} else {
			$this->_table.=" as {$tableAlias}";
		}
		return $this;
	}

	public function tableName() {
		if (is_array($this->_table) && !empty($this->_table)) {
			return $this->_table[0];
		} else {
			return $this->_table;
		}
	}
	public function from($table,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (!is_array($this->_table) && !empty($this->_table)) {
			$this->_table=array($this->_table,$table);
		} else if(is_array($this->_table))
			$this->_table[]=$table;
		else
			$this->_table=$table;
		return $this;
	}

	public function order() {
		$parameters=func_num_args();

		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}
		switch($parameters) {
			case 2:
				$orderBy=func_get_arg(0)." ".func_get_arg(1)."";
				break;
			case 1:
				$orderBy=func_get_arg(0);
				break;
			default:
				throw new QueryBuilderException("ORDER malformed. (".serialize($parameters).")");
		}
		if (!is_array($this->_orderBy) && !empty($this->_orderBy)) {
			$this->_orderBy=array($this->_orderBy,$orderBy);
		} else if(is_array($this->_orderBy))
			$this->_orderBy[]=$orderBy;
		else
			$this->_orderBy=$orderBy;
		return $this;
	}

	public function group($groupBy,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (!is_array($this->_groupBy) && !empty($this->_groupBy)) {
			$this->_groupBy=array($this->_groupBy,$groupBy);
		} else if(is_array($this->_groupBy))
			$this->_groupBy[]=$groupBy;
		else
			$this->_groupBy=$groupBy;
		return $this;
	}

	public function orWhere() {
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}
		$_wheres=array();
		for($idx=0; $idx<$parameters; $idx++) {
			if (is_array(func_get_arg($idx))) {
				$conditions=func_get_arg($idx);
				foreach($conditions as $condition) {
					if (is_array($condition)) {
						switch(count($condition)) {
							case 3:
								if (is_array($condition[2]))
									$where=$condition[0]." ".$condition[1]." ('".implode("', '",$condition[2])."')";
								else
									$where=$condition[0]." ".$condition[1]." '".$condition[2]."'";
								break;
							case 2:
								if (is_array($condition[1]))
									$where=$condition[0]." in ('".implode("', '",$condition[1])."')";
								else
									$where=$condition[0]."='".$condition[1]."'";
								break;
							case 1:
								$where=$condition[0];
								break;
							default:
								throw new QueryBuilderException("Where condition malformed");
						}
						$_wheres[]=$where;
					} else {
						$_wheres[]=$condition;
					}
				}
			} else
				$_wheres[]=func_get_arg($idx);
		}
		return $this->where("(".implode(" OR ",$_wheres).")");
	}

	public function rawWhere() {
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}

		switch($parameters) {
			case 3:
				$where=func_get_arg(0)." ".func_get_arg(1)." ".func_get_arg(2)."";
				break;
			case 1:
				$where=func_get_arg(0);
				break;
			default:
				throw new QueryBuilderException("Where condition malformed");
		}
		if (is_array($where))
			$this->_where=array_merge($this->_where,$where);
		else
			$this->_where[]=$where;
		return $this;
	}

	public function whereNull($field,$className=null) {
		if (is_string($className) && class_exists($className,false)){
			$this->_className=$className;
		}

		if (is_array($field)) {
			$where=array();
			foreach($field as $fld) {
				$where[]=$fld." IS NULL";
			}
		}  else {
			$where="{$field} IS NULL";
		}

		if (is_array($where))
			$this->_where=array_merge($this->_where,$where);
		else
			$this->_where[]=$where;

		return $this;
	}

	public function whereEncoding() {
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}
		switch($parameters) {
			case 3:
				if (is_array(func_get_arg(2)))
					$where=func_get_arg(0)." ".func_get_arg(1)." ('".implode("', '",func_get_arg(2))."')";
				else
					$where=func_get_arg(0)." ".func_get_arg(1)." '".$this->safeEncoding(func_get_arg(2))."'";
				break;
			case 2:
				$where=func_get_arg(0)."='".$this->safeEncoding(func_get_arg(1))."'";
				break;
			case 1:
				$where=func_get_arg(0);
				break;
			default:
				throw new QueryBuilderException("Where condition malformed");
		}

		if (is_array($where))
			$this->_where=array_merge($this->_where,$where);
		else
			$this->_where[]=$where;
		return $this;
	}
	public function where() {
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}

		switch($parameters) {
			case 3:
				if (is_array(func_get_arg(2)))
					$where=func_get_arg(0)." ".func_get_arg(1)." ('".implode("', '",func_get_arg(2))."')";
				else
					$where=func_get_arg(0)." ".func_get_arg(1)." '".func_get_arg(2)."'";
				break;
			case 2:
				$where=func_get_arg(0)."='".func_get_arg(1)."'";
				break;
			case 1:
				$where=func_get_arg(0);
				break;
			default:
				throw new QueryBuilderException("Where condition malformed");
		}
		if (is_array($where))
			$this->_where=array_merge($this->_where,$where);
		else
			$this->_where[]=$where;
		return $this;
	}

	public function having() {
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}

		switch($parameters) {
			case 3:
				if (is_array(func_get_arg(2)))
					$having=func_get_arg(0)." ".func_get_arg(1)." ('".implode("', '",func_get_arg(2))."')";
				else
					$having=func_get_arg(0)." ".func_get_arg(1)." '".func_get_arg(2)."'";
				break;
			case 2:
				$having=func_get_arg(0)."='".func_get_arg(1)."'";
				break;
			case 1:
				$having=func_get_arg(0);
				break;
			default:
				throw new QueryBuilderException("Having condition malformed");
		}
		if (is_array($having))
			$this->_having=array_merge($this->_having,$having);
		else
			$this->_having[]=$having;
		return $this;
	}

	public function orHaving() {
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}

		switch($parameters) {
			case 3:
				if (is_array(func_get_arg(2)))
					$having=func_get_arg(0)." ".func_get_arg(1)." ('".implode("', '",func_get_arg(2))."')";
				else
					$having=func_get_arg(0)." ".func_get_arg(1)." '".func_get_arg(2)."'";
				break;
			case 2:
				$having=func_get_arg(0)."='".func_get_arg(1)."'";
				break;
			case 1:
				$having=func_get_arg(0);
				break;
			default:
				throw new QueryBuilderException("Having condition malformed");
		}
		if (is_array($where))
			$this->_or_having=array_merge($this->_or_having,$having);
		else
			$this->_or_having[]=$having;
		return $this;
	}

	public function offset($offset,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		$this->_offset=$offset;
		return $this;
	}

	public function take($limit,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		$this->_limit=$limit;
		return $this;
	}

	public function if($condition,$true,$false,$alias,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		$field="IF({$condition},'{$true}','{$false}') as {$alias}";

		return $this->select($field);
	}

	public function join() {
		//$joinedTable,$joinCondition,$type="inner",$className=null
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}

		switch ($parameters) {
			case 3:
				$type=func_get_arg(2);
				$joinCondition=func_get_arg(1);
				$joinedTable=func_get_arg(0);
				break;
			case 2:
				$type="inner";
				$joinCondition=func_get_arg(1);
				$joinedTable=func_get_arg(0);
				break;
		}

		if (is_object($joinedTable)) {
			if (is_a($joinedTable,"TQueryBuilder"))
				$tableName=$joinedTable->tableName(true);
			else {
				throw new QueryBuilderException("Join must be a table name or a QueryBulder object");
			}
		} else {
			$tableName=$joinedTable;
		}
		$this->_joins[]=array("table"=>$tableName,"on"=>"1","condition"=>$joinCondition,"type"=>$type);
		return $this;
	}

	public function joinUsing() {
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}

		switch ($parameters) {
			case 3:
				$type=func_get_arg(2);
				$joinCondition=func_get_arg(1);
				$joinedTable=func_get_arg(0);
				break;
			case 2:
				$type="inner";
				$joinCondition=func_get_arg(1);
				$joinedTable=func_get_arg(0);
				break;
		}

		if (is_object($joinedTable)) {
			if (is_a($joinedTable,"TQueryBuilder"))
				$tableName=$joinedTable->tableName(true);
			else {
				throw new QueryBuilderException("Join must be a table name or a QueryBulder object");
			}
		} else {
			$tableName=$joinedTable;
		}
		$this->_joins[]=array("table"=>$tableName,"using"=>"1","condition"=>$joinCondition,"type"=>$type);
		return $this;
	}

	public function compile($className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (is_array($this->_fields))
			$this->_fields=implode(",",$this->_fields);

		if (empty($this->_fields)) $this->_fields="*";

		if (is_array($this->_table))
			$this->_table=implode(",",$this->_table);

		if (is_array($this->_orderBy)) {
			$__o=array();
			foreach($this->_orderBy as $order) {
				if (substr($order,0,2)!="r ") $__o[]=$order;
			}
			$this->_orderBy=implode(",",$__o);
		}

		if (is_array($this->_groupBy))
			$this->_groupBy=implode(",",$this->_groupBy);

		if (is_array($this->_having))
			$this->_having=implode(" AND ",$this->_having);

		$this->_joinedTables="";
		if (!empty($this->_joins)) {
			foreach($this->_joins as $join) {
				$joinStmt=strtoupper($join["type"])." JOIN ".$join["table"]." ".($join["on"]?"ON":"USING")." (".$join["condition"].")";
				$this->_joinedTables.=" ".$joinStmt;
			}
		}

		$this->_compiled = true;
		$sql = Application::$db->getSQL ( array (
				"calc_found_rows"=>$this->_calcFoundRows,
				"debug" => $this->debug,
				"table" => $this->_table . $this->_joinedTables,
				"distinct" => $this->_distinct,
				"fields" => $this->_fields,
				"grouping" => $this->_groupBy,
				"having" => $this->_having
		), $this->_where, $this->_orderBy, $this->_offset, $this->_limit );
		return $sql;
	}

	public function debug($enable,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		$this->_debug=$enable;
		return $this;
	}

	public function get($className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (!$this->_compiled)
			$sql=$this->compile();

		if (Application::get('DEBUG_LEVEL')>=6) KoshkilLog::debug("[SQL DEBUG]:{$sql}");
		$records = Application::$db->getRecords ( array (
				"calc_found_rows"=>$this->_calcFoundRows,
				"distinct" => $this->_distinct,
				"fields" => $this->_fields,
				"table" => $this->_table . $this->_joinedTables,
				"grouping" => $this->_groupBy,
				"having" => $this->_having
		), $this->_where, $this->_orderBy, $this->_offset, $this->_limit );

		$this->affectedRecords=count($records["data"]);
		$this->totalRecords=intval($records["records"]);
		$this->compiledSQL=$sql;
		if (is_string($this->_className) && class_exists($this->_className,false)) {
			$retVal=new $this->_collectionClass();
			$retVal->compiledSQL=$sql;
			$retVal->totalRecords=intval($records["records"]);
			$retVal->affectedRecords=count($records["data"]);
			$retVal->finalSQL=$this->finalSQL;
			if ($retVal->affectedRecords>0 && $retVal->affectedRecords<$this->_minRecords) {
				$paginas=ceil($this->_minRecords/$retVal->affectedRecords);
			} else {
				$paginas=1;
			}
			for($p=0; $p<$paginas;$p++) {
				foreach($records["data"] as $record) {
					$_record=new $this->_className();
					foreach($record as $field => $value) {
						$_record->rawAttribute($field,$value);
					}
					if ($this->_triggerEvents && $this->_parentModel) $_record=$this->_parentModel->triggerEvent("getrecord",$_record);
					//if ($_record->getTableName()=='tbl_novedades') dump_var($_record);
					$retVal->addItem($_record);
				}
			}
		} else
			$retVal=$records["data"];
		return $retVal;
	}

	public function getAsArray() {
		$this->_className=null;
		return $this->get(null);
	}
	public function getCombo($selected,$textField,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (!is_array($selected)) $selected=array($selected);
		$records=$this->get();

		$retVal=array();
		foreach($records as $record) {
			if (is_string($textField))
				$text=trim("{$record[$textField]}");
			else if(is_array($textField) && is_object($textField[0]) && method_exists($textField[0],$textField[1]))
				$text=call_user_func($textField,$record);
			if (empty($text)) continue;
			$sel="";
			if (in_array($record[$record->indexField],$selected)) $sel=' selected="selected"'; else $sel='';
			$retVal[]="<option value=\"".$record[$record->indexField]."\"{$sel}>{$text}</option>";
		}
		return implode("\n",$retVal);
	}

	public function dataTable() {

		$computedFields=array();

		if (!is_array($this->_fields))
			$_fields=array($this->_fields);
		else
			$_fields=$this->_fields;

		foreach($_fields as $_field){
			$fields=explode(",",$_field);
			foreach($fields as $field) {
				preg_match_all("/ as ([`0-9a-z_]*?)/Usi",$field,$matches,PREG_SET_ORDER);
				if ($matches)
					foreach($matches as $match) $computedFields[]=$match[1];
			}
		}
		$parameters=func_num_args();
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}

		if ($parameters==2)
			$formatters=func_get_arg(1);

		$columns=func_get_arg(0);
		if($_POST['iDisplayStart'])
			$this->_offset=$_POST['iDisplayStart'];
		if($_POST['iDisplayLength'])
			$this->_limit=$_POST['iDisplayLength'];

		for ( $i=0 ; $i<intval( $_POST['iSortingCols'] ) ; $i++ ) {
			if ( $_POST[ 'bSortable_'.intval($_POST['iSortCol_'.$i]) ] == "true" && !empty($columns[ intval( $_POST['iSortCol_'.$i] ) ])) {
				$this->order($columns[ intval( $_POST['iSortCol_'.$i] ) ],($_POST['sSortDir_'.$i]==='asc' ? 'asc' : 'desc'));
			}
		}

		$searchW=$searchH=array();
		if ( isset($_POST['sSearch']) && $_POST['sSearch'] != "" ) {

			for ( $i=0 ; $i<count($columns) ; $i++ ) {
				if (in_array($columns[$i],$computedFields))
					$searchH[]= "`".$columns[$i]."` LIKE '%".Application::escape($_POST['sSearch'])."%'";
				else
					$searchW[]= "`".$columns[$i]."` LIKE '%".Application::escape($_POST['sSearch'])."%'";
			}
		}

		if (!empty($searchW))
			$this->where("(".implode (" OR ",$searchW).")");

		if (!empty($searchH))
			$this->having("(".implode (" OR ",$searchH).")");

		for ( $i=0 ; $i<count($columns) ; $i++ ) {
			if ( isset($_POST['bSearchable_'.$i]) && $_POST['bSearchable_'.$i] == "true" && $_POST['sSearch_'.$i] != '' ) {
				$this->where($columns[$i],'LIKE',"%".Application::escape($_POST['sSearch_'.$i])."%");
			}
		}

		$retVal=$this->get();
		$output=array(
			"sEcho" => intval($_POST['sEcho']),
			"iTotalRecords" => $retVal->totalRecords,
			"iTotalDisplayRecords" => $retVal->totalRecords,
			"aaData" => array()
		);
		foreach($retVal as $record) {
			$indexField=$record->indexField;
			$aaData=array();
			foreach($columns as $col) $aaData[]=utf8_encode($record->{$col});
			$aaData[]=$record->{$indexField};
			$output["aaData"][]=$aaData;
		}
		return $output;
	}

	public function first($className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		$retVal=$this->get();
		return $retVal[0];
	}

	public function lists() {
		$parameters=func_num_args();
		$whereClause=null;
		if (is_string(func_get_arg($parameters-1)) && class_exists(func_get_arg($parameters-1),false)) {
			$this->_className=func_get_arg($parameters-1);
			$parameters--;
		}
		if (is_bool(func_get_arg($parameters-1)) && func_get_arg($parameters-1)===true) {
			$associative=true;
			$parameters--;
		}
		if (is_array(func_get_arg($parameters-1))) {
			$whereClause=func_get_arg($parameters-1);
			$parameters--;
		}
		$fields=array();
		for($i=0; $i<$parameters; $i++)
			$fields[]=func_get_arg($i);

		if ($whereClause) $this->where($whereClause);
		$result=$this->get();
		$retVal=array();
		foreach($result as $record) {
			$_rec=(is_array($record)?$record:$record->record());
			if (count($fields)>1) {
				$item=array();
				foreach($fields as $fld) {
					if ($associative)
						$item[$fld]=$_rec[$fld];
					else
						$item[]=$_rec[$fld];
				}
			} else {
				$item=$_rec[$fields[0]];
			}
			$retVal[]=$item;
		}
		return $retVal;
	}

	public function find($value,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (!is_null($this->_className)) {
			$auxModel=new $this->_className;
			$retVal=$this->where($auxModel->indexField,$value)->first();
//			if ($this->_triggerEvents) $retVal=$this->_parentModel->triggerEvent("find",$retVal);
			return $retVal;
		}
	}

	public function insert($data,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (!$this->_compiled)
			$sql=$this->compile();

		$primaryKey=Application::$db->insert($this->_table,$data);

		$auxModel=new $this->_className;
		if ($auxModel->indexField)
			return $this->where($auxModel->indexField,$primaryKey)->first();

		return null;

	}

	public function update($data,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		if (!$this->_compiled)
			$sql=$this->compile();

		$auxModel=new $this->_className;

		$primaryKey=Application::$db->update($this->_table,$data,array($auxModel->indexField."=".$data[$auxModel->indexField]));
		return $this->where($auxModel->indexField,$data[$primaryKey])->first();
	}

	public function delete() {
		if (empty($this->_where)) {
			throw new Exception("DELETE without WHERE not allowed");
		}
		Application::$db->delete($this->_table, $this->_where);
		return true;
	}

	public function asArray() {
		$this->_className=null;
		return $this;
	}

	public function asModel($className) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;
		return $this;
	}

	public function each($callback,$className=null) {
		if (is_string($className) && class_exists($className,false))
			$this->_className=$className;

		foreach($this->get() as $record) {
			call_user_func($callback,$record);
		}
	}

	function safeEncoding($value) {
		$origValue=$value;
		if (stringUtils::hasUTF8Chars($value))
			$value=utf8_encode($value);

		$value=htmlentities($value,ENT_COMPAT | ENT_HTML401,"ISO-8859-1");
		if (strpos($value,"&Acirc;")!==false) {
			$value=utf8_decode($origValue);
			$value=htmlentities($value,ENT_COMPAT | ENT_HTML401,"ISO-8859-1");
		}

		$value=stringUtils::replace_all("&lt;","<",$value);
		$value=stringUtils::replace_all("&gt;",">",$value);
		$value=stringUtils::replace_all("&amp;","&",$value);
		$value=stringUtils::replace_all("&iuml;&iquest;&frac12;","&deg;",$value);
		$value=stringUtils::replace_all("&ordm;","&deg;",$value);

		return $value;
	}

	public function __call($method,$parameters) {
		if ($this->_parentModel && method_exists($this->_parentModel,$method)) {
			return call_user_func_array(array($this->_parentModel, $method), $parameters);
		} else {
			throw new Exception("TQueryBuilder error. Method '{$method}' not found. Debug info:<pre>".print_r(debug_backtrace(),true));
		}
	}

}
