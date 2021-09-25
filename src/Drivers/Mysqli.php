<?php
namespace Koshkil\DBAL\Drivers;

use Koshkil\DBAL\Exceptions\ExceptionDatabaseError;

class Mysqli {

	private $handler=null;
	public $debug=false;

	public function __construct($host,$user,$pass,$name) {

		$this->handler=@new mysqli($host,$user,$pass,$name);
		if ($this->handler->connect_error) {
			throw new ExceptionDatabaseError("Imposible conectar a la base de datos: {$this->handler->connect_error} ({$this->handler->connect_errno})");
		}
		$this->setCharSet();
	}

	public function version() {
		return $this->handler->client_version;
	}

	public function setCharSet($charset="utf-8") {
		return $this->handler->set_charset($charset);
	}
	public function table_exists($tableName) {
		$sql="SHOW TABLES LIKE '{$tableName}';";
		$res=$this->execute($sql);
		$row=$res->fetch_assoc();
		return ($row?true:false);
	}

	public function getTables() {
		$sql="SHOW TABLES;";
		$res=$this->execute($sql);
		$tables=array();
		while ($tbl=$this->getNextRecord($res)) {
			$key="Tables_in_".Application::get("DB_NAME");
			$tables[]=$tbl[$key];
		}
		return $tables;
	}

	public function execute($sql) {
		$retVal=$this->handler->query($sql);
		if (!$retVal) {
			KoshkilLog::error("Error al ejecutar SQL: ".mysqli_error($this->handler)."\n ({$sql})<br/>Debug info:<pre>".print_r(debug_backtrace(),true));
			throw new EQueryError("Error al ejecutar SQL: ".mysqli_error($this->handler)."<br/> ({$sql})<bR/>Debug info:<pre>".print_r(debug_backtrace(),true));
		}
		return $retVal;
	}

	public function getRow($sql) {
		$res=$this->execute($sql);
		$rec=$res->fetch_assoc();
		if ($this->handler->error) {
			throw new EQueryError($this->handler->error);
		}
		return $rec;
	}

	public function getNextRecord($res) {
		return $res->fetch_assoc();
	}

	public function escape($str) {
		return $this->handler->real_escape_string($str);
	}

	public function close() {
		$this->handler->close();
	}

	public function lastInsertId() {
		return $this->handler->insert_id;
	}

	public function insert($table, $data) {
		if (!is_array($data)) {
			throw new EDatasetError("No se ha indicado un set de campos y valores");
		}
		$fields=array();
		foreach($data as $field => $value) {
			$escapedValue=Application::escape($value);
			if (!is_null($value))
				$fields[]="{$field}='{$escapedValue}'";
			else
				$fields[]="{$field}=NULL";
		}
		$tableDef=explode(" ",$table);
		$table=array_shift($tableDef);
		$sql="INSERT INTO {$table} SET ".implode(", ",$fields);
		$this->execute($sql);
		return $this->lastInsertId();
	}

	public function update($table, $data,$condition=false) {
		if (!is_array($data)) {
			throw new EDatasetError("No se ha indicado un set de campos y valores");
		}

		if (!$condition) {
			$physPath=Application::Get('PHYS_PATH');
			$configFolder=$physPath."/config";
			if (file_exists($configFolder."/db.php")) {
				include($configFolder."/db.php");
				if (isset($database[$table])) {
					$_data=array();
					foreach($data as $field => $value) {
						if (isset($database[$table]["fields"][$field]))
							$_data[$field]=$value;
					}
					$data=$_data;
					foreach($database[$table]["keys"] as $key) {
						if ($key["primary"]) {
							$fields=explode(",",$key["fields"]);
							$condition=array();
							foreach($fields as &$field) {
								$field=trim($field);
								$field=stringUtils::replace_all("`","",$field);
								$field="{$field}";
								if (isset($data[$field])) {
									$val=Application::escape($data[$field]);
									$condition[]="`{$field}`='{$val}'";
								}
							}
							break;
						}
					}
				}
			}
		}


		if (is_array($condition)) $where="WHERE ".implode(" AND ",$condition);
		elseif ($condition) $where="WHERE ".$condition;
		else $where="";

		$fields=array();
		foreach($data as $field => $value) {
			$escapedValue=Application::escape($value);
			if (!is_null($value))
				$fields[]="{$field}='{$escapedValue}'";
			else
				$fields[]="{$field}=NULL";
		}
		$tableDef=explode(" ",$table);
		$table=array_shift($tableDef);
		$sql="UPDATE {$table} SET ".implode(", ",$fields)." ".$where;
		$this->execute($sql);
	}

	public function delete($table, $condition) {
		if (is_array($condition)) $where="WHERE ".implode(" AND ",$condition);
		elseif ($condition) $where="WHERE ".$condition;
		else $where="";
		$tableDef=explode(" ",$table);
		$table=array_shift($tableDef);
		$sql="DELETE FROM {$table} ".$where;
		$this->execute($sql);
	}

	public function getSQL($table,$condition="",$order="",$start=-1,$number=-1) {
		if (is_array($condition)) $where="WHERE ".implode(" AND ",$condition);
		elseif ($condition) $where="WHERE ".$condition;
		else $where="";

		if (trim($where)=="WHERE") $where="";
		if ($start!=-1 || $number!=-1) {
			$limits=array();
			if ($start!=-1) $limits[]=$start;
			if ($number!=-1) $limits[]=$number;
			$limit="LIMIT ".implode(", ",$limits);
		}
		else $limit="";

		if ($order) $order="ORDER BY {$order}";
		else $order="";

		$fields="*";
		$groupBy="";
		$having="";
		$calcFoundRows="SQL_CALC_FOUND_ROWS";
		if (is_string($table)) {
			$DISTINCT="";
			if (substr($table,0,9)=="distinct ") {
				$DISTINCT="DISTINCT ";
				$table=substr($table,9);
			}
			$tableName=$table;
		} else if (is_array($table)) {
			$fields=$table["fields"];
			$tableName=$table["table"];

			if (isset($table["grouping"]) && !empty($table["grouping"]) && $table["grouping"]!="t") {
				$groupBy="GROUP BY ";
				if (is_array($table["grouping"]))
					$groupBy.=implode(", ",$table["grouping"]);
				else
					$groupBy.=$table["grouping"];
			}
			if (isset($table["having"]) && !empty($table["having"]) && $table["having"]!="t") {
				$having="HAVING ";
				if (is_array($table["having"]))
					$having.=implode(", ",$table["having"]);
				else
					$having.=$table["having"];
			}
			if ($table["debug"]) KoshkilLog::error($having);
			if ($table["distinct"]) $DISTINCT="DISTINCT ";
			if ($table["calc_found_rows"]===false) $calcFoundRows=" ";
		}


		$query="select {$DISTINCT}{$calcFoundRows} {$fields} from {$tableName} {$where} {$groupBy} {$having} {$order} {$limit}";
		return $query;
	}
	public function getRecords($table,$condition="",$order="",$start=-1,$number=-1,$debug=false) {
		$query=$this->getSQL($table,$condition,$order,$start,$number);
		if (Application::get('DEBUG_LEVEL')>=6) KoshkilLog::debug("[SQL DEBUG]:{$query}");
		$res=$this->execute($query);
		$records=array();
		while ($rec=$this->getNextRecord($res)) {
			$records[]=$rec;
		}
		$total=$this->getRow("select found_rows() as total");
		$retVal=array(
			"data"=>$records,
			"records"=>$total["total"]
		);
		if ($debug) $retVal["sql"]=$query;
		return $retVal;
	}

	public function getRecord($table,$condition="") {
		$retVal=$this->getRecords($table,$condition,"",0,1);
		return $retVal["data"][0];
	}

	public function performTransaction(array $sqls) {

	}
}
