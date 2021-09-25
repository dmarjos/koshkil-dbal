<?php
namespace Koshkil\DBAL;

class SchemaManager {

	public static function checkTable($model) {
		$structure=$model->getStructure();
		$tbl_name=$model->getTableName();
		foreach(array("fields","keys") as $section) {
			if (!isset($structure[$section])) return false;
		}
		$columns=array();
		foreach($structure["fields"] as $fieldName=>$column) {
			$columnDef="`{$fieldName}` {$column["type"]}";
			if ($column["length"]) $columnDef.="({$column["length"]})";
			if ($column["extra"]) $columnDef.=" ".$column["extra"];
			$columns[]=$columnDef;
		}
		$keys=array();
		$indexField=null;
		foreach($structure["keys"] as $keyDef) {
			if ($keyDef["primary"]) {
				$keys[]="PRIMARY KEY ({$keyDef["fields"]})";
				$indexField=$keyDef["fields"];
			} else if (strtolower($keyDef["key_type"])!="fulltext")
				$keys[]="KEY `{$keyDef["key_name"]}` ({$keyDef["fields"]})";
		}
		$createTable="CREATE TABLE {$tbl_name} (".implode(", ",$columns).($keys?", ".implode(",",$keys):'').") ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE latin1_spanish_ci;";
		$db=Application::getDatabase();
//		KoshkilLog::error("------ CHECK IF TABLE EXISTS: {$tbl_name}");
		$tableExists=$db->getRow("show tables like '{$tbl_name}'");
		if (!$tableExists) {
			$db->execute($createTable);
			$model->triggerEvent('tableCreated');
			if ($structure["initial_records"]) {
				$modelClass=get_class($model);
				foreach($structure["initial_records"] as $data) {
					$modelClass::create($data);
				}
				$model->triggerEvent('tablePopulated');
			}
		} else {
			$rec=$db->getRow("show create table {$tbl_name}");
			$sql=$rec["Create Table"];
			preg_match_all("/\n[\s]*([^\s]+)\s([\w]+)(\([^\)]+\))?(\n\))?/s", $sql, $matches);
			$changedStructure=[];

			if (!preg_match("/DEFAULT CHARSET=latin1 COLLATE=latin1_spanish_ci/s",$sql))
				self::$db->execute("ALTER TABLE {$tbl_name} CONVERT TO CHARACTER SET latin1 COLLATE latin1_spanish_ci");

			$fields=$matches[1];
			$types=$matches[2];
			$sizes=$matches[3];
			$fieldsInTable=array();
			foreach($fields as $idx=>$field){
				if (substr($field,0,1)=="`" && substr($field,-1)=="`") {
					$fieldName=substr($field,1,-1);
					$size=$sizes[$idx];
					$size=str_replace("(","",$size);
					$size=str_replace(")","",$size);
					$fieldsInTable[$fieldName]=array("type"=>$types[$idx],"length"=>$size);
				}
			}
			foreach($structure["fields"] as $fieldName=>$field) {
				if (!$fieldsInTable[$fieldName]) {
					$columnDef="`{$fieldName}` {$field["type"]}";
					if ($field["length"]) $columnDef.="({$field["length"]})";
					if ($field["extra"]) $columnDef.=" ".$field["extra"];
					$changedStructure[]="ADD {$columnDef}";
				} else {
					if ($field["type"]!=$fieldsInTable[$fieldName]["type"] || $field["length"]!=$fieldsInTable[$fieldName]["length"]) {
						$columnDef="`{$fieldName}` {$field["type"]}";
						if ($field["length"]) $columnDef.="({$field["length"]})";
						if ($field["extra"]) $columnDef.=" {$field["extra"]}";
						$changedStructure[]="CHANGE `{$fieldName}` {$columnDef}";
					}
				}
			}
			foreach($fieldsInTable as $fieldName=>$fieldDef) {
				if (!isset($structure["fields"][$fieldName])) {
					$changedStructure[]="DROP `{$fieldName}`";
				}
			}
			if ($changedStructure) {
				$sql="ALTER TABLE {$tbl_name} ";
				$sql.=implode(", ",$changedStructure);
				try {
					$db->execute($sql);
				} catch(Exception $e) {
					KoshkilLog::error("EXCEPTION: ".$e->getMessage().", SQL: {$sql}");
				}
			}

			if (!isset($structure["keys"]))
				$structure["keys"]=array();

			foreach($structure["keys"] as $key) {
				if ($key["primary"]) continue;
				$fields=explode(",",$key["fields"]);
				foreach($fields as &$field) {
					$field=trim($field);
					$field=StringUtils::replace_all("`","",$field);
					$field="`{$field}`";
				}
				$key["fields"]=implode(",",$fields);
				$regExp="/KEY `{$key["key_name"]}` \({$key["fields"]}\)/sim";
				if (!@preg_match_all($regExp,$rec["Create Table"])) {
					if (preg_match("/KEY `{$key["key_name"]}`/s",$rec["Create Table"])) {
						$db->execute("DROP INDEX `{$key["key_name"]}` on `{$tbl_name}`");
						$db->execute("CREATE ".(strtoupper($key["key_type"])=="FULLTEXT"?"FULLTEXT ":"")."INDEX `{$key["key_name"]}` on `{$tbl_name}` ({$key["fields"]})");
					} else {
						$db->execute("CREATE ".(strtoupper($key["key_type"])=="FULLTEXT"?"FULLTEXT ":"")."INDEX `{$key["key_name"]}` on `{$tbl_name}` ({$key["fields"]})");
					}
				}
			}
			return true;
		}
	}

}
