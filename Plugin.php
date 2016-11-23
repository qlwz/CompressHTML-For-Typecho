<?php
if (!defined('__TYPECHO_ROOT_DIR__'))
    exit;

/**
 * 压缩HTML代码
 *
 * @package CompressHTML
 * @author 情留メ蚊子
 * @version 1.0.0.0
 * @link http://www.94qing.com/
 */
class CompressHTML_Plugin implements Typecho_Plugin_Interface {
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate() {
        Typecho_Plugin::factory('Widget_Archive')->beforeRender = array('CompressHTML_Plugin', 'Widget_Archive_beforeRender');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate() {
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form) {
        $compression_open = new Typecho_Widget_Helper_Form_Element_Checkbox('compression_open', array('compression_open' => '开启gzip'), null, _t('是否开启gzip'));
        $form->addInput($compression_open);

        $compression_level = new Typecho_Widget_Helper_Form_Element_Select('compression_level', array('1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6', '7' => '7', '8' => '8', '9' => '9'), '5', _t('gzip压缩级别:'), _t('这个参数值范围是0-9，0表示无压缩，9表示最大压缩，当然压缩程度越高越费CPU。*推荐：5'));
        $form->addInput($compression_level);

        $compress_html = new Typecho_Widget_Helper_Form_Element_Checkbox('compress_html', array('compress_html' => '开启压缩HTML'), null, _t('是否开启压缩HTML'), _t('当开启后页面与原来页面不一致时请关闭'));
        $form->addInput($compress_html);

        $keyword_replace = new Typecho_Widget_Helper_Form_Element_Textarea('keyword_replace', null, null, _t('HTML关键词替换'), _t('作用：主要把附件的内容转到七牛。一行一个。格式：关键词=替换关键词'));
        $form->addInput($keyword_replace);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {
    }

    public static function Widget_Archive_beforeRender() {
        ob_start('CompressHTML_Plugin::qlwz_ob_handler');
    }

    public static function qlwz_ob_handler($buffer) {
        $settings = Helper::options()->plugin('CompressHTML');

        if ($settings->keyword_replace) {
            $list = explode("\r\n", $settings->keyword_replace);
            foreach ($list as $tmp) {
                list($old, $new) = explode('=', $tmp);
                $buffer = str_replace($old, $new, $buffer);
            }
        }

        if ($settings->compress_html) {
            $buffer = self::qlwz_compress_html($buffer);
        }

        if ($settings->compression_open) {
            $buffer = self::ob_gzip($buffer, $settings->compression_level);
        } else {
            if (ini_get('zlib.output_compression')) {
                ini_set('zlib.output_compression', 'Off');
            }
        }
        return $buffer;
    }

    public static function ob_gzip($buffer, $level) {
        if (ini_get('zlib.output_compression')) {
            if (ini_get('zlib.output_compression_level') != $level) {
                ini_set('zlib.output_compression_level', $level);
            }
            return $buffer;
        }
        if (headers_sent() || !extension_loaded('zlib') || !strstr($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
            return $buffer;
        }

        $out_buffer = gzencode($buffer, $level);
        if (strlen($out_buffer) < strlen($buffer)) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            header('Content-Length: ' . strlen($out_buffer));
        } else {
            $out_buffer = $buffer;
        }
        return $out_buffer;
    }

    /**
     * 压缩HTML代码
     *
     * @author 情留メ蚊子 <qlwz@qq.com>
     * @version 1.0.0.0 By 2016-11-23
     * @param string $html_source HTML源码
     * @return string 压缩后的代码
     */
    public static function qlwz_compress_html($html_source) {
        $chunks = preg_split('/(<!--<nocompress>-->.*?<!--<\/nocompress>-->|<nocompress>.*?<\/nocompress>|<pre.*?\/pre>|<textarea.*?\/textarea>|<script.*?\/script>)/msi', $html_source, -1, PREG_SPLIT_DELIM_CAPTURE);
        $compress = '';
        foreach ($chunks as $c) {
            if (strtolower(substr($c, 0, 19)) == '<!--<nocompress>-->') {
                $c = substr($c, 19, strlen($c) - 19 - 20);
                $compress .= $c;
                continue;
            } else if (strtolower(substr($c, 0, 12)) == '<nocompress>') {
                $c = substr($c, 12, strlen($c) - 12 - 13);
                $compress .= $c;
                continue;
            } else if (strtolower(substr($c, 0, 4)) == '<pre' || strtolower(substr($c, 0, 9)) == '<textarea') {
                $compress .= $c;
                continue;
            } else if (strtolower(substr($c, 0, 7)) == '<script' && strpos($c, '//') != false && (strpos($c, "\r") !== false || strpos($c, "\n") !== false)) { // JS代码，包含“//”注释的，单行代码不处理
                $tmps = preg_split('/(\r|\n)/ms', $c, -1, PREG_SPLIT_NO_EMPTY);
                $c = '';
                foreach ($tmps as $tmp) {
                    if (strpos($tmp, '//') !== false) { // 对含有“//”的行做处理
                        if (substr(trim($tmp), 0, 2) == '//') { // 开头是“//”的就是注释
                            continue;
                        }
                        $chars = preg_split('//', $tmp, -1, PREG_SPLIT_NO_EMPTY);
                        $is_quot = $is_apos = false;
                        foreach ($chars as $key => $char) {
                            if ($char == '"' && $chars[$key - 1] != '\\' && !$is_apos) {
                                $is_quot = !$is_quot;
                            } else if ($char == '\'' && $chars[$key - 1] != '\\' && !$is_quot) {
                                $is_apos = !$is_apos;
                            } else if ($char == '/' && $chars[$key + 1] == '/' && !$is_quot && !$is_apos) {
                                $tmp = substr($tmp, 0, $key); // 不是字符串内的就是注释
                                break;
                            }
                        }
                    }
                    $c .= $tmp;
                }
            }
            $c = preg_replace('/[\\n\\r\\t]+/', ' ', $c); // 清除换行符，清除制表符
            $c = preg_replace('/\\s{2,}/', ' ', $c); // 清除额外的空格
            $c = preg_replace('/>\\s</', '> <', $c); // 清除标签间的空格
            $c = preg_replace('/\\/\\*.*?\\*\\//i', '', $c); // 清除 CSS & JS 的注释
            $c = preg_replace('/<!--[^!]*-->/', '', $c); // 清除 HTML 的注释
            $compress .= $c;
        }
        return $compress;
    }

}