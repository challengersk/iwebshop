<?php
/**
 * @brief 插件模块
 * @class plugins
 * @note  后台
 */
class plugins extends IController implements adminAuthorization
{
	public $layout='admin';
	public $checkRight = array('check' => 'all');

	function init()
	{

	}

	//修改插件
	public function plugin_edit()
	{
		$className = IFilter::act(IReq::get('class_name'));
		if(!$className || !$pluginRow = plugin::getItems($className))
		{
			IError::show("插件不存在");
		}
		$this->pluginRow = $pluginRow;
		if($this->pluginRow['is_install'] == 0)
		{
			IError::show("插件必须还没有安装无法进行配置");
		}
		$this->redirect('plugin_edit');
	}

	//更新插件信息
	public function plugin_update()
	{
		$className    = IFilter::act(IReq::get('class_name'));
		$isOpen       = IFilter::act(IReq::get('is_open'));
		$sort         = IFilter::act(IReq::get('sort'));
		$config_param = array();

		$pluginRow = plugin::getItems($className);
		if(!$pluginRow)
		{
			IError::show("插件不存在");
		}

		if($pluginRow['is_install'] == 0)
		{
			IError::show("插件还没有安装无法进行配置");
		}

		if($_POST)
		{
			foreach($_POST as $key => $val)
			{
				if(array_key_exists($key,$pluginRow['config_name']))
				{
					$config_param[$key] = is_array($val) ? join(";",$val) : $val;
					$config_param[$key] = trim($config_param[$key]);
				}
			}
		}
		$pluginDB = new IModel('plugin');
		$pluginDB->setData(array(
			'is_open'      => $isOpen,
			'sort'         => $sort,
			'config_param' => IFilter::act(JSON::encode($config_param)),
		));
		$pluginDB->update('class_name = "'.$className.'"');
		$this->redirect('plugin_list');
	}

	//删除插件
	public function plugin_del()
	{
		$className = IFilter::act(IReq::get('class_name'));
		$pluginRow = plugin::getItems($className);
		if(!$pluginRow)
		{
			IError::show("插件不存在");
		}

		if($pluginRow['is_install'] == 0)
		{
			IError::show("插件未安装到系统");
		}

		//运行插件uninstall卸载接口
		$uninstallResult = call_user_func(array($pluginRow['class_name'],"uninstall"));
		if($uninstallResult === true)
		{
			//删除插件从plugin表数据库中
			$pluginDB = new IModel('plugin');
			$pluginDB->del('class_name = "'.$className.'"');
		}
		else
		{
			$message = is_string($uninstallResult) ? $uninstallResult : "卸载插件失败";
			IError::show($message);
		}
		$this->redirect('plugin_list');
	}

	//添加插件
	public function plugin_add()
	{
		$className = IFilter::act(IReq::get('class_name'));
		$pluginRow = plugin::getItems($className);
		if(!$pluginRow)
		{
			IError::show("插件不存在");
		}

		if($pluginRow['is_install'] == 1)
		{
			IError::show("插件已经安装到系统");
		}

		//运行插件install安装接口
		$installResult = call_user_func(array($pluginRow['class_name'],"install"));
		if($installResult === true)
		{
			//插入到plugin表数据库中
			$pluginDB = new IModel('plugin');
			$pluginDB->setData(array(
				"name"       => $pluginRow['name'],
				"class_name" => $pluginRow['class_name'],
				"description"=> $pluginRow['description'],
				"is_open"    => 0,
			));
			$pluginDB->add();
		}
		else
		{
			$message = is_string($installResult) ? $message : "安装插件失败";
			IError::show($message);
		}
		$this->redirect('plugin_list');
	}
}