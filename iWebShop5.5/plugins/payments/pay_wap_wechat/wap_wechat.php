<?php
/**
 * @class wap_wechat
 * @brief 移动微信支付
 * @date 2015/4/21 15:45:40
 */
include_once(dirname(__FILE__)."/../common/wechatBase.php");
class wap_wechat extends wechatBase
{
	//支付插件名称
    public $name = '微信公众号支付';

	/**
	 * @see paymentplugin::getSendData()
	 */
	public function getSendData($payment)
	{
		if(!class_exists("wechat"))
		{
			die("插件wechat不存在，无法完成微信支付");
		}

		$return = array();

		//基本参数
		$return['appid']            = $payment['appid'];
		$return['mch_id']           = $payment['mch_id'];
		$return['nonce_str']        = rand(100000,999999);
		$return['body']             = '微信支付';
		$return['out_trade_no']     = $payment['M_OrderNO'];
		$return['total_fee']        = $payment['M_Amount']*100;
		$return['spbill_create_ip'] = IClient::getIp();
		$return['notify_url']       = $this->serverCallbackUrl;
		$return['trade_type']       = 'JSAPI';
		$return['openid']           = wechat::getOpenId();

		//除去待签名参数数组中的空值和签名参数
		$para_filter = $this->paraFilter($return);

		//对待签名参数数组排序
		$para_sort = $this->argSort($para_filter);

		//生成签名结果
		$mysign = $this->buildMysign($para_sort, $payment['key']);

		//签名结果与签名方式加入请求提交参数组中
		$return['sign'] = $mysign;

		$xmlData = $this->converXML($return);
		$result  = $this->curlSubmit($xmlData);

		//进行与支付订单处理
		$resultArray = $this->converArray($result);
		if(is_array($resultArray))
		{
			//处理正确
			if(isset($resultArray['return_code']) && $resultArray['return_code'] == 'SUCCESS')
			{
				$resultArray['key']      = $payment['key'];
				$resultArray['order_no'] = $payment['M_OrderNO'];
				return $resultArray;
			}
			else
			{
				die("wap_wechat返回：".$resultArray['return_msg']);
			}
		}
		else
		{
			die("wap_wechat返回：".$result);
		}
		return null;
	}

	/**
	 * @see paymentplugin::doPay()
	 */
	public function doPay($sendData)
	{
		if(isset($sendData['prepay_id']) && $sendData['prepay_id'])
		{
			$return = array();

			//基本参数
			$return['appId']     = $sendData['appid'];
			$return['timeStamp'] = time();
			$return['nonceStr']  = rand(100000,999999);
			$return['package']   = "prepay_id=".$sendData['prepay_id'];
			$return['signType']  = "MD5";

			//除去待签名参数数组中的空值和签名参数
			$para_filter = $this->paraFilter($return);

			//对待签名参数数组排序
			$para_sort = $this->argSort($para_filter);

			//生成签名结果
			$mysign = $this->buildMysign($para_sort, $sendData['key']);

			//签名结果与签名方式加入请求提交参数组中
			$return['paySign']    = $mysign;
			$return['successUrl'] = IUrl::getHost().IUrl::creatUrl('/site/success/message/'.urlencode('支付成功！'));
			$return['failUrl']    = IUrl::getHost().IUrl::creatUrl('/errors/error/message/'.urlencode('支付失败！'));

			include(dirname(__FILE__).'/template/pay.php');
		}
		else
		{
			$message = $sendData['err_code_des'] ? $sendData['err_code_des'] : '微信下单API接口失败';
			die($message);
		}
	}

	/**
	 * @param 获取配置参数
	 */
	public function configParam()
	{
		$result = array(
			'mch_id'    => '商户号',
			'key'       => '商户支付密钥',
			'appid'     => '公众号AppID',
			'appsecret' => '公众号AppSecret',
		);
		return $result;
	}
}