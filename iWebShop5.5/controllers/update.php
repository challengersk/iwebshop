<?php
/**
 * @brief 升级更新控制器
 */
class Update extends IController
{
	/**
	 * @brief 升级更新
	 */
	public function index()
	{
		set_time_limit(0);

		$sql = array(
            "ALTER TABLE `{pre}takeself` ADD `seller_id` int(11) unsigned default 0 COMMENT '商家ID'",
            "ALTER TABLE `{pre}takeself` ADD `logo` varchar(255) DEFAULT NULL COMMENT 'logo图片'",

            "alter table `{pre}banner` add column `type` enum('mobile','pc') NOT NULL DEFAULT 'pc' COMMENT 'Banner类型';",
            "alter table `{pre}banner` add column `id` int(11) unsigned NOT NULL;",
            "alter table `{pre}banner` drop primary key;",
            "alter table `{pre}banner` add primary key(id,_hash);",
            "alter table `{pre}banner` modify id int(11) auto_increment;",

		    "ALTER TABLE `{pre}announcement` ADD `keywords` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '关键词'",
		    "ALTER TABLE `{pre}announcement` ADD `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '描述'",

		    "ALTER TABLE `{pre}article_category` ADD `title` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SEO标题'",
            "ALTER TABLE `{pre}article_category` ADD `keywords` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SEO关键词和检索关键词'",
            "ALTER TABLE `{pre}article_category` ADD `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'SEO描述'",

            "ALTER TABLE `{pre}order` ADD INDEX(`checkcode`);",
		);

		foreach($sql as $key => $val)
		{
		    IDBFactory::getDB()->query( $this->_c($val) );
		}

		$rightDB = new IModel('right');
		$rightDB->setData(['right' => 'order@check_code_ajax,order@get_code_info_ajax,order@order_code_check']);
		$rightDB->update('name = "[订单]验证消费码" ');

        //清空runtime缓存
		$runtimePath = IWeb::$app->getBasePath().'runtime';
		$result      = IFile::clearDir($runtimePath);
		die("升级成功!! V5.5版本");
	}

	public function _c($sql)
	{
		return str_replace('{pre}',IWeb::$app->config['DB']['tablePre'],$sql);
	}
}