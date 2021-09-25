<?php 
namespace Koshkil\DBAL;

use Koshkil\Core\Application;
use Koshkil\DBAL\QueryBuilder;
use Koshkil\DBAL\SchemaManager;

class Model implements \ArrayAccess {

    private $qb;

	//Readonly Model
	protected $readOnly=false;

	protected $structure=NULL;

	// On which table it shall work
	protected $table="";

	protected $alias="";

	// Which fields will be filled with data
	protected $fillable=array();

	// Which fields will not be encoded when filled with data
	protected $skipEncoding=array();
	protected $encodeQuotes=array();

	// Which fields are dates or datetimes
	protected $dates=array();

	// Which fields are json objects
	protected $json=array();

	// Fields values
	public $attributes=array();

	// The primary index field
	protected $indexField="";

	protected $auditEnabled=true;

	protected $encodingFunction=null;
	protected $decodingFunction=null;

	protected $tablePrefix='';

	protected $dependantModels=array();

	public function __construct() {
		$this->initModel();
	}


	protected function initModel() {
		$this->loadClassEvents($this);
		$this->setupTableStructure();
		$this->triggerEvent("setupTableStructure");
		if (Application::get("DB_STRUCTURE_AUTOMANAGED")==="true") {
			SchemaManager::checkTable($this);
		}
		$this->initBuilder();
	}

	protected function setupTableStructure() {}

	public function canDelete() {
		foreach($this->dependantModels as $model) {
			$className=Application::UsesModel($model);
			$instance=new $className;
			if ($instance->hasField($this->indexField)) {
				$records=$className::noEvents(false,get_class($instance))->where($this->indexField,$this->recordId())->first();
				if ($records) return false;
			}
		}
		return true;
	}

	public static function getIndexField() {
		$dummy=new static;
		$retVal=$dummy->indexField;
		unset($dummy);
		return $retVal;
	}

	public function initBuilder() {
		$this->qb=new QueryBuilder($this);
		$this->qb->clear();
		$this->qb->from($this->table.($this->alias!=""?" as ".$this->alias:""));
		return $this;
	}

	public function builder() {
		return $this->qb;
	}

	public function fill($data,$prefix="") {
		foreach($data as $field=>$value) {
			if ($prefix) $field=$prefix.$field;
			if (in_array($field,$this->fillable))
				$this->setAttribute($field,$value);
		}
		return $this;
	}

	public function hasField($fieldName) {
		if ($this->structure && is_array($this->structure) && $this->structure["fields"]) {
			return isset($this->structure["fields"][$fieldName]);
		}
		return isset($this->attributes[$fieldName]);
	}

	public static function create($data,$tempFillable=[]) {
		$instance = new static;
		if ($instance->readOnly) return;
		//$record=array($instance->indexField=>$instance[$instance->indexField]);
		foreach($data as $field=>$value) {
			if (in_array($field,$instance->fillable) || (is_array($tempFillable) && in_array($field,$tempFillable)))
				$instance->{$field}=$value;
		}
		$retVal=$instance->builder()->insert($instance->record(),get_class($instance));
		$retVal=$instance->triggerEvent("create",$retVal);
		return $retVal;
	}

	public function update() {
		if ($this->readOnly) return;
		$record=array($this->indexField=>$this[$this->indexField]);
		foreach($this->attributes as $field=>$value) {
			if (in_array($field,$this->fillable))
				$record[$field]=$value;
		}
		$retVal=$this->initBuilder()->builder()->update($record,get_class($this));
		$this->triggerEvent("update");
		return $retVal;
	}

	public function delete() {
		if ($this->readOnly) return;
		if ($this->indexField && $this[$this->indexField]) {
			$this->initBuilder()->builder()->where($this->indexField,$this[$this->indexField])->delete();
			$this->triggerEvent("delete");
		}
		return;
	}

	public function record() {
		return $this->attributes;
	}

	public function encodeFieldValue($value,$encodeQuotes) {
		if (!is_string($value)) return $value;
		if (preg_match_all("/&[lr]dquo;/si",htmlentities($value),$matches)) {
			foreach($matches[0] as $specialQuotes) {
				$value=html_entity_decode(str_replace($specialQuotes,"&quot",htmlentities($value)));
			}
		}
		if(ini_get('default_charset')=="UTF-8")
			$value=utf8_decode($value);
		$value=htmlentities($value,ENT_COMPAT | ENT_HTML401,"ISO-8859-1");
		$value=stringUtils::replace_all("[:euro:]","&euro;",$value);
		$value=stringUtils::replace_all("&lt;","<",$value);
		if (!$encodeQuotes)
			$value=stringUtils::replace_all("&quot;",'"',$value);
		$value=stringUtils::replace_all("&gt;",">",$value);
		$value=stringUtils::replace_all("&amp;","&",$value);
		return $value;
	}

	public function decodeFieldValue($value) {
		$value=stringUtils::replace_all("&euro;","[:euro:]",$value);
		return $value;
	}

	private function getAttribute($attribute) {
		list($fieldName,$function)=explode("|",$attribute);
		$retVal=$this->attributes[$fieldName];

		if (!is_null($this->decodingFunction) && isset($this->decodingFunction[$attribute])) {
			$func=$this->decodingFunction[$attribute];
			return $func($retVal);
		}
		//$retVal=$this->decodeFieldValue($retVal);
		if (!empty($function)) $retVal=$function($retVal);
		if (in_array($fieldName,$this->dates)) {
			if (preg_match_all("/([0-9]{4})\-([0-9]{2})\-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/si",$retVal,$matches)) {
				list($d,$t)=explode(" ",$retVal);
				$d=implode("/",array_reverse(explode("-",$d)));
				$retVal=$d." ".$t;
			} else if (preg_match_all("/([0-9]{4})\-([0-9]{2})\-([0-9]{2})/si",$retVal,$matches)) {
				$retVal=implode("/",array_reverse(explode("-",$retVal)));
			}
		} else if (in_array($fieldName,$this->json)) {
			$retVal=@json_decode($retVal,true);
		}

		return $retVal;
	}

	public function rawAttribute($attribute,$value=null) {
		list($fieldName,$function)=explode("|",$attribute);
		if (!is_null($value)) {
			$this->attributes[$fieldName]=$value;
			return $value;
		} else
			$retVal=$this->attributes[$fieldName];
		//$retVal=$this->decodeFieldValue($retVal);
		if (!empty($function)) $retVal=$function($retVal);
		return $retVal;
	}

	private function setAttribute($attribute,$value) {
		if (!in_array($attribute,$this->skipEncoding)) {
			if (!is_null($this->encodingFunction) && isset($this->encodingFunction[$attribute])) {
				$value=call_user_func($this->encodingFunction[$attribute],$value);
			} else
				$value=$this->encodeFieldValue($value,in_array($attribute,$this->encodeQuotes));
		}

		if (in_array($attribute,$this->dates)) {
			$dt=preg_match_all("/([0-9]{2})\/([0-9]{2})\/([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/si",$value,$matches) || preg_match_all("/([0-9]{2})\/([0-9]{2})\/([0-9]{4}) ([0-9]{2}):([0-9]{2})/si",$value,$matches) || preg_match_all("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/si",$value,$matches);
			if ($dt) {
				list($d,$t)=explode(" ",$value);
				$d=implode("-",array_reverse(explode("/",$d)));
				if ($t) {
					$hms=explode(":",$t);
					for($i=count($hms); $i<3; $i++) $hms[]="00";
					$t=implode(":",$hms);
				}
				$value=trim($d." ".$t);
			} else if (preg_match_all("/([0-9]{2})\/([0-9]{2})\/([0-9]{4})/si",$value,$matches)) {
				$value=implode("/",array_reverse(explode("-",$value)));
			}
		} else if (in_array($attribute,$this->json)) {
			$value=@json_encode($value);
		}
		$this->attributes[$attribute]=$value;
		return $value;
	}

	public static function __callStatic($method,$parameters) {
		$instance = new static;
		if (method_exists($instance,$method))
			return call_user_func_array(array($instance, $method), $parameters);
		else if (method_exists($instance->builder(),$method)) {
			if (is_array($parameters))
				$parameters[]=get_class($instance);
			else
				$parameters=array(get_class($instance));
			return call_user_func_array(array($instance->builder(), $method), $parameters);
		}
		throw new Exception("TModel error. Method '{$method}' not found. Debug info:<pre>".print_r(debug_backtrace(),true));
	}

	public function __call($method,$parameters) {
		$builder=$this->builder();
		if ($builder && method_exists($builder,$method)) {
			return call_user_func_array(array($builder, $method), $parameters);
		} else {
			throw new Exception("QueryBuilder error. Method '{$method}' not found. Debug info:<pre>".print_r(debug_backtrace(),true));
		}
	}

	public function offsetExists ($offset) {
		return isset($this->attributes[$offset]);
	}

	/**
	 * @param offset
	 */
	public function offsetGet ($offset) {
		list($fieldName,$function)=explode("|",$offset);
		if (isset($this->attributes[$fieldName])) {
			return $this->getAttribute($offset);
		} else
			return null;

	}

	/**
	 * @param offset
	 * @param value
	 */
	public function offsetSet ($offset, $value) {
		$this->setAttribute($offset,$value);
	}

	/**
	 * @param offset
	 */
	public function offsetUnset ($offset) {
		unset($this->attributes[$offset]);
	}

	public function __get($varName) {
		if (method_exists($this,$varName))
			return call_user_func(array($this,$varName));
		else if (isset($this->attributes[$varName]))
			return $this->getAttribute($varName);
		return null;
	}

	public function __set($varName,$value) {
		return $this->setAttribute($varName,$value);
	}

	public function __toString() {
		return serialize($this->attributes);
	}

	public function getStructure() {
		return $this->structure;
	}
	public function getTableName() {
		return $this->table;
	}
	public function recordId() {
		if ($this->indexField && $this[$this->indexField]) return $this[$this->indexField];
		return null;
	}

	public function asPost($prefix="") {
		foreach($this->attributes as $attr=>$value) {
			if (is_array($prefix)) {
				foreach($prefix as $prx)
					Application::setOld(str_replace($prx,"",$attr),$this->getAttribute($attr));
			} else
				Application::setOld(str_replace($prefix,"",$attr),$this->getAttribute($attr));
		}
	}

	public function primaryKey() {
		if ($this->indexField) return $this->indexField;
		return null;
	}
	public function hasAttribute($attribute) {
		return isset($this->structure["fields"][$attribute]);
	}

	public function internationalDate($field) {
		return $this->attributes[$field];
	}

}