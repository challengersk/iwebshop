<?php
/**
 * @copyright (c) 2011 aircheng.com
 * @file splitWord.php
 * @brief 智能分词类
 * @date 2017/11/25 12:43:50
 * @version 5.0
 */

/**
 * @class splitWord
 * @brief 智能分词管理类
 * @note  所有的扩展接口都放到当前目录下的extend目录中
 */
class splitWord extends pluginBase
{
	//分词实例对象
	private $instance = null;

	//名字
	public static function name()
	{
		return "商品智能分词";
	}

	//描述
	public static function description()
	{
		return "1,商品添加修改时对名称进行分词; 2,根据商品名称的分词形式进行查询。方便商品标签自动生成，商品智能检索更快更精准的让买家找到合适的商品";
	}

	//插件默认配置
	public static function configName()
	{
		$configData = array(
			'type' => array("name" => "应用接口","type" => "select","pattern" => "required","value" => array(),"bind" => array()),
		);

		//扩展接口配置信息
		$extPath = dirname(__FILE__).'/extend';
		$extData = IFile::getList($extPath);
		foreach($extData as $file)
		{
			include($extPath.'/'.$file);
			$className  = basename($file,'.php');

			//扩展接口的文件名（类名）和 名称作为选项数据
			$configData['type']['value'][$className::name()] = $className;

			//扩展接口的配置进行绑定和合并到总的配置表
			$extconfig = $className::configName();
			if($extconfig)
			{
				foreach($extconfig as $colum => $data)
				{
					if(isset($configData['type']['bind'][$className]))
					{
						$configData['type']['bind'][$className][] = $colum;
					}
					else
					{
						$configData['type']['bind'][$className] = array($colum);
					}
					$configData[$colum] = $data;
				}
			}
		}
		return $configData;
	}

	//获取分词实例化
	private function getInstance()
	{
		if($this->instance)
		{
			return $this->instance;
		}

		$config    = $this->config();
		$className = $config['type'];
		$path      = dirname(__FILE__)."/extend/".$className.".php";
		if(is_file($path))
		{
			include($path);
			$this->instance = new $className($config);
			return $this->instance;
		}
		throw new IException("未获取到分词接口");
	}

	//注册事件
	public function reg()
	{
		plugin::reg("onBeforeCreateAction@goods@goods_tags_words",function(){
			self::controller()->goods_tags_words = function(){$this->goods_tags_words();};
		});

		plugin::reg("onBeforeCreateAction@seller@goods_tags_words",function(){
			self::controller()->goods_tags_words = function(){$this->goods_tags_words();};
		});

		plugin::reg("onFinishView@goods@goods_edit",function(){
			$this->goodsEditNameWord("/goods/goods_tags_words");
		});

		plugin::reg("onFinishView@seller@goods_edit",function(){
			$this->goodsEditNameWord("/seller/goods_tags_words");
		});

		//商品查询分词
		plugin::reg("onSearchGoodsWordsPart",$this,"run");
	}

	/**
	 * @brief 运行分词
	 * @param string $content 要分词的内容
	 * @return array 词语
	 */
	public function run($content)
	{
		return $this->getInstance()->run($content);
	}

	//商品标签分词
	public function goods_tags_words()
	{
		$content = IFilter::act(IReq::get('content'));
		$words   = $this->run($content);

		$result = array('result' => 'fail');

		if(isset($words['data']) && $words['data'])
		{
			$result = array(
				'result' => 'success',
				'data'   => join(",",$words['data']),
			);

		}
		die( JSON::encode($result) );
	}

	/**
	 * @brief 商品名称分词
	 * @param string $subUrl 提交地址
	 * @return string
	 */
	public function goodsEditNameWord($subUrl)
	{
		$url = IUrl::creatUrl($subUrl);

echo <<< OEF
<script type="text/javascript">
function wordsPart()
{
	var goodsName = $('input[name="name"]').val();
	if(goodsName)
	{
		$.getJSON("$url",{"content":goodsName},function(json)
		{
			if(json.result == 'success')
			{
				$('input[name="search_words"]').val(json.data);
			}
		});
	}
}

//绑定页面中的控件
$("input[name='name']").on("change",wordsPart);
</script>
OEF;
	}
}

/**
 * @brief iWebShop分词接口
 */
interface wordsPart_inter
{
	/**
	 * @brief 运行分词
	 * @param string $content 要分词的内容
	 * @return array 词语
	 */
	public function run($content);

	/**
	 * @brief 处理规范统一的结果集
	 * @param string $result 要处理的返回值
	 * @return array 返回结果 array('result' => 'success 或者 fail','data' => array('分词数据'))
	 */
	public function response($result);
}