<?php
/**
 * @copyright Copyright(c) 2011 aircheng.com
 * @file config.php
 * @brief
 * @author webning
 * @date 2011-03-24
 * @version 0.6
 * @note
 */
/**
 * @brief Config 产品中所有Config文件取值统一入口文件
 * @class Config
 */
class Config
{
	//不需要过滤的键名
	private static $safeKey = array('index_slide','service_online');
	private $configFile;
    private $config;
    /**
     * @brief 初始化对应的config文件
     * @param String $config config文件名
     */
    public function __construct($config)
    {
        $this->initConfig($config);
    }

    /**
     * @brief 设定Config文件
     * @param String $config config文件名
     */
    public function setConfig($config)
    {
        $this->initConfig($config);
    }

    /**
     * @brief 获取全部的config信息
     */
    public function getInfo()
    {
    	return $this->config;
    }

    /**
     * @brief  初始化对应的config文件
     * @param String $config config文件名
     * @return Array 或者为null
     */
    private function initConfig($config)
    {
        if(isset(IWeb::$app->config['configExt']) && isset(IWeb::$app->config['configExt'][$config]))
        {
        	$this->configFile = IWeb::$app->getBasePath().IWeb::$app->config['configExt'][$config];
        	$this->config     = require($this->configFile);
        }
        else
        	$this->config = null;
    }

    /**
     * @brief 取得当前Config文件下的对应变量
     * @param String $name 变量名
     * @return mixed
     * @note 此函数可自由扩展自己对应的默认值
     */
    public function __get($name)
    {
        if(isset($this->config[$name]))
        {
            return $this->config[$name];
        }
        return '';
    }

    /**
     * @brief 取得当前Config文件下的对应变量
     * @param String $name 变量名
     * @return mixed
     * @note 此函数可自由扩展自己对应的默认值
     */
    public function write($inputArray)
    {
    	return self::edit($this->configFile , $inputArray);
    }

	/**
	 * @brief 修改配置文件信息
	 * @param string 配置文件名
	 * @param array  写入的配置内容 key:配置信息里面key值; value:配置信息里面的value值
	 * @return boolean
	 */
	public static function edit($configFile,$inputArray)
	{
		//安全过滤要写入文件的内容
		foreach($inputArray as $key => $val)
		{
			if(!in_array($key,self::$safeKey))
			{
				$inputArray[$key] = IFilter::act($val,'text');
			}
		}

		$configStr = "";

		//读取配置信息内容
		if(file_exists($configFile))
		{
			$configStr   = file_get_contents($configFile);
			$configArray = require($configFile);
		}

		if(trim($configStr)=="")
		{
			$configStr   = "<?php return array( \r\n);?>";
			$configArray = array();
		}

		//表单中存在但是不进行录用的键值
		$except = array('form_index');

		foreach($except as $value)
		{
			unset($inputArray[$value]);
		}

		$inputArray = array_merge($configArray,$inputArray);
		$configData = var_export($inputArray,true);
		$configStr = "<?php return {$configData}?>";

		//写入配置文件
		$fileObj   = new IFile($configFile,'w+');
		$writeResult = $fileObj->write($configStr);
		return $writeResult;
	}

	/**
	 * @brief 获取语言包,主题,皮肤的方案
	 * @param string $type  方案类型: theme:主题; skin:皮肤; lang:语言包;
	 * @param string $theme 此参数只有$type为skin时才有用，获取任意theme下的skin方案;
	 * @return array key=>方案名称;value=>具体数据
	 */
	public static function getSitePlan($type,$theme = null)
	{
		$defaultConf = 'config.php';
		$planPath    = null;    //资源方案的路径
		$planList    = array(); //方案列表
		$configKey   = array('name','version','author','time','thumb','info','type');

		//根据不同的类型设置方案路径
		switch($type)
		{
			case "theme":
			{
				$planPath = IWeb::$app->getViewPath();
				$webPath  = IWeb::$app->getWebViewPath();
			}
			break;

			case "skin":
			{
				$planPath = IWeb::$app->getViewPath().$theme."/".IWeb::$app->defaultSkinDir."/";
				$webPath  = IWeb::$app->getWebViewPath().$theme."/".IWeb::$app->defaultSkinDir."/";
			}
			break;

			case "lang":
			{
				$planPath = IWeb::$app->getLanguagePath();
			}
			break;
		}

		if($planPath && is_dir($planPath))
		{
			$planList = array();
			$dirRes   = opendir($planPath);

			//遍历目录读取配置文件
			while(false !== ($dir = readdir($dirRes)))
			{
				if($dir[0] == ".")
				{
					continue;
				}
				$fileName = $planPath.'/'.$dir.'/'.$defaultConf;
				$tempData = file_exists($fileName) ? include($fileName) : array();
				if($tempData)
				{
					//拼接系统所需数据
					foreach($configKey as $val)
					{
						if(!isset($tempData[$val]))
						{
							$tempData[$val] = '';
						}
					}

					//缩略图拼接路径
					if(isset($tempData['thumb']) && isset($webPath))
					{
						$tempData['thumb'] = $webPath.$dir.'/'.$tempData['thumb'];
					}
					$planList[$dir] = $tempData;
				}
			}
		}
		return $planList;
	}
}