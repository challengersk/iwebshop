<?php
/**
 * @brief 优惠券类库
 */
class ticket
{
	/**
	 * @brief 获取优惠券状态数值
	 * @param array $ticketRow 优惠券数据
	 * @return int 状态码 -1:已使用;-2:已禁用;-3:临时锁定;-4:已过期;1:可使用;
	 */
	public static function status($ticketRow)
	{
    	if($ticketRow['is_userd']==1)
    	{
    		return -1;
    	}

    	if($ticketRow['is_close']==1)
    	{
			return -2;
    	}

    	if($ticketRow['is_close']==2)
    	{
    		return -3;
    	}

    	if(ITime::getDateTime() > $ticketRow['end_time'])
    	{
    		return -4;
    	}
    	return 1;
	}

	/**
	 * @brief 获取优惠券的状态文字
	 * @param int $status 状态码
	 * @return string 状态文字
	 */
	public static function statusText($status)
	{
		$mapping = array(
			"-1" => "已使用",
			"-2" => "已禁用",
			"-3" => "临时锁定",
			"-4" => "已过期",
			"1"  => "可使用",
		);
		return isset($mapping[$status]) ? $mapping[$status] : "未知";
	}
}