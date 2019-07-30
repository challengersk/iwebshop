<?php
/**
 * @copyright (c) 2016 aircheng.com
 * @file bosonWords.php
 * @brief 玻森分词系统
 * @see http://bosonnlp.com/
 * @author nswe
 * @date 2016/12/29 23:27:03
 * @version 4.7
 */
class bosonWords extends pluginBase implements wordsPart_inter
{
	//api令牌
	private $apiToken = '';

	/**
	 * brief 构造函数
	 * @param $API_TOKEN
	 */
	public function __construct($API_TOKEN = array())
	{
		$this->apiToken = isset($API_TOKEN['API_TOKEN']) ? $API_TOKEN['API_TOKEN'] : "t3skvsZF.11903.abEqecPajvli";
	}

	public static function name()
	{
		return "玻森分词接口";
	}

	public static function description()
	{
		return "玻森数据专业关注数据分词技术！官网地址：<a href='http://bosonnlp.com' target='_blank'>http://bosonnlp.com/</a> 可以用于：(1)商品添加修改时对名称进行分词; (2)根据商品名称的分词形式进行查询";
	}

	//插件默认配置
	public static function configName()
	{
		return array(
			'API_TOKEN' => array("name" => "API_TOKEN","type" => "text","info" => "此项仅适用于boson玻森分词接口，到其官网 http://bosonnlp.com 免费申请相关业务参数"),
		);
	}

	/**
	 * @brief 获取提交按钮
	 * @return string
	 */
	public function getSubmitUrl()
	{
		return 'http://api.bosonnlp.com/tag/analysis?oov_level=1';
	}

	/**
	 * @brief 运行分词
	 * @param string $content 要分词的内容
	 * @return array 词语
	 */
	public function run($content)
	{
		$result = $this->curlSend($this->getSubmitUrl(),$content);
		return $this->response($result);
	}

	/**
	 * @brief 发送curl组建数据
	 * @param string $url 提交的api网址
	 * @param array $post 发送的接口参数
	 * @return mixed 返回的数据
	 */
	private function curlSend($url,$postData)
	{
		//获取参数配置
		$API_TOKEN = $this->apiToken;
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => $url,
			CURLOPT_HTTPHEADER => array(
				"Accept:application/json",
				"Content-Type: application/json",
				"X-Token: $API_TOKEN",
			),
			CURLOPT_TIMEOUT => 5,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode(array($postData), JSON_UNESCAPED_UNICODE),
			CURLOPT_RETURNTRANSFER => true,
		));

		$result = curl_exec($ch);
		curl_close($ch);
		return $result;
	}

	/**
	 * @brief 处理规范统一的结果集
	 * @param string $result 要处理的返回值
	 * @return array 返回结果 array('result' => 'success 或者 fail','data' => array('分词数据'))
	 */
	public function response($result)
	{
		$resultArray = JSON::decode($result);
		if(!is_array($resultArray))
		{
			return array('result' => 'fail','data' => array());
		}
		$resultArray = current($resultArray);
		if(isset($resultArray['word']) && $resultArray['word'])
		{
			$data = array();
			foreach($resultArray['word'] as $key => $val)
			{
				if(IString::getStrLen($val) >= 2)
				{
					$data[] = $val;
				}
			}
			return array('result' => 'success','data' => $data);
		}
		else
		{
			return array('result' => 'fail','data' => array());
		}
	}
}