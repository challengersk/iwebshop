<?php
/**
 * @copyright (c) 2015 aircheng.com
 * @file wechat.php
 * @brief 微信插件
 * @date 2018/4/10 17:34:22
 * @version 5.1
 */
class wechat extends pluginBase
{
	//微信API地址
	const SERVER_URL = "https://api.weixin.qq.com/cgi-bin";
	private static $sslConfig = array(
	    "ssl"=>array(
	        "verify_peer"=>false,
	        "verify_peer_name"=>false,
	    ),
	);

	//配置数组
	public static $config = array(
		'wechat_AppID'      => '',
		'wechat_AppSecret'  => '',
		'wechat_AutoLogin'  => '',
		'wechat_jsApiSDK'   => '',
		'wechat_tempalteMsg'=> '',
		'wechat_menu'       => '',
		'wechat_tag'        => '',
	);

	//绑定回调,对应state值和函数
	public static $bindConfig = [];

	//令牌存活时间
	private static $accessTokenTime = 5000;
	private static $jsapiTicketTime = 5000;

	//获取配置参数
	private function initConfig()
	{
		//缺少SSL组件
		if(!extension_loaded("OpenSSL"))
		{
			$this->setError("您的环境缺少OpenSSL组件，这是调用微信API所必须的");
			return false;
		}
		//获取参数配置
		$siteConfigObj = $this->config();
		if(isset($siteConfigObj['wechat_AppID']) && isset($siteConfigObj['wechat_AppSecret']))
		{
			self::$config['wechat_AppID']     = $siteConfigObj['wechat_AppID'];
			self::$config['wechat_AppSecret'] = $siteConfigObj['wechat_AppSecret'];
			self::$config['wechat_AutoLogin'] = isset($siteConfigObj['wechat_AutoLogin']) ? $siteConfigObj['wechat_AutoLogin'] : 0;
			self::$config['wechat_jsApiSDK']  = isset($siteConfigObj['wechat_jsApiSDK']) ? $siteConfigObj['wechat_jsApiSDK'] : 0;
			self::$config['wechat_tempalteMsg']  = isset($siteConfigObj['wechat_tempalteMsg']) ? $siteConfigObj['wechat_tempalteMsg'] : 0;
			self::$config['wechat_menu']         = isset($siteConfigObj['wechat_menu']) ? $siteConfigObj['wechat_menu'] : 0;
			self::$config['wechat_tag']          = isset($siteConfigObj['wechat_tag']) ? $siteConfigObj['wechat_tag'] : 0;
			return true;
		}
		else
		{
			$this->setError("微信配置信息不完全，参数【AppID】【AppSecret】必须填写完整");
			return false;
		}
	}

	/**
	 * @brief 获取access_token令牌
	 * @param boolean $fresh 是否刷新令牌
	 */
	public static function getAccessToken($fresh = false)
	{
		$cacheObj = new ICache();
		$accessTokenTime = $cacheObj->get('accessTokenTime');

		//延续使用
		if($accessTokenTime && time() - $accessTokenTime < self::$accessTokenTime && $fresh == false)
		{
			$accessToken = $cacheObj->get('accessToken');
			if($accessToken)
			{
				return $accessToken;
			}
			else
			{
				$cacheObj->del('accessTokenTime');
				return self::getAccessToken();
			}
		}
		//重新获取令牌
		else
		{
			$urlparam = array(
				'grant_type=client_credential',
				'appid='.self::$config['wechat_AppID'],
				'secret='.self::$config['wechat_AppSecret'],
			);
			$apiUrl = self::SERVER_URL."/token?".join("&",$urlparam);
			$json   = file_get_contents($apiUrl,false,stream_context_create(self::$sslConfig));
			$result = JSON::decode($json);
			if($result && isset($result['access_token']) && isset($result['expires_in']))
			{
				$cacheObj->set('accessTokenTime',time());
				$cacheObj->set('accessToken',$result['access_token']);
				return $result['access_token'];
			}
			else
			{
				die("获取accessToken异常：".$json);
			}
		}
	}

	//获取openid
	public static function getOpenId()
	{
		return ICookie::get('wechat_openid');
	}

	//设置openid
	public static function setOpenId($openid)
	{
		ICookie::set('wechat_openid',$openid);
	}

    //oauth登录接口处理
    public function wechatOauth()
    {
    	$code  = IReq::get('code');
    	$state = IReq::get('state');

    	//oauth回调处理
    	if($code)
    	{
			$result = $this->getOauthAccessToken($code);
			if($result && $state)
			{
			    //以下划线为分隔自定义绑定参数
			    if(stripos($state,"_") !== false)
			    {
			        $infoArray = explode("_",$state);
			        $methodKey = $infoArray[0];
			        $param     = $infoArray[1];
			    }
			    else
			    {
			        $methodKey = $state;
			        $param     = '';
			    }

        		if(!isset($result['openid']))
        		{
        			throw new IException("未获取到用户的OPENID数据");
        		}

        		//保存openid为其他wechat应用使用
        		$this->setOpenId($result['openid']);

			    $fun = isset(self::$bindConfig[$methodKey]) ? self::$bindConfig[$methodKey] : null;
			    if($result['scope'] == 'snsapi_userinfo' && $fun && is_callable($fun))
			    {
			        call_user_func($fun,$result,$param);
			    }

				$callbackUrl = ICrypt::simpleDecode(IReq::get('callback'));
				header('location: '.$callbackUrl);
			}
    	}
    }

	/**
	 * @brief 提交信息
	 * @param string $submitUrl 提交的URL
	 * @param array $postData 提交数据
	 * @return string 返回的结果字符串
	 */
    public static function submit($submitUrl,$postData = null)
    {
		//提交菜单
		$curl = curl_init($submitUrl);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);//SSL证书认证
		curl_setopt($curl, CURLOPT_HEADER, 0); // 过滤HTTP头
		curl_setopt($curl,CURLOPT_RETURNTRANSFER, 1);// 显示输出结果
		curl_setopt($curl,CURLOPT_POST,true); // post传输数据
		curl_setopt($curl,CURLOPT_POSTFIELDS,$postData);// post传输数据
		$responseText = curl_exec($curl);
		if($responseText == false)
		{
			throw new IException(curl_error($curl));
		}
		curl_close($curl);
		return JSON::decode($responseText);
    }

	/**
	 * @brief 根据code获取oauth登录令牌和openid
	 * @param string $code
	 */
	private function getOauthAccessToken($code)
	{
		if(!IValidate::check("^[\w\-]+$",$code))
		{
			die("CODE码非法");
		}

		$urlparam = array(
			'appid='.self::$config['wechat_AppID'],
			'secret='.self::$config['wechat_AppSecret'],
			'code='.$code,
			'grant_type=authorization_code',
		);
		$apiUrl = "https://api.weixin.qq.com/sns/oauth2/access_token?".join("&",$urlparam);
		$json   = file_get_contents($apiUrl,false,stream_context_create(self::$sslConfig));
		$result = JSON::decode($json);
		if(!isset($result['openid']))
		{
			//code重复问题
			if(isset($result['errmsg']) && stripos($result['errmsg'],'invalid code') !== false || stripos($result['errmsg'],'code been used') !== false)
			{
				$callback = ICrypt::simpleDecode(IReq::get('callback'));
				$url      = $this->oauthUrl($callback);
				header('location: '.$url);
				return;
			}
			die("根据code【".$code."】值未获取到open_id码:{$json}");
		}
		return $result;
	}

	/**
	 * @brief 获取用户的基本信息
	 * @param array $userData
	 * {
			"access_token": "****",
			"expires_in": 7200,
			"refresh_token": "****",
			"openid": "****",
			"scope": "snsapi_userinfo"
	 * }
	 * @return array
	 */
	public function getUserInfo($oauthAccess)
	{
		$openid = $oauthAccess['openid'];
		$scope  = $oauthAccess['scope'];

		//根据不同的授权类型运行不同的接口
		if($scope == 'snsapi_userinfo')
		{
			$urlparam    = array(
				'access_token='.$oauthAccess['access_token'],
				'openid='.$openid,
			);
			$apiUrl = "https://api.weixin.qq.com/sns/userinfo?";
		}
		else
		{
			$urlparam    = array(
				'access_token='.self::getAccessToken(),
				'openid='.$openid,
			);
			$apiUrl = self::SERVER_URL."/user/info?";
		}

		$apiUrl .= join("&",$urlparam);
		$json    = file_get_contents($apiUrl,false,stream_context_create(self::$sslConfig));
		if(strpos($json,"access_token is invalid"))
		{
			die('access_token错误请退出重试');
		}
		return JSON::decode($json);
	}

	/**
	 * @brief 转换URL
	 * @param string $url 跳转的URL参数
	 */
	public static function converUrl($url)
	{
		//preg_replace_callback 回调的是数组参数
		if(is_array($url))
		{
			foreach($url as $key => $val)
			{
				return self::converUrl($val);
			}
		}
		else
		{
			//伪静态路径
			if(strpos($url,"/") === 0)
			{
				return IUrl::getHost().IUrl::creatUrl($url);
			}
			return $url;
		}
	}

	/**
	 * @brief 获取oauth登录的回调
	 * @param $callback string 回调url地址
	 */
	public static function getOauthCallback($callback = '')
	{
		return IUrl::getHost().IUrl::creatUrl("/block/wechatOauth/callback/".ICrypt::simpleEncode($callback));
	}

	/**
	 * @brief 绑定微信账号到用户系统
	 * @param array $oauthAccess
	 * {
			"access_token": "****",
			"expires_in": 7200,
			"refresh_token": "****",
			"openid": "****",
			"scope": "snsapi_userinfo"
	 * }
	 */
	public function bindUser($oauthAccess)
	{
		//获取微信用户信息
		$wechatUser = $this->getUserInfo($oauthAccess);
		if(isset($wechatUser['errmsg']))
		{
			throw new IException("获取用户信息失败！".$wechatUser['errmsg']);
		}

		/**
		 * 获取个人信息结果(昵称，头像，性别)
		 * 如果是"snsapi_base"模式且必须关注才可以获取完整信息；
		 * 如果是"snsapi_userinfo"模式可以直接全部获取
		 */
		$unId = $oauthAccess['openid'];

		//当公众号和开发平台有多个应用会存在此 unionid,此时需要开放这里,可以让微信公众账号和微信开放平台同步用户信息
		$oauthUserDB = new IModel('oauth_user');
		$oldOauthUser= $oauthUserDB->getObj('oauth_user_id = "'.$unId.'" and oauth_id = 5');
		if($oldOauthUser && isset($wechatUser['unionid']) && $wechatUser['unionid'])
		{
			$oauthUserDB->setData(array('oauth_user_id' => $wechatUser['unionid'],'openid' => $oauthAccess['openid']));
			$oauthUserDB->update('id = '.$oldOauthUser['id']);
		}
		$unId = isset($wechatUser['unionid']) && $wechatUser['unionid'] ? $wechatUser['unionid'] : $unId;

		$username = substr($oauthAccess['openid'],-8);
		if(isset($wechatUser['nickname']))
		{
			//有个别微信用户头像是二进制图片，需要过滤掉
			$wechatName= trim(preg_replace('/[\x{10000}-\x{10FFFF}]/u',"",$wechatUser['nickname']));
			$username  = $wechatName ? IFilter::act($wechatName) : $username;
		}
		$sex        = isset($wechatUser['sex'])        ? $wechatUser['sex']        : "";
		$ico        = isset($wechatUser['headimgurl']) ? $wechatUser['headimgurl'] : "";

		//检查用户信息
		$tempDB   = new IModel('oauth_user as ou,user as u');
		$oauthRow = $tempDB->getObj("ou.oauth_user_id = '".$unId."' and ou.oauth_id = 5 and ou.user_id = u.id");

		if($oauthRow)
		{
			$user_id   = $oauthRow['user_id'];
			$userDB    = new IModel('user');

			//更新user表
			$userDB->setData(array(
				'head_ico' => $ico,
			));
			$userDB->update($user_id);

			//更新member表
			$memberDB = new IModel('member');
			$memberDB->setData(array(
				'sex' => $sex,
			));
			$memberDB->update('user_id = '.$user_id);

			//信息不存在的时候更新openid
			if(!$oauthRow['openid'])
			{
    			$oauthUserDB->setData(array('openid' => $oauthAccess['openid']));
                $oauthUserDB->update("oauth_user_id = '".$oauthRow['oauth_user_id']."'");
			}
		}
		else
		{
			$userDB    = new IModel('user');
	    	$userCount = $userDB->getObj("username = '{$username}' ",'count(*) as num');

	    	//没有重复的用户名
	    	if($userCount['num'] == 0)
	    	{

	    	}
	    	else
	    	{
	    		//随即分配一个用户名
	    		$username = $username.rand(1000,9999);
	    	}

			//插入user表
			$userDB->setData(array(
				'username' => $username,
				'password' => md5(time()),
				'head_ico' => $ico,
			));
			$user_id = $userDB->add();

			//插入member表
			$memberDB = new IModel('member');
			$memberDB->setData(array(
				'user_id' => $user_id,
				'time'    => ITime::getDateTime(),
				'sex'     => $sex,
			));
			$memberDB->add();

			//插入oauth_user关系表
			$oauthUserDB = new IModel('oauth_user');
			$oauthUserDB->del("oauth_user_id = '".$unId."'");
			$oauthUserData = array(
				'oauth_user_id' => $unId,
				'oauth_id'      => 5,
				'user_id'       => $user_id,
				'datetime'      => ITime::getDateTime(),
				'openid'        => $oauthAccess['openid'],
			);
			$oauthUserDB->setData($oauthUserData);
			$oauthUserDB->add();

			//通知事件用户注册完毕
			$userArray = $userDB->getObj('id = '.$user_id);
			plugin::trigger("userRegFinish",$userArray);
		}

		$this->login($unId);
	}

	/**
	 * @brief 登录用户系统
	 * @param string $unId 唯一ID标识
	 */
	public function login($unId)
	{
		$oauthUserDB = new IModel('oauth_user');
		$oauthRow = $oauthUserDB->getObj("oauth_user_id = '".$unId."' and oauth_id = 5");
		$userRow  = array();
		if($oauthRow)
		{
			$userDB = new IModel('user');
			$userRow = $userDB->getObj('id = '.$oauthRow['user_id']);
		}

		if(!$userRow)
		{
			$oauthUserDB->del("oauth_user_id = '".$unId."' and oauth_id = 5");
			die('无法获取微信用户与商城的绑定信息，请重新关注公众账号');
		}

		$user = plugin::trigger("isValidUser",array($userRow['username'],$userRow['password']));
		if($user)
		{
			plugin::trigger("userLoginCallback",$user);
		}
		else
		{
			die('<h1>该用户'.$userRow['username'].'被移至回收站内无法进行登录</h1>');
		}
	}

	/**
	 * @breif oauth路径处理
	 * @param string $url 网址路径
	 * @param string $snsType 登录授权方式：snsapi_base (不弹出授权页面，直接跳转，只能获取用户openid), snsapi_userinfo (弹出授权页面，可通过openid拿到昵称、性别、所在地。并且，即使在未关注的情况下，只要用户授权，也能获取其信息)
	 * @return string 处理后oauth的URL
	 */
	public static function oauthUrl($url,$snsType = "snsapi_userinfo")
	{
		$url = self::converUrl($url);
		$urlparam = array(
			'appid='.self::$config['wechat_AppID'],
			'redirect_uri='.urlencode(self::getOauthCallback($url)),
			'response_type=code',
			'scope='.$snsType,
			'state=user',
			'connect_redirect=1',
		);
		$apiUrl = "https://open.weixin.qq.com/connect/oauth2/authorize?".join("&",$urlparam)."#wechat_redirect";
		return $apiUrl;
	}

	/**
	 * @brief 获取jsapi_ticket令牌
	 * @param $fresh 是否刷新令牌
	 */
    public function jsapiTicket($fresh = false)
    {
		$cacheObj = new ICache();
		$jsapiTicketTime = $cacheObj->get('jsapiTicketTime');

		//延续使用
		if($jsapiTicketTime && time() - $jsapiTicketTime < self::$jsapiTicketTime && $fresh == false)
		{
			$jsapiTicket = $cacheObj->get('jsapiTicket');
			if($jsapiTicket)
			{
				return $jsapiTicket;
			}
			else
			{
				$cacheObj->del('jsapiTicketTime');
				return $this->jsapiTicket();
			}
		}
		//重新获取令牌
		else
		{
			$accessToken = self::getAccessToken();
			$urlparam = array(
				'type=jsapi',
				'access_token='.$accessToken,
			);
			$apiUrl = self::SERVER_URL."/ticket/getticket?".join("&",$urlparam);
			$json   = file_get_contents($apiUrl,false,stream_context_create(self::$sslConfig));
			$result = JSON::decode($json);
			if($result && isset($result['ticket']) && isset($result['expires_in']))
			{
				$cacheObj->set('jsapiTicketTime',time());
				$cacheObj->set('jsapiTicket',$result['ticket']);
				return $result['ticket'];
			}
			else
			{
				die($json);
			}
		}
    }

	/**
	 * @brief jsApi签名字符串
	 * @param $noncestr 随机字符串
	 * @param $time 时间
	 * @return 返回字符串签名
	 */
    public function jsApiSignature($noncestr,$time)
    {
    	$jsapi_ticket = $this->jsapiTicket();
    	$url          = IUrl::getHost().IUrl::getUri();
    	$tmpArr       = array(
			"noncestr=".$noncestr,
			"jsapi_ticket=".$jsapi_ticket,
			"timestamp=".$time,
			"url=".$url,
    	);
		sort($tmpArr,SORT_STRING);
		$tmpStr = sha1(join("&",$tmpArr));
		return $tmpStr;
    }

	/**
	 * @brief 是否调用jsApiSDK
	 * @see http://mp.weixin.qq.com/wiki/7/aaa137b55fb2e0456bf8dd9148dd613f.html
	 * @notice 因为加载weixin.js是在模板结束处，所以模板里面要调用js-sdk需要写到jQuery(function(){...})里面
	 *         才可以在页面完全加载后在依次调用而不会导致wx对象不存在的情况
	 */
    public function jsApiSDK()
    {
    	if(self::$config['wechat_jsApiSDK'] == 0)
    	{
			return;
    	}

		$appID    = self::$config['wechat_AppID'];
		$noncestr = rand(1000000,9999999);
		$time     = time();
		$signature= $this->jsApiSignature($noncestr,$time);

echo <<< OEF
<script type="text/javascript" src="//res.wx.qq.com/open/js/jweixin-1.3.2.js"></script>
<script type="text/javascript">
wx.config({
	debug: false, // 开启调试模式,调用的所有api的返回值会在客户端alert出来，若要查看传入的参数，可以在pc端打开，参数信息会通过log打出，仅在pc端时才会打印。
	appId: "{$appID}", // 必填，公众号的唯一标识
	timestamp: $time, // 必填，生成签名的时间戳
	nonceStr: "{$noncestr}", // 必填，生成签名的随机串
	signature: "{$signature}",// 必填，签名，见附录1
	jsApiList: [
        'checkJsApi',
        'onMenuShareTimeline',
        'onMenuShareAppMessage',
        'onMenuShareQQ',
        'onMenuShareWeibo',
        'onMenuShareQZone',
        'hideMenuItems',
        'showMenuItems',
        'hideAllNonBaseMenuItem',
        'showAllNonBaseMenuItem',
        'translateVoice',
        'startRecord',
        'stopRecord',
        'onVoiceRecordEnd',
        'playVoice',
        'onVoicePlayEnd',
        'pauseVoice',
        'stopVoice',
        'uploadVoice',
        'downloadVoice',
        'chooseImage',
        'previewImage',
        'uploadImage',
        'downloadImage',
        'getNetworkType',
        'openLocation',
        'getLocation',
        'hideOptionMenu',
        'showOptionMenu',
        'closeWindow',
        'scanQRCode',
        'chooseWXPay',
        'openProductSpecificView',
        'addCard',
        'chooseCard',
        'openCard'
	] // 必填，需要使用的JS接口列表，所有JS接口列表见附录2
});
</script>
OEF;

	//获取各项参数
	$shareUrl = plugin::trigger('getCommissionUrl');
	$title    = "";
	$img      = "";
	$desc     = "";
	if(class_exists("seo"))
	{
    	$title = seo::$data['name'];
    	$desc  = seo::$data['description'];

    	if(stripos(seo::$data['img'],"http") === 0)
    	{
    	    $img = seo::$data['img'];
    	}
    	else
    	{
    	    $img = IUrl::getHost().IUrl::creatUrl(seo::$data['img']);
    	}
	}

echo <<< OEF
<script type="text/javascript">
//对分享功能URL进行参数添加
wx.ready(function()
{
	//分享到朋友圈
	wx.onMenuShareTimeline({
		title:"{$title}",
		link:"{$shareUrl}",
		imgUrl:"{$img}",
		success:function(){},
		cancel:function(){},
	});

	//分享给朋友
	wx.onMenuShareAppMessage({
		title:"{$title}",
		link:"{$shareUrl}",
		desc:"{$desc}",
		imgUrl:"{$img}",
		success:function(){},
		cancel:function(){},
	});

	//分享到QQ
	wx.onMenuShareQQ({
		title:"{$title}",
		link:"{$shareUrl}",
		desc:"{$desc}",
		imgUrl:"{$img}",
		success:function(){},
		cancel:function(){},
	});

	//分享到腾讯微博
	wx.onMenuShareWeibo({
		title:"{$title}",
		link:"{$shareUrl}",
		desc:"{$desc}",
		imgUrl:"{$img}",
		success:function(){},
		cancel:function(){},
	});

	//分享到QQ空间
	wx.onMenuShareQZone({
		title:"{$title}",
		link:"{$shareUrl}",
		desc:"{$desc}",
		imgUrl:"{$img}",
		success:function(){},
		cancel:function(){},
	});
});
</script>
OEF;
    }

	//插件注册
	public function reg()
	{
		if(IReq::get('_from') == 'miniProgram')
		{
			return;
		}

		if($this->initConfig() == false)
		{
			throw new IException($this->getError());
		}

		if(IClient::isWechat() == true)
		{
			plugin::reg("onFinishView",$this,"oauthLogin");
			plugin::reg("onFinishView",$this,"jsApiSDK");

    		plugin::reg("onBeforeCreateAction@block@wechatOauth",function(){
    			self::controller()->wechatOauth = function(){$this->wechatOauth();};
    		});

            //绑定微信用户回调
    		self::$bindConfig['user'] = function($result)
    		{
    		    $this->bindUser($result);
    		};
		}

		//微信自定义菜单开启
		if(self::$config['wechat_menu'] == 1)
		{
    		$wechatMenu = new wechatMenu();
    		$wechatMenu->reg();
		}

		//微信用户标签开启
		if(self::$config['wechat_tag'] == 1)
		{
    		$wechatTag = new wechatTag();
    		$wechatTag->reg();
		}

		//模板消息功能开启
		if(self::$config['wechat_tempalteMsg'] == 1)
		{
    		$templateMessage = new templateMessage();
    		$templateMessage->reg();
		}
	}

	//插件名称
	public static function name()
	{
		return "微信插件";
	}

	//插件描述
	public static function description()
	{
		return "微信免登录免注册，微信支付，js-sdk对接，菜单和用户标签自定义等";
	}

	//插件默认配置
	public static function configName()
	{
		return array(
			'wechat_AppID'      => array("name" => "AppID","type" => "text","pattern" => "required"),
			'wechat_AppSecret'  => array("name" => "AppSecret","type" => "text","pattern" => "required"),
			'wechat_AutoLogin'  => array("name" => "免注册登录","type" => "select","value" => array("关闭" => 0, "开启" => 1)),
			'wechat_jsApiSDK'   => array("name" => "微信JS-SDK","type" => "select","value" => array("关闭" => 0, "开启" => 1)),
			'wechat_tempalteMsg'=> array("name" => "微信模板消息","type" => "select","value" => array("关闭" => 0, "开启" => 1)),
			'wechat_menu'       => array("name" => "微信自定义菜单","type" => "select","value" => array("关闭" => 0, "开启" => 1)),
			'wechat_tag'        => array("name" => "微信用户标签","type" => "select","value" => array("关闭" => 0, "开启" => 1)),
		);
	}

	/**
	 * @brief 进行oauth静默登录
	 */
	public function oauthLogin()
	{
		$openid = $this->getOpenId();
		if(!$openid || (self::$config['wechat_AutoLogin'] == 1 && plugin::trigger('getUser') == null) )
		{
			//oauth地址获取openid可以支付
			$type= self::$config['wechat_AutoLogin'] == 1 ? 'snsapi_userinfo' : 'snsapi_base';
			$url = $this->oauthUrl(IUrl::getUrl(),$type);

echo <<< OEF
<script type="text/javascript" src="//res.wx.qq.com/open/js/jweixin-1.3.2.js"></script>
<script>
wx.miniProgram.getEnv(function(res)
{
    if(res.miniprogram != true)
    {
        window.location.href = '{$url}';
    }
})
</script>
OEF;
		}
	}

    //安装过程
	public static function install()
	{
		//商户openid关系表
	    $sellerOpenidRelationDB = new IModel('seller_openid_relation');
	    if(!$sellerOpenidRelationDB->exists())
	    {
	        $data = array(
	            'comment' => '用户的openid关系表',
	            'column'  => array(
	                'seller_id' => array("type" => "int(11) unsigned","comment" => "商家ID"),
	                'openid' => array("type" => "varchar(80)","comment" => "微信openid"),
	                'datetime' => array("type" => "datetime","comment" => "绑定时间"),
	            ),
	            'index' => array("primary" => "seller_id"),
	        );
    	    $sellerOpenidRelationDB->setData($data);
    		unset($data);
    		$sellerOpenidRelationDB->createTable();
	    }

		//模板消息表
	    $wechatTemplateMsgDB = new IModel('wechat_template_message');
	    if(!$wechatTemplateMsgDB->exists())
	    {
	        $data = array(
	            'comment' => '模板消息ID关系表',
	            'column'  => array(
	                'short_id' => array("type" => "varchar(120)","comment" => "模板短ID"),
	                'template_id' => array("type" => "varchar(120)","comment" => "模板长ID"),
	            ),
	            'index' => array("primary" => "short_id"),
	        );
    	    $wechatTemplateMsgDB->setData($data);
    		unset($data);
    		$wechatTemplateMsgDB->createTable();
	    }

	    return true;
	}

	//卸载过程
	public static function uninstall()
	{
		$sellerOpenidRelationDB = new IModel('seller_openid_relation');
		$sellerOpenidRelationDB->dropTable();

		$wechatTemplateMsgDB = new IModel('wechat_template_message');
		$wechatTemplateMsgDB->dropTable();
		return true;
	}

    //获取用户openid
	public static function getOpenidByUser($user_id)
	{
	    $db = new IModel('oauth_user');
	    $userRow = $db->getObj('user_id = '.$user_id);
	    if($userRow)
	    {
	        return $userRow['openid'] ? $userRow['openid'] : $userRow['oauth_user_id'];
	    }
	    return '';
	}

    //获取商家openid
	public static function getOpenidBySeller($seller_id,$cols = 'openid')
	{
	    $db = new IModel('seller_openid_relation');
	    $sellerRow = $db->getObj('seller_id = '.$seller_id);
	    if($sellerRow && isset($sellerRow[$cols]))
	    {
	        return $sellerRow[$cols];
	    }
	    return '';
	}
}

/**
 * @copyright (c) 2015 aircheng.com
 * @file templateMessage.php
 * @brief 微信模板消息
 * @date 2018/11/22 0:39:06
 * @version 5.3
 */
class templateMessage extends pluginBase
{
    public $_id = 'wechat';

    //绑定商家操作【动作】
	private function bindSellerAct($oauthAccess,$seller_id)
	{
	    $db = new IModel('seller_openid_relation');
	    $db->setData(array('seller_id' => $seller_id,'openid' => $oauthAccess['openid'],'datetime' => ITime::getDateTime()));
        return $db->replace();
	}

    //绑定商家界面视图【视图】(管理员和商家共用)
	public function bindSeller()
	{
	    self::controller()->bindSeller = function()
	    {
            $seller_id = self::controller()->seller['seller_id'];
            $seller_id = $seller_id ? $seller_id : 0;

            $url = str_replace("state=user","state=seller_".$seller_id,wechat::oauthUrl('/site/success/message/微信绑定成功',"snsapi_base"));

            $img = "https://api.qrserver.com/v1/create-qr-code/?data=".urlencode($url);
            $this->view('bindSeller',array('img' => $img));

            $ajaxUrl = IUrl::creatUrl(self::controller()->getId().'/sellerOpenid');
            $datetime = wechat::getOpenidBySeller($seller_id,'datetime');
echo <<< OEF
<script>
    datetime = "{$datetime}";
    window.setInterval(function(){
        $.getJSON("{$ajaxUrl}",function(json)
        {
            if(json.datetime && json.datetime != datetime)
            {
                art.dialog.close();
                art.dialog.tips('恭喜您!绑定成功',4);
            }
        });
    },3000);
</script>
OEF;
        };
	}

    /**
     * @brief 根据短ID获取模板ID
     * @param string $shortId 模板短ID
     * @return string template_id模板消息ID
     */
	private function templateId($shortId)
	{
	    $templateDB  = new IModel('wechat_template_message');
	    $templateRow = $templateDB->getObj('short_id = "'.$shortId.'"');
	    if(!$templateRow)
	    {
    		$access_token = wechat::getAccessToken();
    		$sendData     = array(
    			'template_id_short' => $shortId
    		);

    		$postUrl = wechat::SERVER_URL."/template/api_add_template?access_token=".$access_token;
    		try
    		{
    			$result = wechat::submit($postUrl,JSON::encode($sendData));
    			if($result && isset($result['template_id']))
    			{
    			    $templateRow = array('short_id' => $shortId,'template_id' => $result['template_id']);
    			    $templateDB->setData($templateRow);
    			    $templateDB->replace();
    			}
    		}
    		catch(Exception $e){}
	    }
	    return isset($templateRow['template_id']) ? $templateRow['template_id'] : "";
	}

	/**
	 * @brief 发送消息通知模板
	 * @param $openid      收件人openid
	 * @param $templateid  模板消息ID
	 * @param $data        消息数据
	 * @param $url         跳转的URL地址
	 */
	public function send($openid,$templateid,$data,$url = '')
	{
	    $url          = $url ? IUrl::getHost().IUrl::creatUrl($url) : "";
		$access_token = wechat::getAccessToken();
		$sendData     = array(
			'touser'      => $openid,
			'template_id' => $templateid,
			'data'        => $data,
			'url'         => $url,
		);

		$postUrl = wechat::SERVER_URL."/message/template/send?access_token=".$access_token;
		try
		{
			return wechat::submit($postUrl,JSON::encode($sendData));
		}
		catch(Exception $e){}
	}

    //插件注册消息事件
    public function reg()
    {
    	//后台管理菜单
    	plugin::reg("onSystemMenuCreate",function(){
    		$link = "/plugins/bindSeller";
    		$link = "javascript:art.dialog.open('".IUrl::creatUrl($link)."',{title:'绑定微信',id:'bind_wechat'});";
    		Menu::$menu["插件"]["插件管理"][$link] = "绑定微信";
    	});

    	//商家管理菜单
    	plugin::reg("onSellerMenuCreate",function(){
    		$link = "/seller/bindSeller";
    		$link = "javascript:art.dialog.open('".IUrl::creatUrl($link)."',{title:'绑定微信',id:'bind_wechat'});";
    		menuSeller::$menu["配置模块"][$link] = "绑定微信";
    	});

        //绑定微信二维码视图
    	plugin::reg("onBeforeCreateAction@plugins@bindSeller,onBeforeCreateAction@seller@bindSeller",$this,"bindSeller");

        //获取商家关联openid
    	plugin::reg("onBeforeCreateAction@plugins@sellerOpenid,onBeforeCreateAction@seller@sellerOpenid",function(){
    	    self::controller()->sellerOpenid = function(){
    		    $seller_id = self::controller()->seller['seller_id'];
    		    $seller_id = $seller_id ? $seller_id : 0;

    		    $db   = new IModel('seller_openid_relation');
    		    $data = $db->getObj('seller_id = '.$seller_id);
    		    echo JSON::encode($data);
    	    };
    	});

        //绑定微信用户回调
		wechat::$bindConfig['seller'] = function($result,$param)
		{
	        $seller_id = IFilter::act($param,'int');
	        $this->bindSellerAct($result,$seller_id);
		};

        //商家状态更新通知
        plugin::reg('updateSellerStatus',$this,"updateSellerStatus");

        //商家注册成功
        plugin::reg('sellerRegFinish',$this,"sellerRegFinish");

        //订单付款
        plugin::reg("orderPayFinish",$this,"orderPayFinish");

        //退款申请
        plugin::reg("refundsApplyFinish",$this,"refundsApplyFinish");

        //退款同意
        plugin::reg("refundFinish",$this,"refundFinish");

        //退款拒绝
        plugin::reg("refundDocUpdate",$this,"refundDocUpdate");

        //换货申请
        plugin::reg("exchangeApplyFinish",$this,"exchangeApplyFinish");

        //换货同意或者拒绝
        plugin::reg("exchangeDocUpdate",$this,"exchangeDocUpdate");

        //维修申请
        plugin::reg("fixApplyFinish",$this,"fixApplyFinish");

        //维修同意或者拒绝
        plugin::reg("fixDocUpdate",$this,"fixDocUpdate");

        //发货实体出库
		plugin::reg("orderSendDeliveryFinish",$this,"orderSendDeliveryFinish");

        //核销服务消费码
        plugin::reg("checkOrderCodeFinish",$this,"checkOrderCodeFinish");

        //提现申请
        plugin::reg("withdrawApplyFinish",$this,"withdrawApplyFinish");

        //提现结果更新
        plugin::reg("withdrawStatusUpdate",$this,"withdrawStatusUpdate");

        //在线充值
        plugin::reg("onlineRechargeFinish",$this,"onlineRechargeFinish");
    }

    /*******************************以下为事件处理*************************************/
    /**
     * @brief 商家状态更新[商家接受]
     * @param int $seller_id 商家ID
     */
    public function updateSellerStatus($seller_id)
    {
        $sellerDB  = new IModel('seller');
        $sellerRow = $sellerDB->getObj($seller_id);

        //锁定
        if($sellerRow['is_lock'] == 1)
        {
            $remark = "您的商家账号被封，请尽快联系平台管理员查明原因";
        }
        //正常
        else
        {
            $remark = "恭喜您审核通过成为我们的商家，请严格遵守平台规定";
        }

        $templateid = $this->templateId('OPENTM416102355');
        $openid = wechat::getOpenidBySeller($seller_id);
        $data   = array(
            "first"    => array("value" => "您的商家账号状态更新","color" => "#173177"),
            "keyword1" => array("value" => $sellerRow['true_name']),
            "keyword2" => array("value" => ITime::getDateTime()),
            "remark"   => array("value" => $remark),
        );
        $this->send($openid,$templateid,$data,"site/index");
    }

    /**
     * @brief 商家注册[管理员接受]
     * @param int $seller_id 商家ID
     */
    public function sellerRegFinish($seller_id)
    {
        $sellerDB  = new IModel('seller');
        $sellerRow = $sellerDB->getObj($seller_id);
        if($sellerRow)
        {
            $templateid = $this->templateId('OPENTM416747945');
            $openid = wechat::getOpenidBySeller(0);
            $data   = array(
                "first"    => array("value" => "您有新的商家入驻申请","color" => "#173177"),
                "keyword1" => array("value" => $sellerRow['seller_name']),
                "keyword2" => array("value" => $sellerRow['true_name']),
                "keyword3" => array("value" => $sellerRow['true_name']),
                "keyword4" => array("value" => $sellerRow['mobile']." , ".$sellerRow['phone']),
                "keyword5" => array("value" => ITime::getDateTime()),
                "remark"   => array("value" => "请尽快登录管理员后台进行相关资质审核"),
            );
            $this->send($openid,$templateid,$data,"member/seller_list");
        }
    }

    /**
     * @brief【模板消息】付款完成[用户、商家接受]
     * @param array $orderRow 订单信息
     */
	public function orderPayFinish($orderRow)
	{
	    //验证码发送
	    if($orderRow['takeself'] > 0)
	    {
	        $takeselfDB = new IModel('takeself');
	        $takeselfRow = $takeselfDB->getObj($orderRow['takeself']);
	        if($takeselfRow)
	        {
    	        $templateid = $this->templateId('OPENTM411229705');
                $openid = wechat::getOpenidByUser($orderRow['user_id']);
                $data   = array(
                    "first"    => array("value" => "购买成功！请到自提点领取","color" => "#173177"),
                    "keyword1" => array("value" => $orderRow['order_no']),
                    "keyword2" => array("value" => $takeselfRow['name']),
                    "keyword3" => array("value" => $takeselfRow['address']),
                    "keyword4" => array("value" => $takeselfRow['mobile'].",".$takeselfRow['phone']),
                    "remark"   => array("value" => "妥善保管验证码：".$orderRow['checkcode']),
                );
                $this->send($openid,$templateid,$data,"ucenter/order_detail/id/".$orderRow['id']);
	        }
	    }
	    //服务类型消费码
	    else if($orderRow['goods_type'] == 'code')
	    {
	        //获取消费码
	        $codeArray = array();
	        $goodsCodeRelationObj = new IModel('order_code_relation');
	        $codeList = $goodsCodeRelationObj->query('order_id = '.$orderRow['id']);
	        foreach($codeList as $codeItem)
	        {
	            $codeArray[] = $codeItem['code'];
	        }

	        //获取商品信息
	        $goodsRow = Api::run('getGoodsInfo',array('id' => $codeItem['goods_id']));

	        $templateid = $this->templateId('OPENTM401536801');
	        $openid = wechat::getOpenidByUser($orderRow['user_id']);
	        $data   = array(
                "first"    => array("value" => "购买成功！凭消费码到店服务","color" => "#173177"),
                "keyword1" => array("value" => $goodsRow['name']),
                "keyword2" => array("value" => $orderRow['order_amount']),
                "keyword3" => array("value" => join(",",$codeArray)),
                "remark"   => array("value" => "妥善保管不要泄露"),
	        );
            $this->send($openid,$templateid,$data,"ucenter/order_detail/id/".$orderRow['id']);
	    }
	    //实体类型
	    else if($orderRow['goods_type'] == 'default')
	    {
	        $templateid = $this->templateId('TM00015');
    	    $goodsInfo  = array();
    	    $goodsList  = Api::run('getOrderGoodsRowByOrderId',array('id' => $orderRow['id']));
    	    foreach($goodsList as $item)
    	    {
    	        $goodsTemp = JSON::decode($item['goods_array']);
    	        if($goodsTemp['value'])
    	        {
    	            $goodsInfo[] = $goodsTemp['name']."(".$goodsTemp['value'].")";
    	        }
    	        else
    	        {
    	            $goodsInfo[] = $goodsTemp['name'];
    	        }
    	    }
    	    $goodsInfo = join(",",$goodsInfo);

    	    //给用户发消息
    	    $openid = wechat::getOpenidByUser($orderRow['user_id']);
    	    $data   = array(
                "first"    => array("value" => "订单付款成功！","color" => "#173177"),
                "orderMoneySum" => array("value" => $orderRow['order_amount']),
                "orderProductName" => array("value" => $goodsInfo),
                "Remark"   => array("value" => "我们会尽快处理，请稍后"),
    	    );
    	    $this->send($openid,$templateid,$data,"ucenter/order_detail/id/".$orderRow['id']);

    	    //给商家发消息
    	    $openid = wechat::getOpenidBySeller($orderRow['seller_id']);
    	    $data   = array(
                "first"    => array("value" => "您有新的订单","color" => "#173177"),
                "orderMoneySum" => array("value" => $orderRow['order_amount']),
                "orderProductName" => array("value" => $goodsInfo),
                "Remark"   => array("value" => "登录后台尽快进行订单处理"),
    	    );
    	    $url = $orderRow['seller_id'] == 0 ? "order/order_list" : "seller/order_list";
    	    $this->send($openid,$templateid,$data,$url);
	    }
	}

    /**
     * @brief 【模板消息】退款申请[用户、商家接受]
     * @param int $id 申请退款单ID
     */
	public function refundsApplyFinish($id)
	{
	    $templateid = $this->templateId('TM00431');

	    $refundDB = new IModel('refundment_doc');
	    $refundRow= $refundDB->getObj($id);

        $orderAmount= 0;
	    $goodsInfo  = array();
	    $goodsList  = Api::run('getOrderGoodsRowById',array('id' => $refundRow['order_goods_id']));
	    foreach($goodsList as $item)
	    {
	        $goodsSpec    = JSON::decode($item['goods_array']);
	        $orderAmount += $item['real_price']*$item['goods_nums'];
	        if($goodsSpec['value'])
	        {
	            $goodsInfo[]  = $goodsSpec['name']."(".$goodsSpec['value'].")";
	        }
	        else
	        {
	            $goodsInfo[]  = $goodsSpec['name'];
	        }
	    }
	    $orderNo = $refundRow['order_no'];
	    $goodsInfo = join(",",$goodsInfo);

	    //给用户发消息
	    $openid = wechat::getOpenidByUser($refundRow['user_id']);
	    $data   = array(
	        'first' => array("value" => "退款申请","color" => "#173177"),
	        'orderProductPrice' => array("value" => $orderAmount),
	        'orderProductName' => array("value" => $goodsInfo),
	        'orderName' => array("value" => $orderNo),
	        'remark' => array("value" => "我们将尽快处理您的退款申请"),
	    );
	    $this->send($openid,$templateid,$data,"ucenter/refunds_detail/id/{$id}");

	    //给商家发消息
	    $openid = wechat::getOpenidBySeller($refundRow['seller_id']);
	    $data   = array(
	        'first' => array("value" => "您有新的退款申请","color" => "#173177"),
	        'orderProductPrice' => array("value" => $orderAmount),
	        'orderProductName' => array("value" => $goodsInfo),
	        'orderName' => array("value" => $orderNo),
	        'remark' => array("value" => "请尽快处理退款申请"),
	    );
	    $url = $refundRow['seller_id'] == 0 ? "order/refundment_list" : "seller/refundment_list";
	    $this->send($openid,$templateid,$data,$url);
	}

    /**
     * @brief 【模板消息】退款完成[用户、商家接受]
     * @param int $id 申请退款单ID
     */
	public function refundFinish($id)
	{
	    $templateid = $this->templateId('TM00430');

	    $refundDB = new IModel('refundment_doc');
	    $refundRow= $refundDB->getObj($id);

	    $orderAmount = $refundRow['amount'];
        $goodsInfo   = array();
	    $goodsList   = Api::run('getOrderGoodsRowById',array('id' => $refundRow['order_goods_id']));
	    foreach($goodsList as $item)
	    {
	        $goodsSpec = JSON::decode($item['goods_array']);
	        if($goodsSpec['value'])
	        {
	            $goodsInfo[]  = $goodsSpec['name']."(".$goodsSpec['value'].")";
	        }
	        else
	        {
	            $goodsInfo[]  = $goodsSpec['name'];
	        }
	    }
	    $orderNo = $refundRow['order_no'];
	    $goodsInfo = join(",",$goodsInfo);
	    $way = "退款方式：".order_class::refundWay($refundRow['way']);

	    //给用户发消息
	    $openid = wechat::getOpenidByUser($refundRow['user_id']);
	    $data   = array(
	        'first' => array("value" => "退款成功","color" => "#173177"),
	        'orderProductPrice' => array("value" => $orderAmount),
	        'orderProductName' => array("value" => $goodsInfo),
	        'orderName' => array("value" => $orderNo),
	        'remark' => array("value" => $way),
	    );
	    $this->send($openid,$templateid,$data,"ucenter/refunds_detail/id/{$id}");

        //给商户发消息
        $openid = wechat::getOpenidBySeller($refundRow['seller_id']);
	    $data   = array(
	        'first' => array("value" => "退款成功","color" => "#173177"),
	        'orderProductPrice' => array("value" => $orderAmount),
	        'orderProductName' => array("value" => $goodsInfo),
	        'orderName' => array("value" => $orderNo),
	        'remark' => array("value" => $way),
	    );
	    $url = $refundRow['seller_id'] == 0 ? "order/refundment_list" : "seller/refundment_list";
	    $this->send($openid,$templateid,$data,$url);
	}

    /**
     * @brief 【模板消息】发货通知[用户接受]
     * @param int $deliveryId 发货单ID
     */
	public function orderSendDeliveryFinish($deliveryId)
	{
	    $templateid = $this->templateId('TM00303');
	    $deliveryDB = new IQuery('delivery_doc as dd');
	    $deliveryDB->join = 'left join freight_company as fc on dd.freight_id = fc.id';
	    $deliveryDB->where= 'dd.id = '.$deliveryId;
	    $deliveryRow = $deliveryDB->find();
	    $deliveryRow = current($deliveryRow);

	    //给用户发消息
        $openid = wechat::getOpenidByUser($deliveryRow['user_id']);
	    $data   = array(
	        'first' => array("value" => "商品已经发货","color" => "#173177"),
	        'delivername' => array("value" => $deliveryRow['freight_name']),
	        'ordername' => array("value" => $deliveryRow['delivery_code']),
	        'remark' => array("value" => '请您耐心等待'),
	    );
	    return $this->send($openid,$templateid,$data,"ucenter/order_detail/id/".$deliveryRow['order_id']);
	}

    /**
     * @brief 退款被拒绝[用户接受]
     * @param int $id 申请退款单ID
     */
    public function refundDocUpdate($id)
    {
	    $refundDB = new IModel('refundment_doc');
	    $row = $refundDB->getObj($id);

	    if($row)
	    {
	        switch($row['pay_status'])
	        {
	            //拒绝
	            case 1:
	            {
                    $templateid = $this->templateId('OPENTM414592249');
                    $openid = wechat::getOpenidByUser($row['user_id']);
                    $data   = array(
                        "first"    => array("value" => "您退款申请被驳回","color" => "#173177"),
                        "keyword1" => array("value" => $row['order_no']),
                        "keyword2" => array("value" => $row['dispose_time']),
                        "keyword3" => array("value" => $row['dispose_idea']),
                        "remark"   => array("value" => "如果仍存在问题请咨询客服"),
                    );
                    $this->send($openid,$templateid,$data,"ucenter/refunds_detail/id/{$id}");
	            }
	            break;

                //买家返还物流
	            case 3:
	            {
    	            $data = [
    	                "first"    => "退款申请需要返还",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['pay_status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => "需要您把商品返还给商家，并且把相关物流信息更新到个人中心的售后服务里面",
    	            ];
        	        $openid     = wechat::getOpenidByUser($row['user_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
                    $this->send($openid,$templateid,$data,"ucenter/refunds_detail/id/{$id}");
	            }
	            break;

                //卖家重发物流
	            case 4:
	            {
    	            $data = [
    	                "first"    => "换货申请返还物流更新",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['pay_status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => "买家已经更新了物流信息，注意查收后进行退款",
    	            ];

        	        $openid     = wechat::getOpenidBySeller($row['seller_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
            	    $url = $row['seller_id'] == 0 ? "order/refunds_list" : "seller/refunds_list";
            	    $this->send($openid,$templateid,$data,$url);
	            }
	            break;
	        }
	    }
    }

    /**
     * @brief 消费码核销成功
     * @param string $code 消费码
     */
    public function checkOrderCodeFinish($code)
    {
        $db = new IModel('order_code_relation');
        $codeRow = $db->getObj('code = "'.$code.'"');
        if($codeRow && $codeRow['is_used'] == 1)
        {
            $goodsRow = Api::run('getGoodsInfo',array('id' => $codeRow['goods_id']));
            $templateid = $this->templateId('OPENTM406638019');
            $openid = wechat::getOpenidByUser($codeRow['user_id']);
            $data   = array(
                "first"    => array("value" => "消费码使用成功","color" => "#173177"),
                "keyword1" => array("value" => $goodsRow['name']),
                "keyword2" => array("value" => 1),
                "keyword2" => array("value" => ITime::getDateTime()),
                "remark"   => array("value" => "您的消费码：".$code."，消费成功，欢迎下次光临"),
            );
            $this->send($openid,$templateid,$data,"ucenter/order_detail/id/".$codeRow['order_id']);
        }
    }

    /**
     * @brief 提现申请[用户，管理员接受]
     * @param int $id 提现ID
     */
    public function withdrawApplyFinish($id)
    {
        $db = new IModel('withdraw');
        $row= $db->getObj($id);

        $templateid = $this->templateId('TM00979');

        //用户发送
        $openid = wechat::getOpenidByUser($row['user_id']);
        $data   = array(
            "first" => array("value" => "提现申请提交成功","color" => "#173177"),
            "money" => array("value" => $row['amount']),
            "timet" => array("value" => $row['time']),
            "remark"=> array("value" => "请您耐心等待审核结果"),
        );
        $this->send($openid,$templateid,$data,"ucenter/withdraw");

        //管理员发送
        $openid = wechat::getOpenidBySeller(0);
        $data   = array(
            "first" => array("value" => "您有新的提现申请需要处理","color" => "#173177"),
            "money" => array("value" => $row['amount']),
            "timet" => array("value" => $row['time']),
            "remark"=> array("value" => "申请内容：".$row['note']),
        );
        $this->send($openid,$templateid,$data,"member/withdraw_list");
    }

    /**
     * @brief 提现结果更新[用户接受]
     * @param int $id 提现ID
     */
    public function withdrawStatusUpdate($id)
    {
        $db = new IModel('withdraw');
        $row= $db->getObj($id);

        //拒绝
        if($row['status'] == '-1')
        {
            $templateid = $this->templateId('TM00981');
            $data = array(
                "first" => array("value" => "提现审核被拒","color" => "#173177"),
                "money" => array("value" => $row['amount']),
                "timet" => array("value" => $row['time']),
                "remark"=> array("value" => "您的提现申请被拒绝，如有问题请联系网站管理员"),
            );
        }
        //同意
        else if($row['status'] == '2')
        {
            $templateid = $this->templateId('TM00980');
            $data = array(
                "first" => array("value" => "提现审核成功","color" => "#173177"),
                "money" => array("value" => $row['amount']),
                "timet" => array("value" => $row['time']),
                "remark"=> array("value" => "我们会尽快把钱转到您指定的账户中，请注意查收"),
            );
        }
        $openid = wechat::getOpenidByUser($row['user_id']);
        $this->send($openid,$templateid,$data,"ucenter/withdraw");
    }

    /**
     * @brief 在线充值成功
     * @param string $recharge_no 充值订单号
     */
    public function onlineRechargeFinish($recharge_no)
    {
		$rechargeObj = new IModel('online_recharge');
		$rechargeRow = $rechargeObj->getObj('recharge_no = "'.$recharge_no.'"');
		if($rechargeRow && $rechargeRow['status'] == 1)
		{
            $templateid = $this->templateId('TM00977');
            $openid = wechat::getOpenidByUser($rechargeRow['user_id']);
            $data   = array(
                "first"   => array("value" => "在线充值成功","color" => "#173177"),
                "money"   => array("value" => $rechargeRow['account']),
                "product" => array("value" => $rechargeRow['payment_name']),
                "remark"  => array("value" => "请登录您的个人中心查看余额"),
            );
            $this->send($openid,$templateid,$data,"ucenter/account_log");
		}
    }

    //换货申请
    public function exchangeApplyFinish($id)
    {
	    $templateid = $this->templateId('OPENTM410195709');

	    $db = new IModel('exchange_doc');
	    $row= $db->getObj($id);

	    //给用户发消息
	    $openid = wechat::getOpenidByUser($row['user_id']);
	    $data   = array(
	        'first'    => array("value" => "换货申请","color" => "#173177"),
	        'keyword1' => array("value" => $row['order_no']),
	        'keyword2' => array("value" => "申请中"),
	        'keyword3' => array("value" => $row['time']),
	        'remark'   => array("value" => "我们将尽快处理您的换货申请"),
	    );
	    $this->send($openid,$templateid,$data,"ucenter/exchange_detail/id/{$id}");

	    //给商家发消息
	    $openid = wechat::getOpenidBySeller($row['seller_id']);
	    $data   = array(
	        'first'    => array("value" => "您有新的换货申请","color" => "#173177"),
	        'keyword1' => array("value" => $row['order_no']),
	        'keyword2' => array("value" => "待处理"),
	        'keyword3' => array("value" => $row['time']),
	        'remark'   => array("value" => "请尽快处理申请"),
	    );
	    $url = $row['seller_id'] == 0 ? "order/exchange_list" : "seller/exchange_list";
	    $this->send($openid,$templateid,$data,$url);
    }

    //换货更新同意或者拒绝
    public function exchangeDocUpdate($id)
    {
	    $db = new IModel('exchange_doc');
	    $row= $db->getObj($id);

	    if($row)
	    {
	        switch($row['status'])
	        {
	            //拒绝
	            case 1:
	            {
    	            $data = [
    	                "first"    => "换货申请被拒绝",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => $row['dispose_idea'],
    	            ];
        	        $openid     = wechat::getOpenidByUser($row['user_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
                    $this->send($openid,$templateid,$data,"ucenter/exchange_detail/id/{$id}");
	            }
	            break;

                //成功
	            case 2:
	            {
    	            $data = [
    	                "first"    => "换货申请已通过",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => $row['dispose_idea'],
    	            ];
        	        $openid     = wechat::getOpenidByUser($row['user_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
                    $this->send($openid,$templateid,$data,"ucenter/exchange_detail/id/{$id}");
	            }
	            break;

                //买家返还物流
	            case 3:
	            {
    	            $data = [
    	                "first"    => "换货申请需要返还",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => "需要您把商品返还给商家，并且把相关物流信息更新到个人中心的售后服务里面",
    	            ];
        	        $openid     = wechat::getOpenidByUser($row['user_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
                    $this->send($openid,$templateid,$data,"ucenter/exchange_detail/id/{$id}");
	            }
	            break;

                //卖家重发物流
	            case 4:
	            {
    	            $data = [
    	                "first"    => "换货申请返还物流更新",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => "买家已经更新了物流信息，注意查收后进行商品重发",
    	            ];

        	        $openid     = wechat::getOpenidBySeller($row['seller_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
            	    $url = $row['seller_id'] == 0 ? "order/exchange_list" : "seller/exchange_list";
            	    $this->send($openid,$templateid,$data,$url);
	            }
	            break;
	        }
	    }
    }

    //维修申请
    public function fixApplyFinish($id)
    {
	    $templateid = $this->templateId('OPENTM410195709');

	    $db = new IModel('fix_doc');
	    $row= $db->getObj($id);

	    //给用户发消息
	    $openid = wechat::getOpenidByUser($row['user_id']);
	    $data   = array(
	        'first'    => array("value" => "维修申请","color" => "#173177"),
	        'keyword1' => array("value" => $row['order_no']),
	        'keyword2' => array("value" => "申请中"),
	        'keyword3' => array("value" => $row['time']),
	        'remark'   => array("value" => "我们将尽快处理您的维修申请"),
	    );
	    $this->send($openid,$templateid,$data,"ucenter/fix_detail/id/{$id}");

	    //给商家发消息
	    $openid = wechat::getOpenidBySeller($row['seller_id']);
	    $data   = array(
	        'first'    => array("value" => "您有新的维修申请","color" => "#173177"),
	        'keyword1' => array("value" => $row['order_no']),
	        'keyword2' => array("value" => "待处理"),
	        'keyword3' => array("value" => $row['time']),
	        'remark'   => array("value" => "请尽快处理申请"),
	    );
	    $url = $row['seller_id'] == 0 ? "order/fix_list" : "seller/fix_list";
	    $this->send($openid,$templateid,$data,$url);
    }

    //维修更新同意或者拒绝
    public function fixDocUpdate($id)
    {
	    $db = new IModel('fix_doc');
	    $row= $db->getObj($id);

	    if($row)
	    {
	        switch($row['status'])
	        {
	            //拒绝
	            case 1:
	            {
    	            $data = [
    	                "first"    => "维修申请被拒绝",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => $row['dispose_idea'],
    	            ];
        	        $openid     = wechat::getOpenidByUser($row['user_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
                    $this->send($openid,$templateid,$data,"ucenter/fix_detail/id/{$id}");
	            }
	            break;

                //成功
	            case 2:
	            {
    	            $data = [
    	                "first"    => "维修申请已通过",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => $row['dispose_idea'],
    	            ];
        	        $openid     = wechat::getOpenidByUser($row['user_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
                    $this->send($openid,$templateid,$data,"ucenter/fix_detail/id/{$id}");
	            }
	            break;

                //买家返还物流
	            case 3:
	            {
    	            $data = [
    	                "first"    => "维修申请需要返还",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => "需要您把商品返还给商家，并且把相关物流信息更新到个人中心的售后服务里面",
    	            ];
        	        $openid     = wechat::getOpenidByUser($row['user_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
                    $this->send($openid,$templateid,$data,"ucenter/fix_detail/id/{$id}");
	            }
	            break;

                //卖家重发物流
	            case 4:
	            {
    	            $data = [
    	                "first"    => "维修申请返还物流更新",
    	                "keyword1" => $row['order_no'],
    	                "keyword2" => Order_Class::refundmentText($row['status']),
    	                "keyword3" => $row['time'],
    	                "remark"   => "买家已经更新了物流信息，注意查收后进行商品重发",
    	            ];

        	        $openid     = wechat::getOpenidBySeller($row['seller_id']);
        	        $templateid = $this->templateId('OPENTM410195709');
            	    $url = $row['seller_id'] == 0 ? "order/fix_list" : "seller/fix_list";
            	    $this->send($openid,$templateid,$data,$url);
	            }
	            break;
	        }
	    }
    }
}

/**
 * 微信菜单自定义
 */
class wechatMenu extends pluginBase
{
    public $_id = 'wechat';
    public $actions = ["plugins" => ["wechat_menu","wechat_menu_update"]];

    public function reg()
    {
        parent::reg();

        //后台管理菜单
		plugin::reg("onSystemMenuCreate",function(){
		    $link = "/plugins/wechat_menu";
		    $link = "javascript:art.dialog.open('".IUrl::creatUrl($link)."',{title:'微信自定义菜单',width:'100%',height:'100%',id:'wechat_menu'});";
		    Menu::$menu["插件"]["插件管理"][$link] = "微信自定义菜单";
		});
    }

    //微信菜单更新
    function wechat_menu_update()
    {
        $tag_id   = IFilter::act(IReq::get('tagid'));
        $menuid   = IFilter::act(IReq::get('menuid'));
        $menuData = IFilter::act(IReq::get('menu'));

        if($menuData && isset($menuData['menu']))
        {
            //根据标签自定义
            if($tag_id)
            {
                $updateMenu = $menuData['menu']['button'];

                //删除之前旧的数据
                if($menuid)
                {
                    $delResult = $this->delTagCustom($menuid);
                }

                $result = $this->createTagCustom($updateMenu,$tag_id);
                if($result && isset($result['menuid']))
                {
                    $response = ["result" => "success","message" => "个性化菜单更新成功"];
                }
                else
                {
                    $response = ["result" => "fail","error" => "个性化菜单创建失败"];
                }
            }
            //默认菜单
            else
            {
                $result = $this->create($menuData['menu']['button']);
                if($result && $result['errmsg'] == 'ok')
                {
                    $response = ["result" => "success","message" => "菜单更新成功"];
                }
                else
                {
                    $response = ["result" => "fail","error" => "默认标准菜单创建失败"];
                }
            }
        }
        //删除菜单
        else
        {
            $result = $this->del();
            if($result && $result['errmsg'] == 'ok')
            {
                $response = ["result" => "success","message" => "菜单删除成功"];
            }
            else
            {
                $response = ["result" => "fail","error" => "菜单删除失败"];
            }
        }

        die(JSON::encode($response));
    }

    //微信菜单主页
    public function wechat_menu()
    {
        $tag_id  = IFilter::act(IReq::get('id'),'int');
        $tag_name= IFilter::act(IReq::get('name'));
        $menu_id = 0;

        //菜单查询
        $menuData = $this->get();

        //根据用户标签读取菜单
        if($tag_id)
        {
            if(isset($menuData['conditionalmenu']) && $menuData['conditionalmenu'])
            {
                foreach($menuData['conditionalmenu'] as $item)
                {
                    if($item['matchrule']['group_id'] == $tag_id)
                    {
                        $menu_id     = $item['menuid'];
                        $tagMenuData = ['menu' => $item];
                    }
                }
            }
            else
            {
                $defaultData = [
                    'button'=>[
                        [
                            'name'=>'默认菜单1',
                            'type'=>'view',
                            'url'=>'http://www.baidu.com/',
                            'sub_button'=>[]
                        ],
                        [
                            'name'=>'默认菜单2',
                            'type'=>'view',
                            'url'=>'http://www.baidu.com',
                            'sub_button'=>[]
                        ],
                        [
                            'name'=>'默认菜单3',
                            'type'=>'view',
                            'url'=>'http://www.baidu.com/',
                            'sub_button'=>[]
                        ]
                    ]
                ];

                $tagMenuData = ['menu' => $defaultData];
            }
            $data = ['menuData' => JSON::encode($tagMenuData),'tagid' => $tag_id,'name' => $tag_name,'menuid' => $menu_id];
        }
        else
        {
            $data = ['menuData' => JSON::encode($menuData),'tagid' => $tag_id,'name' => $tag_name,'menuid' => $menu_id];
        }

        $this->redirect('menu',$data);
    }

    /**
     * 创建自定义菜单
     */
    public function create($data)
    {
        $access_token = wechat::getAccessToken();
        $sendData = ['button' => $data];
        $postUrl = wechat::SERVER_URL."/menu/create?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }

    /**
     * 查询自定义菜单
     */
    public function get()
    {
        $access_token = wechat::getAccessToken();
        $postUrl = wechat::SERVER_URL."/menu/get?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl);
        }
        catch(Exception $e){}
    }

    /**
     * 删除自定义菜单
     */
    public function del()
    {
        $access_token = wechat::getAccessToken();
        $postUrl = wechat::SERVER_URL."/menu/delete?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl);
        }
        catch(Exception $e){}
    }

    /**
     * 创建个性化菜单
     * $data    一二级菜单数据
     * $tag_id  用户标签的id
     */
    public function createTagCustom($data,$tag_id)
    {
        $access_token = wechat::getAccessToken();
        $sendData = array(
            'button'   => $data,
            'matchrule'=> ['tag_id' => $tag_id]
        );
        $postUrl = wechat::SERVER_URL."/menu/addconditional?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }

    /**
     * 删除个性化菜单
     */
    public function delTagCustom($menuid)
    {
        $access_token = wechat::getAccessToken();
        $sendData = array(
            'menuid' => $menuid,
        );
        $postUrl = wechat::SERVER_URL."/menu/delconditional?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }
}

/**
 * 微信标签类
 */
class wechatTag extends pluginBase
{
    public $_id = 'wechat';
    public $actions = ["plugins" => ["wechat_tag","wechat_tag_update","wechat_tag_del","wechat_tag_member_list","wechat_tag_user_update"]];
    public function reg()
    {
        parent::reg();

        //后台管理菜单
	    plugin::reg("onSystemMenuCreate",function(){
	        $link = "/plugins/wechat_tag";
	        $link = "javascript:art.dialog.open('".IUrl::creatUrl($link)."',{title:'微信用户标签',width:'100%',height:'100%',id:'wechat_tag'});";
	        Menu::$menu["插件"]["插件管理"][$link] = "微信用户标签";
	    });
    }

    //微信标签列表
    public function wechat_tag()
    {
        $tagData = $this->get();
        $this->redirect('tag',['tagData' => $tagData['tags']]);
    }

    /**
     * 标签添加与修改 ajax
     */
    public function wechat_tag_update()
    {
        $id   = IFilter::act(IReq::get('id'),'int');
        $name = IFilter::act(IReq::get('name'));

        //更新修改
        if($id)
        {
            $result = $this->update($id, $name);
            if($result && $result['errmsg'] == 'ok')
            {
                $response = ['result' => 'success','message' => '修改标签成功'];
            }
            else
            {
                $response = ['result' => 'fail','error' => '修改标签失败'];
            }
        }
        //添加
        else
        {
            $result = $this->create($name);
            if($result && $result['tag'])
            {
                $response = ['result' => 'success','message' => '新建标签成功'];
            }
            else
            {
                $response = ['result' => 'fail','error' => $result['errmsg']];
            }
        }
        die(JSON::encode($response));
    }

    /**
     * 标签删除
     */
    public function wechat_tag_del()
    {
        $id     = IFilter::act(IReq::get('id'), 'int');
        $result = $this->del($id);
        if($result && $result['errmsg'] == 'ok')
        {
            $response = ['result' => 'success','message' => '删除标签成功'];
        }
        else
        {
            $response = ['result' => 'fail','error' => $result['errmsg']];
        }
        die(JSON::encode($response));
    }

    /**
     * 用户列表
     */
    public function wechat_tag_member_list()
    {
        $search   = IFilter::act(IReq::get('search'), 'strict');
        $keywords = IFilter::act(IReq::get('keywords'));
        $tagid    = IFilter::act(IReq::get('tagid'));
        $where    = " 1 ";

        if($search && $keywords)
        {
            $where .= " AND $search = '".$keywords."' ";
        }

        //标签筛选
        if($tagid)
        {
            $userIdArray = [];
            $result      = $this->getUserListByTag($tagid);
            if($result && isset($result['data']) && isset($result['data']['openid']))
            {
                $openidString = join('","',$result['data']['openid']);
                $oauthDB = new IModel('oauth_user');
                $oauthList = $oauthDB->query('openid in ("'.$openidString.'")','user_id');
                foreach($oauthList as $val)
                {
                    $userIdArray[] = $val['user_id'];
                }
            }
            $userIdArray = $userIdArray ? $userIdArray : [0];
            $where .= " and m.user_id in (".join(',',$userIdArray).") ";
        }

        $data = [
            'search'   => $search,
            'keywords' => $keywords,
            'where'    => $where,
            'tagData'  => $this->get(),//获取现有标签数据
            'tagid'    => $tagid,
        ];
        $this->redirect('tag_member_list',$data);
    }

    //更新用户标签
    public function wechat_tag_user_update()
    {
        $tagid   = IFilter::act(IReq::get('tagid'),'int');
        $user_id = IFilter::act(IReq::get('user_id'),'int');
        $openid  = wechat::getOpenidByUser($user_id);

        //查询当前用户已有标签
        $userTagData = $this->getTagByUser($openid);
        if($userTagData && $userTagData['errmsg'] == 'ok')
        {
            if($userTagData['tagid_list'] && in_array($tagid,$userTagData['tagid_list']))
            {
                $result = $this->delUser($tagid,[$openid]);
                if($result && $result['errmsg'] == 'ok')
                {
                    $response = ['result' => 'success','message' => '取消标签成功'];
                }
                else
                {
                    $response = ['result' => 'fail','error' => $result['errmsg']];
                }
            }
            else
            {
                $result = $this->setUser($tagid,[$openid]);
                if($result && $result['errmsg'] == 'ok')
                {
                    $response = ['result' => 'success','message' => '设置标签成功'];
                }
                else
                {
                    $response = ['result' => 'fail','error' => $result['errmsg']];
                }
            }
        }
        else
        {
            $response = ['result' => 'fail','error' => $userTagData['errmsg']];
        }

        die(JSON::encode($response));
    }

    /**
     * 创建用户标签
     */
    public function create($name)
    {
        $access_token = wechat::getAccessToken();
        $sendData     = array(
            'tag' => ['name' => $name]
        );

        $postUrl = wechat::SERVER_URL."/tags/create?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }

    /**
     * 获取用户标签
     */
    public function get()
    {
        $access_token = wechat::getAccessToken();
        $apiUrl = wechat::SERVER_URL."/tags/get?access_token=".$access_token;
        $json   = file_get_contents($apiUrl,false);
        return JSON::decode($json);
    }

    /**
     * 用户标签删除
     */
    public function del($tagid)
    {
        $access_token = wechat::getAccessToken();
        $sendData     = array(
            'tag' => ["id" => $tagid]
        );

        $postUrl = wechat::SERVER_URL."/tags/delete?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }

    /**
     * 用户标签编辑
     */
    public function update($tagid,$name)
    {
        $access_token = wechat::getAccessToken();
        $sendData     = array(
            'tag' => ["id" => $tagid,"name" => $name]
        );

        $postUrl = wechat::SERVER_URL."/tags/update?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }

    /**
     * 获取标签用户
     * @param $tag_id 用户标签ID
     * @param $next_openid 下一组用户的openid起始点
     */
    public function getUserListByTag($tagid,$next_openid = '')
    {
        $access_token = wechat::getAccessToken();
        $sendData     = array(
            'tagid'       => $tagid,
            'next_openid' => $next_openid,
        );

        $postUrl = wechat::SERVER_URL."/user/tag/get?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }

    /**
     * 用户设置标签
     */
    public function setUser($tagid,$openidList)
    {
        $access_token = wechat::getAccessToken();
        $sendData     = array(
            'openid_list' => $openidList,
            'tagid'       => $tagid,
        );

        $postUrl = wechat::SERVER_URL."/tags/members/batchtagging?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }

    /**
     * 取消用户身上的标签
     */
    public function delUser($tagid,$openidList)
    {
        $access_token = wechat::getAccessToken();
        $sendData     = array(
            'openid_list' => $openidList,
            'tagid'       => $tagid,
        );

        $postUrl = wechat::SERVER_URL."/tags/members/batchuntagging?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }

    /**
     * 获取用户身上的标签列表
     */
    public function getTagByUser($openid)
    {
        $access_token = wechat::getAccessToken();
        $sendData     = ['openid' => $openid];

        $postUrl = wechat::SERVER_URL."/tags/getidlist?access_token=".$access_token;
        try
        {
            return wechat::submit($postUrl,JSON::encode($sendData));
        }
        catch(Exception $e){}
    }
}