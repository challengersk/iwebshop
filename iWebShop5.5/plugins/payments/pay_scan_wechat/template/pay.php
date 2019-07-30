<!DOCTYPE html>
<html lang="zh-CN">
<head>
	<title>微信扫码支付</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta http-equiv="X-UA-Compatible" content="IE=Edge">
	<link rel="stylesheet" href="//libs.cdnjs.net/twitter-bootstrap/3.3.4/css/bootstrap.min.css">
	<script src="//libs.cdnjs.net/jquery/3.1.1/jquery.min.js"></script>
</head>

<body>
	<div class="container-fluid text-center">
		<div class="form-group">
			<p class="text-primary bg-info" style="padding:15px">
				请使用微信扫一扫进行支付，此验证码在5分钟内有效，请尽快付款
			</p>
		</div>

		<div class="form-group">
			<img src="<?php echo $sendData['code_img'];?>" />
		</div>

		<div class="form-group">
			<h1 calss="text-info">支付金额：￥<?php echo $sendData['amount'];?></h1>
		</div>

		<hr />

		<div class="form-group">
			<input type="button" value="已经支付" class="btn btn-primary" onclick="window.location.href='<?php echo $sendData['url'];?>';" />
		</div>
	</div>
<script>
function orderCheck()
{
	jQuery.getJSON("<?php echo $sendData['orderCheckUrl'];?>",function(content)
	{
		//支付成功跳转走
		if(content.result == 1)
		{
			window.location.href="<?php echo $sendData['successUrl'];?>";
		}
	})
}
setInterval(orderCheck,3000);
</script>
</body>
</html>