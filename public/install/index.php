<?php
/**
 * 安装向导转发入口
 * 站点根目录指向 public/ 时，/install/ 通过本文件转发到项目根目录的安装向导。
 * 安装完成后请删除 public/install/ 与 install/ 两个目录。
 */
require_once dirname(__DIR__, 2) . '/install/index.php';
