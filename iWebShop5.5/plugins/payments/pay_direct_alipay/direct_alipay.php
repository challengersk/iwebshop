<?php
/**
 * @copyright Copyright(c) 2011 aircheng.com
 * @file direct_alipay.php
 * @brief 支付宝插件类[即时到帐]
 * @author nswe
 * @date 2011-01-27
 * @version 0.6
 * @note
 */

 /**
 * @class direct_alipay
 * @brief 支付宝插件类
 */
class direct_alipay extends paymentPlugin
{
	//支付插件名称
    public $name = '支付宝';

	/**
	 * @see paymentplugin::getSubmitUrl()
	 */
	public function getSubmitUrl()
	{
		return 'https://mapi.alipay.com/gateway.do?_input_charset=utf-8';
	}

	/**
	 * @see paymentplugin::notifyStop()
	 */
	public function notifyStop()
	{
		echo "success";
	}

	/**
	 * @see paymentplugin::callback()
	 */
	public function callback($callbackData,&$paymentId,&$money,&$message,&$orderNo)
	{
		//除去待签名参数数组中的空值和签名参数
		$para_filter = $this->paraFilter($callbackData);

		//对待签名参数数组排序
		$para_sort = $this->argSort($para_filter);

		//生成签名结果
		$mysign = $this->buildMysign($para_sort,Payment::getConfigParam($paymentId,'M_PartnerKey'));

		if(isset($callbackData['sign']) && $callbackData['sign'] == $mysign)
		{
			//回传数据
			$orderNo = $callbackData['out_trade_no'];
			$money   = $callbackData['total_fee'];

			//记录回执流水号
			if(isset($callbackData['trade_no']) && $callbackData['trade_no'])
			{
				$this->recordTradeNo($orderNo,$callbackData['trade_no']);
			}

			if($callbackData['trade_status'] == 'TRADE_FINISHED' || $callbackData['trade_status'] == 'TRADE_SUCCESS')
			{
				return true;
			}
		}
		else
		{
			$message = "签名不正确";
			throw new IException("签名不正确,参数接口回调数据：".var_export($callbackData,true));
		}
		return false;
	}

	/**
	 * @see paymentplugin::serverCallback()
	 */
	public function serverCallback($callbackData,&$paymentId,&$money,&$message,&$orderNo)
	{
		return $this->callback($callbackData,$paymentId,$money,$message,$orderNo);
	}

	/**
	 * @see paymentplugin::getSendData()
	 */
	public function getSendData($payment)
	{
		$return = array();

		//基本参数
		$return['service'] = 'create_direct_pay_by_user';
		$return['partner'] = $payment['M_PartnerId'];
		$return['seller_email'] = $payment['M_Email'];
		$return['_input_charset'] = 'utf-8';
		$return['payment_type'] = 1;
		$return['return_url'] = $this->callbackUrl;
		$return['notify_url'] = $this->serverCallbackUrl;

		//业务参数
		$return['subject'] = $payment['R_Name'];
		$return['out_trade_no'] = $payment['M_OrderNO'];
		$return['total_fee'] = number_format($payment['M_Amount'], 2, '.', '');

		//除去待签名参数数组中的空值和签名参数
		$para_filter = $this->paraFilter($return);

		//对待签名参数数组排序
		$para_sort = $this->argSort($para_filter);

		//生成签名结果
		$mysign = $this->buildMysign($para_sort, $payment['M_PartnerKey']);

		//签名结果与签名方式加入请求提交参数组中
		$return['sign'] = $mysign;
		$return['sign_type'] = 'MD5';

		return $return;
	}

	/**
	 * 除去数组中的空值和签名参数
	 * @param $para 签名参数组
	 * return 去掉空值与签名参数后的新签名参数组
	 */
	private function paraFilter($para)
	{
		$para_filter = array();
		foreach($para as $key => $val)
		{
			if($key == "sign" || $key == "sign_type" || $val == "")
			{
				continue;
			}
			else
			{
				$para_filter[$key] = $para[$key];
			}
		}
		return $para_filter;
	}

	/**
	 * 对数组排序
	 * @param $para 排序前的数组
	 * return 排序后的数组
	 */
	private function argSort($para)
	{
		ksort($para);
		reset($para);
		return $para;
	}

	/**
	 * 生成签名结果
	 * @param $sort_para 要签名的数组
	 * @param $key 支付宝交易安全校验码
	 * @param $sign_type 签名类型 默认值：MD5
	 * return 签名结果字符串
	 */
	private function buildMysign($sort_para,$key,$sign_type = "MD5")
	{
		//把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
		$prestr = $this->createLinkstring($sort_para);
		//把拼接后的字符串再与安全校验码直接连接起来
		$prestr = $prestr.$key;
		//把最终的字符串签名，获得签名结果
		$mysgin = md5($prestr);
		return $mysgin;
	}

	/**
	 * 把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
	 * @param $para 需要拼接的数组
	 * return 拼接完成以后的字符串
	 */
	private function createLinkstring($para)
	{
		$arg  = "";
		foreach($para as $key => $val)
		{
			$arg.=$key."=".$val."&";
		}

		//去掉最后一个&字符
		$arg = trim($arg,'&');

		//如果存在转义字符，那么去掉转义
		if(function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc())
		{
			$arg = stripslashes($arg);
		}

		return $arg;
	}

	/**
	 * @param 获取配置参数
	 */
	public function configParam()
	{
		$result = array(
			'M_PartnerId'  => '合作者身份（PID）',
			'M_PartnerKey' => '安全校验码（Key）',
			'M_Email'      => '登录账号',
		);
		return $result;
	}

	/**
	 * @brief 执行退款接口
	 * @param array $payment 退款信息接口
	 */
	public function doRefund($payment)
	{
        $return = array();
        //基本参数
        $return['service']        = 'refund_fastpay_by_platform_nopwd';
        $return['partner']        = $payment['M_PartnerId'];
        $return['_input_charset'] = 'utf-8';
        $return['batch_no']       = date("YmdHis")."MMM".$payment['M_RefundId'];
        $return['detail_data']    = $payment['M_TransactionId']."^".$payment['M_Refundfee']."^协商退款";
        $return['batch_num']      = 1;
        $return['seller_email']   = $payment['M_Email'];
        $return['refund_date']    = date('Y-m-d H:i:s');

        //除去待签名参数数组中的空值和签名参数
        $para_filter = $this->paraFilter($return);

        //对待签名参数数组排序
        $para_sort = $this->argSort($para_filter);

        //生成签名结果
        $mysign = $this->buildMysign($para_sort, $payment['M_PartnerKey']);

        //签名结果与签名方式加入请求提交参数组中
        $return['sign']      = $mysign;
        $return['sign_type'] = 'MD5';

        $result      = $this->curlPost($return);
        $resultArray = $this->converArray($result);
		if(is_array($resultArray))
		{
			//处理正确
			if(isset($resultArray['is_success']) && $resultArray['is_success'] == 'T')
			{
				$this->recordRefundTradeNo($payment['M_RefundId'],$return['batch_no']);
				return true;
			}
			else
			{
				die($resultArray['error']);
			}
		}
		else
		{
			die($resultArray);
		}
		return null;
	}

	/**
	 * @brief 发送post请求
	 * @param array $return 发送的数据
	 */
	public function curlPost($return)
	{
		//生产环境
		$post_url = $this->getSubmitUrl();
		$sendData = http_build_query($return);
        $ch       = curl_init();

        //设置基础设置
        curl_setopt($ch,CURLOPT_URL, $post_url);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($ch,CURLOPT_SSLVERSION, 1);
        curl_setopt($ch,CURLOPT_HTTPHEADER, array (
            'Content-type:application/x-www-form-urlencoded;charset=UTF-8'
        ) );
        //设置header
        curl_setopt($ch,CURLOPT_HEADER, FALSE);

        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, TRUE);

        //post提交方式
        curl_setopt($ch,CURLOPT_POST, TRUE);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $sendData);
        //运行curl
        $data = curl_exec($ch);

        //返回结果
        if($data)
        {
            curl_close($ch);
            return $data;
        }
        else
        {
            $error = curl_error($ch);
            curl_close($ch);
            die("curl出错，错误信息:".$error);
        }
	}

	/**
	 * @brief 从xml到array转换数据格式
	 * @param xml $xmlData
	 * @return array
	 */
	private function converArray($xmlData)
	{
		$result = array();
		$xmlHandle = xml_parser_create();
		xml_parse_into_struct($xmlHandle, $xmlData, $resultArray);
		foreach($resultArray as $key => $val)
		{
			if($val['tag'] != 'XML' && isset($val['value']))
			{
				$result[$val['tag']] = $val['value'];
			}
		}
		return array_change_key_case($result);
	}
}