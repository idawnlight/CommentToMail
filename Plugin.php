<?php
/**
 * 评论邮件提醒插件
 *
 * @package CommentToMail
 * @author 黎明余光
 * @version 2.1.0
 * @link https://blog.lim-light.com
 * @oriAuthor DEFE (http://defe.me)
 * 
 * 原作者是 DEFE (http://defe.me)，原维护者是 Byends (http://www.byends.com/)，请尊重版权
 *
 */
class CommentToMail_Plugin implements Typecho_Plugin_Interface
{
    /** @var string 提交路由前缀 */
    public static $action = 'comment-to-mail';
    
    /** @var string 控制菜单链接 */
    public static $panel  = 'CommentToMail/page/console.php';

    /** @var bool 是否记录日志 */
    private static $_isMailLog  = false;
    
    /** @var bool 请求适配器 */
    private static $_adapter    = false;

    /** @var int 超时时间 */
    private static $_timeout    = 1;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        if (false == self::isAvailable()) {
            throw new Typecho_Plugin_Exception(_t('对不起, 您的主机没有打开 allow_url_fopen 功能而且不支持 php-curl 扩展, 无法正常使用此功能'));
        }
        
        if (false == self::isWritable(dirname(__FILE__) . '/cache/')) {
            throw new Typecho_Plugin_Exception(_t('对不起，插件目录不可写，无法正常使用此功能'));
        }

        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('CommentToMail_Plugin', 'parseComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array('CommentToMail_Plugin', 'parseComment');
        Helper::addAction(self::$action, 'CommentToMail_Action');
        Helper::addPanel(1, self::$panel, '评论邮件提醒', '评论邮件提醒控制台', 'administrator');

        return _t('请设置邮箱信息，以使插件正常使用！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeAction(self::$action);
        Helper::removePanel(1, self::$panel);
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $mode= new Typecho_Widget_Helper_Form_Element_Radio('mode',
                array( 'smtp' => 'smtp',
                       'mail' => 'mail()',
                       'sendmail' => 'sendmail()'),
                'smtp', '发信方式');
        $form->addInput($mode);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, 'smtp.',
                _t('SMTP 地址'), _t('请填写 SMTP 服务器地址'));
        $form->addInput($host->addRule('required', _t('必须填写一个 SMTP 服务器地址')));

        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, '25',
                _t('SMTP 端口'), _t('SMTP 服务端口,一般为25。'));
        $port->input->setAttribute('class', 'mini');
        $form->addInput($port->addRule('required', _t('必须填写 SMTP 服务端口'))
                ->addRule('isInteger', _t('端口号必须是纯数字')));

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', NULL, NULL,
                _t('SMTP 用户'),_t('SMTP服务验证用户名,一般为邮箱名如：youname@domain.com'));
        $form->addInput($user->addRule('required', _t('SMTP 服务验证用户名')));

        $pass = new Typecho_Widget_Helper_Form_Element_Password('pass', NULL, NULL,
                _t('SMTP 密码'));
        $form->addInput($pass->addRule('required', _t('SMTP 服务验证密码')));

        $validate = new Typecho_Widget_Helper_Form_Element_Checkbox('validate',
                array('validate'=>'服务器需要验证'),
                array('validate'),'SMTP 验证');
        $form->addInput($validate);

        $encryption = new Typecho_Widget_Helper_Form_Element_Radio('encryption',
                array('none' => _t('不加密'),
                      'ssl' => _t('SSL'),
                      'tls' => _t('TLS')),
               'none', _t('SMTP 加密方式'));
        $form->addInput($encryption);
        
        $fromName = new Typecho_Widget_Helper_Form_Element_Text('fromName', NULL, NULL,
                _t('发件人名称'),_t('发件人名称，留空则使用博客标题'));
        $form->addInput($fromName);

        $mail = new Typecho_Widget_Helper_Form_Element_Text('mail', NULL, NULL,
                _t('接收邮件的地址'),_t('接收邮件的地址,如为空则使用文章作者个人设置中的邮件地址！'));
        $form->addInput($mail->addRule('email', _t('请填写正确的邮件地址！')));

        $contactme = new Typecho_Widget_Helper_Form_Element_Text('contactme', NULL, NULL,
                _t('模板中“联系我”的邮件地址'),_t('联系我用的邮件地址，如为空则使用文章作者个人设置中的邮件地址！'));
        $form->addInput($contactme->addRule('email', _t('请填写正确的邮件地址！')));

        $status = new Typecho_Widget_Helper_Form_Element_Checkbox('status',
                array('approved' => '提醒已通过评论',
                        'waiting' => '提醒待审核评论',
                        'spam' => '提醒垃圾评论'),
                array('approved', 'waiting'), '提醒设置',_t('该选项仅针对博主，访客只发送已通过的评论。'));
        $form->addInput($status);

        $other = new Typecho_Widget_Helper_Form_Element_Checkbox('other',
                array('to_owner' => '有评论及回复时，发邮件通知博主。',
                    'to_guest' => '评论被回复时，发邮件通知评论者。',
                    'to_me'=>'自己回复自己的评论时，发邮件通知。(同时针对博主和访客)',
                    'to_log' => '记录邮件发送日志。'),
                array('to_owner','to_guest'), '其他设置',_t('选中该选项插件会在log/mailer_log.txt 文件中记录发送日志。'));
        $form->addInput($other->multiMode());

        $titleForOwner = new Typecho_Widget_Helper_Form_Element_Text('titleForOwner',null,"[{title}] 一文有新的评论",
                _t('博主接收邮件标题'));
        $form->addInput($titleForOwner->addRule('required', _t('博主接收邮件标题 不能为空')));

        $titleForGuest = new Typecho_Widget_Helper_Form_Element_Text('titleForGuest',null,"您在 [{title}] 的评论有了回复",
                _t('访客接收邮件标题'));
        $form->addInput($titleForGuest->addRule('required', _t('访客接收邮件标题 不能为空')));
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {}

    /**
     * 获取邮件内容
     *
     * @access public
     * @param $comment 调用参数
     * @return void
     */
    public static function parseComment($comment)
    {        
        $options           = Typecho_Widget::widget('Widget_Options');
        $cfg = array(
            'siteTitle' => $options->title,
            'timezone'  => $options->timezone,
            'cid'       => $comment->cid,
            'coid'      => $comment->coid,
            'created'   => $comment->created,
            'author'    => $comment->author,
            'authorId'  => $comment->authorId,
            'ownerId'   => $comment->ownerId,
            'mail'      => $comment->mail,
            'ip'        => $comment->ip,
            'title'     => $comment->title,
            'text'      => $comment->text,
            'permalink' => $comment->permalink,
            'status'    => $comment->status,
            'parent'    => $comment->parent,
            'manage'    => $options->siteUrl . 'admin/manage-comments.php'
        );

        self::$_isMailLog = in_array('to_log', Helper::options()->plugin('CommentToMail')->other) ? true : false;

        //是否接收邮件
        if (isset($_POST['banmail']) && 'stop' == $_POST['banmail']) {
            $cfg['banMail'] = 1;
        } else {
            $cfg['banMail'] = 0;
        }

        $fileName = Typecho_Common::randString(7);
        $cfg      = (object)$cfg;
        file_put_contents(dirname(__FILE__) . '/cache/' . $fileName, serialize($cfg));
        $url = ($options->rewrite) ? $options->siteUrl : $options->siteUrl . 'index.php';
        $url = rtrim($url, '/') . '/action/' . self::$action . '?send=' . $fileName;

        $date = new Typecho_Date(Typecho_Date::gmtTime());
        $time = $date->format('Y-m-d H:i:s');
        
        self::saveLog("{$time} 开始发送请求：{$url}\n");
        self::asyncRequest($url);
    }



    /**
     * 发送异步请求
     * @param $url
     */
    public static function asyncRequest($url)
    {
        self::isAvailable();
        self::$_adapter == 'Curl' ? self::curl($url) : self::fgc($url);
    }

    /**
     * Socket 请求
     * @param $url
     * @return bool
     */
    public static function socket($url)
    {
        $params = parse_url($url);
        $path = $params['path'] . '?' . $params['query'];
        $host = $params['host'];
        $port = 80;
        $scheme = '';

        if ('https' == $params['scheme']) {
            $port = 443;
            $scheme = 'ssl://';
        }

        if (function_exists('fsockopen')) {
            $fp = @fsockopen ($scheme . $host, $port, $errno, $errstr, 30);
        } elseif (function_exists('pfsockopen')) {
            $fp = @pfsockopen ($scheme . $host, $port, $errno, $errstr, 30);
        } else {
            $fp = stream_socket_client($scheme . $host . ":$port", $errno, $errstr, 30);
        }

        if ($fp === false) {
            self::saveLog("Socket 错误," . $errno . ':' . $errstr);
            return false;
        }

        stream_set_blocking($fp, 0);

        $out = "GET " . $path . " HTTP/1.1\r\n";
        $out .= "Host: $host\r\n";
        $out .= "Connection: Close\r\n\r\n";

        self::saveLog("Socket 方式发送\r\n");

        fwrite($fp, $out);
        fclose($fp);
        self::saveLog("请求结束\r\n");
    }

    /**
     * Curl 请求
     * @param $url
     */
    public static function curl($url)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 信任任何证书
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, self::$_timeout);
        
        self::saveLog("Curl 方式发送\r\n");
        curl_exec($curl);
        curl_close($curl);
        self::saveLog("请求结束\r\n");
    }

    /**
     * file_get_contents 请求
     * @param $url
     */
    public static function fgc($url)
    {
        $opts = array(  
          'http'=>array(  
            'method' => "GET",
            'timeout' => self::$_timeout
          )
        );
        $cxContext = stream_context_create($opts);
        
        self::saveLog("file_get_contents 方式发送\r\n");
        $sFile = file_get_contents($url, false, $cxContext);
        self::saveLog("请求结束\r\n");
    }

    /**
     * 检测 适配器
     * @return string
     */
    public static function isAvailable()
    {
        function_exists('curl_version') && (self::$_adapter = 'Curl');
        false == self::$_adapter && function_exists('file_get_contents') && (self::$_adapter = 'fgc');
        
        return self::$_adapter;
    }

    /**
     * 检测 是否可写
     * @param $file
     * @return bool
     */
    public static function isWritable($file)
    {
        if (is_dir($file)) {
            $dir = $file;
            if ($fp = @fopen("$dir/check_writable", 'w')) {
                @fclose($fp);
                @unlink("$dir/check_writable");
                $writeable = true;
            } else {
                $writeable = false;
            }
        } else {
            if ($fp = @fopen($file, 'a+')) {
                @fclose($fp);
                $writeable = true;
            } else {
                $writeable = false;
            }
        }

        return $writeable;
    }

    /**
     * 写入记录
     * @param $content
     * @return bool
     */
    public static function saveLog($content)
    {
        if (!self::$_isMailLog) {
            return false;
        }

        file_put_contents(dirname(__FILE__) . '/log/mailer_log.txt', $content, FILE_APPEND);
    }
}
