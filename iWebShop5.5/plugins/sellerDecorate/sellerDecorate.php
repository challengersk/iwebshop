<?php
/**
 * @copyright (c) 2017 aircheng.com
 * @file sellerDecorate.php
 * @brief 商家店铺装饰类
 * @date 2017/1/5 10:41:41
 * @version 4.7
 */
class sellerDecorate extends pluginBase
{
	//商户布局方案名称
	public $layout = "default";

	//商户主题方案名称
	public $theme = "";

	public static function name()
	{
		return "商家店铺装饰";
	}

	public static function description()
	{
		return "装饰各个商家店铺，让店铺有独立的主题模板风格，商家模板放到: /plugins/sellerDecorate/sellerTheme 里面";
	}

	public static function install()
	{
		$decorateDB = new IModel('seller_decorate');
		if($decorateDB->exists())
		{
			return true;
		}
		$data = array(
			"comment" => self::name(),
			"column"  => array(
				"seller_id" => array("type" => "int(11) unsigned","comment" => "商家ID"),
				"theme" => array("type" => "varchar(255)","comment" => "模板主题名称"),
			),
			"index" => array("primary" => "seller_id"),
		);
		$decorateDB->setData($data);
		return $decorateDB->createTable();
	}

	public static function uninstall()
	{
		$decorateDB = new IModel('seller_decorate');
		return $decorateDB->dropTable();
	}

	public function reg()
	{
		//后台管理部分
		plugin::reg("onSystemMenuCreate",function(){
			$link = "/plugins/decorate_list";
			$link = "javascript:art.dialog.open('".IUrl::creatUrl($link)."',{title:'".$this->name()."',width:'100%',height:'100%',id:'decorate'});";
			Menu::$menu["插件"]["插件管理"][$link] = $this->name();
		});

		//装饰列表视图
		plugin::reg("onBeforeCreateAction@plugins@decorate_list",function(){
			self::controller()->decorate_list = function(){$this->view('decorate_list',array('themeData' => $this->getSellerTheme()));};
		});

		//设置商户主题
		plugin::reg("onBeforeCreateAction@plugins@decorate_setting",function(){
			self::controller()->decorate_setting = function()
			{
				$seller_id = IFilter::act(IReq::get('seller_id'),'int');
				$theme     = IFilter::act(IReq::get('theme'));
				$this->updateTheme($seller_id,$theme);
			};
		});

		//商户管理部分
		$configData = $this->config();
		if(isset($configData['sellerCostom']) && $configData['sellerCostom'] == 'yes')
		{
			//商家管理
			plugin::reg("onSellerMenuCreate",function(){
				$link = "/seller/decorate_edit";
				$link = "javascript:art.dialog.open('".IUrl::creatUrl($link)."',{title:'".$this->name()."',width:'100%',height:'100%',id:'decorate'});";
				menuSeller::$menu["配置模块"][$link] = $this->name();
			});

			//商户装饰修改页面
			plugin::reg("onBeforeCreateAction@seller@decorate_edit",function(){
				self::controller()->decorate_edit = function(){
					$seller_id   = self::controller()->seller ? self::controller()->seller['seller_id'] : 0;
					$sellerDeDB  = new IModel('seller_decorate');
					$sellerDeRow = $sellerDeDB->getObj('seller_id = '.$seller_id);
					$this->view('decorate_edit',array('themeData' => $this->getSellerTheme(),'sellerDeRow' => $sellerDeRow));
				};
			});

			//商户设置主题
			plugin::reg("onBeforeCreateAction@seller@decorate_setting",function(){
				self::controller()->decorate_setting = function()
				{
					$seller_id = self::controller()->seller ? self::controller()->seller['seller_id'] : 0;
					$theme     = IFilter::act(IReq::get('theme'));
					$this->updateTheme($seller_id,$theme);
				};
			});
		}

		//商户首页
		plugin::reg("onBeforeCreateAction@site@home",function(){
			$this->theme = $this->getTheme();
			if($this->theme)
			{
				self::controller()->home = function(){
					$seller_id = IFilter::act(IReq::get('id'),'int');
					$sellerRow = Api::run('getSellerInfo',$seller_id);
					if(!$sellerRow)
					{
						IError::show(403,'商户信息不存在');
					}
					$this->controller()->layout = $this->getLayout();
					$this->controller()->setRenderData(array('sellerRow' => $sellerRow,'seller_id' => $seller_id));
					$this->redirect($this->getView('home'));
				};
			}
		});
	}

	public static function configName()
	{
		return array(
			"sellerCostom" => array("name" => "允许商家自选","type" => "radio","value" => array("允许" => "yes","禁止" => "no"),"info" => "提示：允许:商家可自己从后台选择模板; 禁止:只能管理员设置商家模板"),
		);
	}

	public static function explain()
	{
		return "把商户模板以目录形式放到 \plugins\sellerDecorate\sellerTheme 里面，即可在配置模板页面中出现选择项";
	}

	//更新商户模板信息
	public function updateTheme($seller_id,$theme)
	{
		if($seller_id)
		{
			$sellerDeDB= new IModel('seller_decorate');
			$sellerDeDB->setData(array('seller_id' => $seller_id,'theme' => $theme));
			return $sellerDeDB->replace();
		}
		return false;
	}

	//获取商家模板方案名称
	public function getSellerTheme()
	{
		$result   = array();
		$planPath = $this->path().'sellerTheme';
		if(is_dir($planPath))
		{
			$dirRes = opendir($planPath);

			//遍历目录读取配置文件
			while(false !== ($dir = readdir($dirRes)))
			{
				if($dir[0] == ".")
				{
					continue;
				}
				$result[] = $dir;
			}
		}
		return $result;
	}

	//获取layout布局文件路径
	public function getLayout()
	{
		return self::path().'layouts/'.$this->layout;
	}

	//获取商户主题
	public function getTheme($seller_id = "")
	{
		$seller_id  = $seller_id ? $seller_id : IFilter::act(IReq::get('id'),'int');
		$sellerDeDB = new IModel('seller_decorate');
		$sellerDeRow= $sellerDeDB->getObj('seller_id = '.$seller_id);
		if($sellerDeRow && $sellerDeRow['theme'])
		{
			return $sellerDeRow['theme'];
		}
		return '';
	}

	//获取模板路径
	public function getView($view)
	{
		return 'sellerTheme/'.$this->theme.'/'.$view;
	}
}