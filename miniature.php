<?php
/*
Plugin Name: Miniature
Plugin URI: http://jeeker.net/projects/miniature/
Description: Generate thumbnails from post and display them.
Author: JinnLynn
Version: 0.1
Author URI: http://jeeker.net/
*/

/**
 * 插件版本
 *
 * @since 0.1
 */
define('MINIATURE_VERSION', '0.1');

/**
 * Miniature
 * 日志缩略图处理类
 *
 * @author JinnLynn
 * @link http://jeeker.net/projects/miniature/
 */
class Miniature {
    /**
     * 默认配置
     *
     * @var array
     */
    private $DefaultOptions = array( 'type'               => 'jpg',
                                     'width'              => 60,
                                     'height'             => 60,
                                     'src_width'          => 200,
                                     'src_height'         => 200,
                                     'cache_folder'       => 'wp-content/miniatures',
                                     'widget_title'       => 'Miniature',
                                     'display_order'      => 'random',  //random recent
                                     'display_line_wrap'  => 1, // 1 0
                                     'display_limit'      => 12,
                                     'display_row'        => 3,
                                     'display_col'        => 3,
                                     'display_format'     => '<a href="%PostURL%" title="%PostTitle%"><img src="%ThumbURL%" alt="%PostTitle%"></a>',
                                     'display_title'      => '<h3>Miniature</h3>',
                                     'display_blank_text' => 'No thumbnail found.',
                                     'display_css'        => "#Miniature {padding:0; margin:0;}\n#Miniature a img { background: #fff; margin:2px; padding: 2px; border: 1px solid #ccc;}\n#Miniature a:hover img { border: 1px solid #333;}");

    /**
     * 当前配置
     *
     * @var array
     */
    private $Options;
        
    /**
     * 缩略图文件夹路径
     *
     * @var array
     */
    private $ThumbFolder;

    /**
     * 构造函数 初始化
     *
     * @since 0.1
     */
    function __construct() {
        $this->ParseOptions();
        $this->InitWidget();
        $this->Schedules();
        add_action('publish_post', array(&$this, 'CreateThumbSchedule'));
        add_action('publish_page', array(&$this, 'CreateThumbSchedule'));
        add_action('future_post', array(&$this, 'CreateThumbSchedule'));
        add_action('future_page', array(&$this, 'CreateThumbSchedule'));
        add_action('delete_post', array(&$this, 'DelInvalidThumbs'));
        add_action('admin_menu', array(&$this, 'AddAdminMenu'));
        add_action('shutdown', array(&$this, 'SaveOptions'));
        
        $this->ThumbFolder = array( 'path' => $this->Options['cache_folder'],
                                    'abspath' => ABSPATH . $this->Options['cache_folder'] . '/',
                                    'url'     => get_option('siteurl') . '/' . $this->Options['cache_folder'] . '/' );

        if ($_GET['miniature_action'] == 'rebuild_thumbs')
            $this->GetSinglePostImages($_GET['post_id']);
        
        if (is_admin()) {
            wp_enqueue_script('jquery');
            //wp_enqueue_script('jquery-ui-tabs');
        }
    }
    
    /**
     * 禁用插件时的操作
     * 删除计划
     * 
     * @since 0.1
     * @todo 删除缩略图、清理配置
     */
    function Deactivate() {
        $schedules = array( 'miniature_single_thumb_build_schedule',
                            'miniature_thumbinfo_build_schedule',
                            'miniature_thumbs_rebuild_schedule',
                            'miniature_thumbs_rebuilding_schedule');
        $crons = _get_cron_array();
        if(!is_array($crons))
            return;
        foreach ($crons as $timestamp => $cronhooks) {
            foreach ($cronhooks as $hook => $keys) {
                if(!in_array($hook, $schedules))
                    continue;
                foreach ($keys as $key => $args) {
                    wp_unschedule_event($timestamp, $hook, $args['args']);
                }
            }
        }
    }

    /**
     * 添加计划
     *
     * @since 0.1
     */   
    function Schedules() {
        add_filter('cron_schedules', array(&$this, 'AddScheduleRecurrence'));
        add_action('miniature_single_thumb_build_schedule', array(&$this, 'GetSinglePostImages'));
        
        //重建缩略图信息缓存计划任务 每天一次
        add_action('miniature_thumbinfo_build_schedule', array(&$this, 'BuildThumbInfo'));
        if (!wp_next_scheduled('miniature_thumbinfo_build_schedule'))
            wp_schedule_event(time(), 'daily', 'miniature_thumbinfo_build_schedule');

        //所有日志缩略图重建开始计划任务 每周一次
        add_action('miniature_thumbs_rebuild_schedule', array(&$this, 'AutoRebuildThumbsStart'));
        add_action('miniature_thumbs_rebuilding_schedule', array(&$this, 'AutoRebuildThumbs'));
        if (!wp_next_scheduled('miniature_thumbs_rebuild_schedule'))
            wp_schedule_event(time(), 'weekly', 'miniature_thumbs_rebuild_schedule');
    }
    
    /**
     * 添加计划周期
     *
     * @since 0.1
     * @param array $schedules
     * @return array
     */
    function AddScheduleRecurrence($schedules) {
        $schedules['weekly'] = array('interval' => 604800, 'display' => 'Once Weekly');
        $schedules['momently'] = array('interval' => 600, 'display' => 'Momently(10M)');
        return $schedules;
    }
    
    /**
     * 计划是否存在
     * 不考虑参数 wp_next_scheduled会同时验证参数
     * 
     * @since 0.1
     * @param str
     * @return bool
     */
    function IsScheduleExists($schedule) {
        $crons = _get_cron_array();
        if(!is_array($crons))
            return false;
        foreach ($crons as $timestamp => $cronhooks) {
            foreach ($cronhooks as $hook => $keys) {
                if ($schedule == $hook)
                    return true;
            }
        }
        return false;
    }

    /**
     * 解析配置信息
     *
     * @since 0.1
     */
    function ParseOptions() {
        $old_options = get_option('miniature_options');
        if (is_array($old_options)) {
            $this->Options = array_merge($this->DefaultOptions, $old_options);
        } else {
            $this->Options = $this->DefaultOptions;
        }

        $this->ImageSizeLimit();
        $this->Options['type'] = $this->ThumbTypeLimit($this->Options['type']);
        $this->CacheFolderStandardization($this->Options['cache_folder']);
        
        /*
        $this->ThumbCaches = get_option('miniature_cache');
        if(empty($this->ThumbCaches) || !is_array($this->ThumbCaches))
            $this->ThumbCaches = array();
        */
    }
    
    /**
     * 更新配置信息
     *
     * @since 0.1
     */
    function UpdateOptions() {
        $post_options = $_POST;
        foreach ( $post_options as $key => $value ) {
            if (!array_key_exists( $key, $this->DefaultOptions)) {
                unset($post_options[$key]);
                continue;
            }
            if ( is_int($this->DefaultOptions[$key])) {
                intval($post_options[$key]);
                if ($post_options[$key] < 0) 
                    $post_options[$key] = 0;
            } else {
                $post_options[$key] = stripslashes(trim($post_options[$key]));
            }
        }
        $this->Options = array_merge($this->Options, $post_options);
    }
    
    /**
     * 重置配置信息
     *
     * @since 0.1
     */
    function ResetOptions() {
        $post_options = $_POST;
        foreach ( $post_options as $key => $value ) {
            if ( array_key_exists($key, $this->DefaultOptions) ) {
                $this->Options[$key] = $this->DefaultOptions[$key];
            }
        }
    }

    /**
     * 保存配置信息
     *
     */
    function SaveOptions() {
        foreach ( $this->Options as $option_key => $option_value ) {
            if ( !array_key_exists($option_key, $this->DefaultOptions) )
                unset($this->Options[$option_key]);
        }
        update_option('miniature_options', $this->Options);
    }
    
    /**
     * 检测缩略图保存文件夹
     *
     * @since 0.1
     */
    function CheckThumbFolder() {
        $thumb_folder = ABSPATH . $this->Options['cache_folder'];
        if (!is_dir($thumb_folder)) {
            if(!@mkdir($thumb_folder, 0755)) {
                $this->ShowMessage('FATAL: The directory you specified to cache the image files did not exist and I could not create it,Either create it for me or select a different directory.');
                return false;
            }
        }
        if (!is_writable($thumb_folder)) {
            $this->ShowMessage('FATAL: The directory you specified to cache the image files is not writeable from the Apache task. Either select a different directory or make the directory you specified writable by the Apache task (chmod 755 the directory).');
            return false;
        }
        return true;
    }

    /**
     * 图片大小限制
     *
     * @since 0.1
     */
    function ImageSizeLimit() {
        $this->SizeRange($this->Options['width'], 15, 1024);
        $this->SizeRange($this->Options['height'], 15, 1024);
        $this->SizeRange($this->Options['src_width'], 0);
        $this->SizeRange($this->Options['src_height'], 0);
    }

    /**
     * 尺寸范围
     *
     * @param int $para
     * @param int $min
     * @param int $max
     */
    function SizeRange(&$para, $min = 0, $max = 0) {
        if($min < 0)$min = 0;
        if($para < $min) {
            $para = $min;
        } else if ($max>0 && $para>$max) {
            $para = $max;
        }
    }

    /**
     * 缩略图类型限制
     *
     * @param str $type
     * @return str
     */
    function ThumbTypeLimit($type = '') {
        //default: GIF->PNG->JPG
        $type = strtolower($type);
        if ($type!='gif' && $type!='png' && $type!='jpg') {
            if (imagetypes() & IMG_GIF) {
                return 'gif';
            } else if (imagetypes() & IMG_PNG) {
                return 'png';
            } else {
                return 'jpg';
            }
        }
        if ( ($type=='gif' && (imagetypes() & IMG_GIF))
             || ($type=='png' && (imagetypes() & IMG_PNG))
             || $type=='jpg' ) {
            return $type;
        } else {
            return $this->ThumbTypeLimit();
        }
    }

    /**
     * 缓存目录路径标准化
     *
     * @param str $cache_folder
     */
    function CacheFolderStandardization(&$cache_folder) {
        $str_arr = split('[/\]', $cache_folder);
        $cache_folder = '';
        foreach ($str_arr as $str_sub) {
            if(!empty($str_sub))
                $cache_folder .= '/' . $str_sub;
        }
        $cache_folder = trim($cache_folder, '/');
    }

    /**
     * 获取缓存目录
     *
     * @param str $type
     * @param str $hash_str
     * @param str $ext
     * @return str
     */
    function GetCache($type, $hash_str = '', $ext = '') {

        switch ($type) {
            case 'folder':
                return $this->Options['cache_folder'];
                break;
            case 'folder_abspath':
                return ABSPATH . $this->Options['cache_folder'] . '/';
                break;
            case 'folder_url':
                return get_option('siteurl') . '/' . $this->Options['cache_folder'] . '/';
                break;
            default:
                break;
        }
    }

    /**
     * 创建单篇日志缩略图生成计划
     *
     * @since 0.1
     * @param int $post_id
     */
    function CreateThumbSchedule($post_id = 0) {
        $args = array($post_id);
        wp_schedule_single_event(time(), 'miniature_single_thumb_build_schedule', $args);
    }

    /**
     * 获取单篇日志的缩略图
     *
     * @since 0.1
     * @param int $post_id
     */
    function GetSinglePostImages($post_id = 0) {
        $post = get_post($post_id);
        if(empty($post))
            return;
        
        //$matches = "/<img(.+?)src=\"http:\/\/.*?\.yupoo\.com\/.*?\/(.*?)\"(.*?)>/i";
        //$replaces = "<img$1src=\"".get_bloginfo('home')."/local/$2\"$3>";
        //$post->post_content = preg_replace($matches,$replaces,$post->post_content);
        
        $new_thumbs = array();
        $content = apply_filters('the_content', $post->post_content);
        $pattern = "/<img.*?src=[\"\'](.*?)[\"\'].*?>/i";
        preg_match_all($pattern, $content, $matches);
        for ($i=0; $i< count($matches[0]); $i++) {
            $image = $matches[1][$i];
            if ( !empty($image) ) {
                $thumb_url = $this->GetSingleThumb('img=' . $image .'&post_id=' . $post_id, $filename);
                if (!empty($thumb_url)) {
                    $new_thumbs[] = $filename;
                }
            }
        }
        $this->DelInvalidThumbs($post_id, $new_thumbs);
    }
    
    /**
     * 删除无效的缩略图
     *
     * @param int $post_id
     * @param array $new_thumbs
     */
    function DelInvalidThumbs($post_id = 0, $new_thumbs = array()) {
        if (!is_array($new_thumbs)) 
            return; 
        if( !@chdir($this->GetCache('folder_abspath')) )
            return;
        foreach(glob($post_id . '_*.*') as $filename) {
            if (!in_array($filename, $new_thumbs))
                @unlink($filename);
        }
        $this->BuildThumbInfo();
    }

    /**
     * 获取缩略图
     *
     * @param array $args
     * @param str $cache_filename
     * @return str
     */
    function GetSingleThumb($args='', &$cache_filename = NULL) {
        $defaults = $this->Options;
        $defaults['post_id'] = 0;
        $defaults['img'] = '';
        $defaults['sizelimit'] = 1;
        $args = wp_parse_args($args, $defaults);
        $args['cache_folder'] = $this->Options['cache_folder'];
        $args['type'] = $this->ThumbTypeLimit($args['type']);;
        $hash = $this->CRC32Hash( $args['img']
                                  .$args['type']
                                  .$args['width']
                                  .$args['height'] );
        $cache_filename = $args['post_id'] . '-' .  $hash . '.' .$args['type'];
        $abs_cache_filename = $this->GetCache('folder_abspath') . $cache_filename;
        if(file_exists($abs_cache_filename)) {
            $thumb_url = $this->GetCache('folder_url') .$cache_filename;
        } else {
            $image = $this->CreateThumb($args, $abs_cache_filename);
            if ($image)
                $thumb_url = $this->GetCache('folder_url') .$cache_filename;
        }
        return $thumb_url;
    }

    /**
     * 创建缩略图
     *
     * @param array $args
     * @param str $abs_cache_filename
     * @return bool
     */
    function CreateThumb($args='', $abs_cache_filename) {
        $img_url = $args['img'];
        
        if (!is_dir(ABSPATH . $this->Options['cache_folder']))
            $this->CheckThumbFolder();
        // Fetch Source Image
        $src_img = $this->FetchImage($img_url);
        if(!$src_img)
            return false;
        list($src_width, $src_height, $src_type) = @getimagesize($src_img);

        //Source Image Size Limit
        if( ($src_width<$this->Options['src_width'] || $src_height<$this->Options['src_height']) && $args['post_id']) 
            return $this->DelTempFile($src_img);

        if ($src_type == 1) {
            $source = @imagecreatefromgif($src_img);
        } else if ($src_type == 2) {
            $source = @imagecreatefromjpeg($src_img);
        } else if ($src_type == 3) {
            $source = @imagecreatefrompng($src_img);
        }
        if(!$source)
            return $this->DelTempFile($src_img);

        $this->ZoomArea($args['width'], $args['height'], $src_width, $src_height, $dis_x, $dis_y, $dis_width, $dis_height);

        // Resample
        $thumb = @imagecreatetruecolor($args['width'], $args['height']);
        @imagecopyresampled($thumb, $source, 0, 0, $dis_x, $dis_y, $args['width'], $args['height'], $dis_width, $dis_height);
        //imagecopyresized($thumb, $source, 0, 0, $dis_x, $dis_y, $thumb_width, $thumb_height, $dis_width, $dis_height);

        // Output
        if($args['type'] == 'png') {
            $imgge_create = @imagepng($thumb, $abs_cache_filename);
        } else if ($args['type'] == 'jpg') {
            $imgge_create = @imagejpeg($thumb, $abs_cache_filename, 100);
        } else {
            $imgge_create = @imagegif($thumb, $abs_cache_filename);
        }
        @imagedestroy($source);
        @imagedestroy($thumb);
        $this->DelTempFile($src_img);
        return $imgge_create;
    }
    
    /**
     * 获取原图
     *
     * @since 0.1
     * @param str $img_url
     * @return false | str 保存至本地的临时文件路径
     */
    function FetchImage($img_url) {
        if (!class_exists('WP_Http')) 
            return false;
        $result = wp_remote_request($img_url);
        if (is_wp_error($result) || $result['response']['code'] != 200)
            return false;
        $filename = $this->GetCache('folder_abspath') . $this->CRC32Hash($img_url) . '.tmp';
        $fp = @fopen($filename, 'w');
        @fwrite($fp, $result['body']);
        @fclose($fp);
        return $filename;
    }
    
    /**
     * 由字符串生成标准八位CRC码
     *
     * @param str $str
     * @return str
     */
    function CRC32Hash($str) {
        $hash = dechex(crc32($str));
        return zeroise($hash, 8);
    }

    /**
     * 原图生成缩略图时的范围
     *
     * @param int $thumb_width
     * @param int $thumb_height
     * @param int $src_width
     * @param int $src_height
     * @param int $dis_x
     * @param int $dis_y
     * @param int $dis_width
     * @param int $dis_height
     */
    function ZoomArea($thumb_width, $thumb_height, $src_width, $src_height, &$dis_x, &$dis_y, &$dis_width, &$dis_height) {
        $ratio = $thumb_width / $thumb_height;
        $tempheight = $src_width / $ratio;
        if($src_height > $tempheight) {
            $dis_width = $src_width;
            $dis_height = $tempheight;
            $dis_x = 0;
            $dis_y = intval(($src_height-$dis_height) / 2);
        } else if ($src_height < $tempheight) {
            $dis_width = $src_height * $ratio;
            $dis_height = $src_height;
            $dis_x = intval(($src_width-$dis_width) / 2);
            $dis_y = 0;
        } else {
            $dis_width = $src_width;
            $dis_height = $src_height;
            $dis_x = 0;
            $dis_y = 0;
        }
    }

    /**
     * 获取图像类型 根据getimagesize的type索引
     *
     * @param unknown_type $index
     * @return unknown
     */
    function GetImageType($index) {
        $image_type = array( 1 => 'GIF', 2 => 'JPG', 3 => 'PNG');
        if(array_key_exists($index,$image_type)) {
            return $image_type[$index];
        } else {
            return 'Unknown';
        }
    }
    
    /**
     * 删除临时文件
     *
     * @param str $temp_file
     */
    function DelTempFile($temp_file) {
        $url_part = parse_url($temp_file);
        if ( (empty($url_part['scheme']) || empty($url_part['host'])) && file_exists($temp_file) )
            @unlink($temp_file);
    }
    
    /**
     * 清除缓存图片
     *
     * @since 0.1
     * @param str $type $type='all' 清除全部 否则仅清除无效的缩略图
     */
    function ClearThumbs($type = '') {
        if( !@chdir(ABSPATH . $this->Options['cache_folder']) ) {
            $this->ShowMessage('FATAL: could not find cache folder!');
            return;
        }
        foreach (glob('*.*') as $filename) {
            if ($type == 'all') {
                @unlink($filename);
            } else {
                $path_info = pathinfo($filename);
                if ( strcasecmp( $path_info['extension'], $this->Options['type'] ) != 0 ) {
                    @unlink($filename);
                } else {
                    list($width, $height) = @getimagesize($filename);
                    if ($width!=$this->Options['width'] || $height!=$this->Options['height'])
                        @unlink($filename);
                }
            }
        }
        $this->BuildThumbInfo();
    }

    /**
     * 建立缩略图信息缓存
     *
     * @since 0.1
     */
    function BuildThumbInfo() {
        if( !@chdir(ABSPATH . $this->Options['cache_folder']) ) {
            $this->ShowMessage('FATAL: Could not find cache folder!');
            return;
        }
        $cache = array();
        foreach (glob('*.' . $this->Options['type']) as $filename) {
            list($width, $height) = @getimagesize($filename);
            if($width!=$this->Options['width'] || $height!=$this->Options['height'])
                continue;
            list($post_id, $hash, $type) = split('[-.]', $filename);
            if ( !empty($post_id) && !empty($hash) && !empty($type) ) {
                $post_id = intval($post_id);
                if (get_post_status($post_id) != 'publish')
                    continue;
                $cache[$post_id][] = $hash;
            }
        }
        uksort($cache, array($this, "SortByPostdate"));
        update_option('miniature_cache', $cache);
        wp_clear_scheduled_hook('miniature_thumbinfo_build_schedule');
        wp_schedule_event(time() + 86400, 'daily', 'miniature_thumbinfo_build_schedule');
    }

    /**
     * 按日志的发布时间排序
     *
     * @param int $post_id_a
     * @param int $post_id_b
     * @return int
     */
    function SortByPostdate($post_id_a, $post_id_b) {
        $post_a = get_post($post_id_a);
        $post_b = get_post($post_id_b);
        return ($post_a->post_date > $post_b->post_date) ? -1 : 1;
    }
    
    /**
     * 开始自动重建缩略图
     * 每10篇日志分为一组，间隔2分钟进行重建
     * 
     * @since 0.1
     */
    function AutoRebuildThumbsStart() {
        global $wpdb;
        $sql = "
                SELECT ID
                FROM $wpdb->posts 
                WHERE post_status = 'publish' AND (post_type = 'post' OR post_type = 'page')
                ORDER BY post_date DESC
               ";
        $results = $wpdb->get_results($sql, OBJECT_K);
        $results = array_chunk(array_keys($results), 10);
        $next_time = time();
        foreach ($results as $result) {
            wp_schedule_single_event($next_time, 'miniature_thumbs_rebuilding_schedule', array($result));
            $next_time = $next_time + 90; //间隔1分半钟
        }
    }
    
    /**
     * 缩略图重建
     *
     * @param arrray $ids 日志ID数组
     */
    function AutoRebuildThumbs($ids) {
        if (!is_array($ids))
            return;
        foreach ($ids as $id) {
            $this->GetSinglePostImages($id);
        }
        $this->BuildThumbInfo();
    }

    /**
     * 计算缩略图大小
     *
     * @return str
     */
    function CalculateThumbSize() {
        if (!@chdir(ABSPATH . $this->Options['cache_folder']))
            return 'unknown!';
        $cache_size = 0;
        foreach (glob('*.*') as $filename) {
            $cache_size += filesize($filename);
        }
        //return round($cache_size / 1024, 2) . 'KB';
        return strtoupper(size_format($cache_size, 2));
    }

    /**
     * 初始化Widget
     *
     * @since 0.1
     */
    function InitWidget() {
        $widget_ops = array('classname' => 'widget_miniature', 'description' => __( "Show thumbnail by Miniature.") );
        wp_register_sidebar_widget('miniature', __('Miniature'), array($this, 'ShowWidget'), $widget_ops);
        wp_register_widget_control('miniature', __('Miniature'), array($this, 'ControlWidget'));
    }
    
    /**
     * 控制Widget
     * 
     * @since 0.1
     */
    function ControlWidget() {
    	if($_POST['miniature_widget_submit'] == 1)
    		$this->Options['widget_title'] = strip_tags(stripslashes($_POST['miniature_widget_title']));
    	$widget_title = $this->Options['widget_title'];
    	echo '
			<p><label for="miniature_widget_title">Title: <input class="widefat" id="miniature_widget_title" name="miniature_widget_title" type="text" value="' . $widget_title . '" /></label></p>
			<input type="hidden" id="miniature_widget_submit" name="miniature_widget_submit" value="1" />
    	';
    }
    
    /**
     * 显示Widget
     *
     * @since 0.1
     * @param array $widget_args
     * @param int $number
     */
    function ShowWidget( $widget_args, $number = 1 ) {
        extract($widget_args);
        $title = $this->Options['widget_title'];
        echo $before_widget;
        
        echo $before_title . $title . $after_title;
            $this->ShowThumbs('title=');
        echo $after_widget;
    }
    
    /**
     * 显示缩略图列表
     *
     * @since 0.1
     * @param str $args
     */
    function ShowThumbs($args = '') {
        echo $this->GetThumbs($args);
    }
    
    /**
     * 获取缩略图列表
     *
     * @since 0.1
     * @param str $args
     * @return str
     */    
    function GetThumbs($args = '') {
        $defaults = array( 'order'      => $this->Options['display_order'],
                           'line_wrap'  => $this->Options['display_line_wrap'],
                           'limit'      => $this->Options['display_limit'],
                           'row'        => $this->Options['display_row'],
                           'col'        => $this->Options['display_col'],
                           'format'     => $this->Options['display_format'],
                           'title'      => $this->Options['display_title'],
                           'blank_text' => $this->Options['display_blank_text'],
                           'css'        => $this->Options['display_css'] );
        extract(wp_parse_args($args, $defaults));

        $output = "\n" . '<!-- Generated by Miniature ' . MINIATURE_VERSION . ' - http://jeeker.net/projects/miniature/ -->' . "\n";
        if(!empty($css)) {
            $output .= '<style type="text/css">' . "\n";
            $output .= '<!--' . "\n";
            $output .= $css . "\n";
            $output .= '-->' . "\n";
            $output .= '</style>'. "\n";
        }
        
        if (!empty($title)) 
           $output .=  $title . "\n";
           
        $output .= '<div id="miniature" class="miniature">' . "\n"; 
           
        $thumbs_cache = get_option('miniature_cache');
        if (!empty($thumbs_cache) && is_array($thumbs_cache)) {
            if ( $order != 'recent')
                uksort($thumbs_cache, array(&$this, 'RanCmp'));
    
            ($line_wrap) ? ($show_num = $limit) : ($show_num = $row * $col);
            $thumbs_cache = array_slice($thumbs_cache, 0, $show_num, true);
            $show_thumbs_count = 0;
            foreach ($thumbs_cache as $post_id => $hash) {
                $post = get_post($post_id);
                if (empty($post) || !is_object($post) || $post->post_status!='publish')
                    continue;

                $post_url = get_permalink($post->ID);
                $post_title = $post->post_title;
                $thumb_url = $this->GetCache('folder_url') . $post_id . '-' . $hash[array_rand($hash)] . '.' . $this->Options['type'];
                
                $replace = array( '%PostURL%'   => $post_url,
                                  '%PostTitle%' => $post_title,
                                  '%ThumbURL%'  => $thumb_url );
                
                $output .= "\t" . strtr($format, $replace);
                $show_thumbs_count++;
                if ($line_wrap == 0 && ($show_thumbs_count % intval($col) == 0))
                    $output .= '<br />';
                $output .= "\n";
            }
        } else {
            $output .= $blank_text;
        }

        $output .= "\n" . '</div>' . "\n";
        return $output;
    }

    /**
     * 随机数
     *
     * @since 0.1
     * @return str
     */
    function RanCmp() {
        return mt_rand(-5, 5);
    }
    
    /**
     * 在后台管理显示信息
     *
     * @since 0.1
     * @param str $msg
     */
    function ShowMessage($msg) {
        if (empty($msg))
            return;
        $msg = '<div class="updated fade"><p><strong>' . $msg . '</strong></p></div>';
        add_action('admin_notices', create_function( '', "echo '$msg';" ) );
    }

    /**
     * 添加菜单
     *
     * @since 0.1
     */
    function AddAdminMenu() {
        add_options_page('Miniature', 'Miniature', 'manage_options', 'miniature_admin_options_page', array(&$this, 'AdminPage'));
    }

    /**
     * 后台管理页面内容
     *
     * @since 0.1
     */
    function AdminPage() {
        global $wpdb;

        wp_enqueue_script("jquery-ui-tabs");
        
        if ( isset($_POST['update_options']) ) {
            $this->UpdateOptions();
            $this->ImageSizeLimit();
            $this->CacheFolderStandardization($this->Options['cache_folder']);
        } else if ( isset($_POST['update_display']) ) {
            $this->UpdateOptions();
        } else if ( isset($_POST['reset_options']) || isset($_POST['reset_display']) ) {
            $this->ResetOptions();
        } else if (isset($_POST['rebuild_thumbs_info'])) {
            $this->BuildThumbInfo();
        } else if (isset($_POST['clear_invalid_thumbs'])) {
            $this->ClearThumbs();
        } else if (isset($_POST['clear_all_thumbs'])) {
            $this->ClearThumbs('all');
        }

        $this->CheckThumbFolder();
        
        //Preview
        $example_img = WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__)) .'/example.jpg';
        $example_img_abspath = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__)) .'/example.jpg';
        list($o_width, $o_height, $o_type, $o_attr) = @getimagesize($example_img_abspath);
        $o_type = $this->GetImageType($o_type);
        $original_image = '<img src="' . $example_img . '" ' . $o_attr . ' alt="example image">';
        $original_info = 'Type:' . $o_type . '&nbsp;&nbsp;Width:' . $o_width . 'px&nbsp;&nbsp;Height:' . $o_height .'px&nbsp;&nbsp;Size:' . round(@filesize(dirname(__FILE__).'/example.jpg') / 1024, 2) .'KB';

        $args = 'img=' . $example_img;
        $thumb = $this->GetSingleThumb($args, $cache_filename);
        list($t_width, $t_height, $t_type, $t_attr) = @getimagesize($this->GetCache('folder_abspath') . $cache_filename);
        $t_type = $this->GetImageType($t_type);
        $output_image = '<img src="' . $thumb . '" ' . $t_attr . ' alt="example thumb">';
        $output_info = 'Type:' . $t_type . '&nbsp;&nbsp;Width:' . $t_width . 'px&nbsp;&nbsp;Height:' . $t_height .'px&nbsp;&nbsp;Size:' . round(@filesize(ABSPATH . $this->Options['cache_folder'] . '/' . $cache_filename) / 1024, 2) .'KB';

        //Graphic Format
        $format_select = '<select name="type">';
        if (imagetypes() & IMG_GIF) {
            ($this->Options['type'] == 'gif') ? ($format_select .= '<option value="gif" selected="selected">GIF</option>') : ($format_select .= '<option value="gif">GIF</option>');
        } else {
            $format_error = 'GIF';
        }
        if (imagetypes() & IMG_PNG) {
            ($this->Options['type'] == 'png') ? ($format_select .= '<option value="png" selected="selected">PNG</option>') : ($format_select .= '<option value="png">PNG</option>');
        if(!empty($format_error))
            $format_error .= ' is ';
        } else {
            (!empty($format_error)) ? ($format_error .= ' and PNG are ') : ($format_error .= 'PNG is ');
        }
        ($this->Options['type'] == 'jpg') ? ($format_select .= '<option value="jpg" selected="selected">JPG</option>') : ($format_select .= '<option value="jpg">JPG</option>');
            $format_select .= '</select>';
        if(!empty($format_error))
            $format_error = 'FATAL: ' . $format_error . 'not available... requires GD version 2.0.28 or higher.';

        //Rebuild Cache 
        $next_thumbs_info_rebuild_time = date('Y-m-d G:i:s', wp_next_scheduled('miniature_thumbinfo_build_schedule'));
        $next_thumbs_rebuild_time = ($this->IsScheduleExists('miniature_thumbs_rebuilding_schedule')) ? ('&nbsp;&nbsp;Rebuilding...') : (date('Y-m-d G:i:s', wp_next_scheduled('miniature_thumbs_rebuild_schedule')));
        $sql = "
                SELECT ID
                FROM $wpdb->posts
                WHERE post_status='publish' AND (post_type='post' OR post_type='page')
                ORDER BY post_date DESC";
        $results = $wpdb->get_results($sql);
        foreach ($results as $post) {
            if (!empty($rebuild_list))
                $rebuild_list .= ',';
            $rebuild_list .= $post->ID;
        }
        echo '
<style type="text/css">
    <!--
    #process_status_info {}
    #rebuild {margin:10px 25px 5px 25px;}
    #progress_message {line-height: 165%;padding:0;color:#999;}
    #rebuildloader {border:0px solid red;height:1px;width:1px;}

    #meterbox {
        overflow: hidden;
        height: 18px;
        margin-bottom: 18px;
        background-color: #f7f7f7;
        background-image: -moz-linear-gradient(top, #f5f5f5, #f9f9f9);
        background-image: -ms-linear-gradient(top, #f5f5f5, #f9f9f9);
        background-image: -webkit-gradient(linear, 0 0, 0 100%, from(#f5f5f5), to(#f9f9f9));
        background-image: -webkit-linear-gradient(top, #f5f5f5, #f9f9f9);
        background-image: -o-linear-gradient(top, #f5f5f5, #f9f9f9);
        background-image: linear-gradient(top, #f5f5f5, #f9f9f9);
        background-repeat: repeat-x;
        filter: progid:DXImageTransform.Microsoft.gradient(startColorstr="#f5f5f5", endColorstr="#f9f9f9", GradientType=0);
        -webkit-box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1);
        -moz-box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1);
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.1);
        -webkit-border-radius: 4px;
        -moz-border-radius: 4px;
        border-radius: 4px;
    }
    #meter {
        width: 0%;
        height: 18px;
        color: #ffffff;
        font-size: 12px;
        text-align: center;
        text-shadow: 0 -1px 0 rgba(0, 0, 0, 0.25);
        background-repeat: repeat-x;
        filter: progid:DXImageTransform.Microsoft.gradient(startColorstr="#149bdf", endColorstr="#0480be", GradientType=0);
        -webkit-box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.15);
        -moz-box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.15);
        box-shadow: inset 0 -1px 0 rgba(0, 0, 0, 0.15);
        -webkit-box-sizing: border-box;
        -moz-box-sizing: border-box;
        -ms-box-sizing: border-box;
        box-sizing: border-box;
        -webkit-transition: width 0.6s ease;
        -moz-transition: width 0.6s ease;
        -ms-transition: width 0.6s ease;
        -o-transition: width 0.6s ease;
        transition: width 0.6s ease;

        background-color: #149bdf;
        background-image: -webkit-gradient(linear, 0 100%, 100% 0, color-stop(0.25, rgba(255, 255, 255, 0.15)), color-stop(0.25, transparent), color-stop(0.5, transparent), color-stop(0.5, rgba(255, 255, 255, 0.15)), color-stop(0.75, rgba(255, 255, 255, 0.15)), color-stop(0.75, transparent), to(transparent));
        background-image: -webkit-linear-gradient(-45deg, rgba(255, 255, 255, 0.15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.15) 50%, rgba(255, 255, 255, 0.15) 75%, transparent 75%, transparent);
        background-image: -moz-linear-gradient(-45deg, rgba(255, 255, 255, 0.15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.15) 50%, rgba(255, 255, 255, 0.15) 75%, transparent 75%, transparent);
        background-image: -ms-linear-gradient(-45deg, rgba(255, 255, 255, 0.15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.15) 50%, rgba(255, 255, 255, 0.15) 75%, transparent 75%, transparent);
        background-image: -o-linear-gradient(-45deg, rgba(255, 255, 255, 0.15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.15) 50%, rgba(255, 255, 255, 0.15) 75%, transparent 75%, transparent);
        background-image: linear-gradient(-45deg, rgba(255, 255, 255, 0.15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, 0.15) 50%, rgba(255, 255, 255, 0.15) 75%, transparent 75%, transparent);
        -webkit-background-size: 40px 40px;
        -moz-background-size: 40px 40px;
        -o-background-size: 40px 40px;
        background-size: 40px 40px;
    }

    em {font:normal 10px verdana; color: gray;}
    form{margin:0;padding:0;}
    /*#MiniatureOptions div {margin:0;height:100%;width:auto;border:0;padding:0;overflow: hidden;}*/
    
    .tabs-hide{display:none;}
    .ui-state-active {text-decoration: none;}
    .ui-state-active a{color:#464646;}
    
    .mini_footer{padding:10px; text-align:center;font-size:10px;}
    -->
</style>

<script type="text/javascript">
    (function($) {
        $(function() {
            var site_url = "' . get_option('siteurl') . '/";
            var dot = "";
            var message = "";
            var buttons = $("#MiniatureOptions :submit, #MiniatureOptions :button");
            
            var rebuild_list = new Array(' . $rebuild_list . ');
            var cur_rebuild_index = 0;
            var rebuild_over = false;
            
            $("div#rebuild").hide();
            
            $("#reset_options, #reset_display").click(function() {
                return confirm(\'Do you really want to restore the default options?\');
            });
            
            $("#clear_all_thumbs").click(function() {
                return confirm(\'You are about to delete all cache. \n  Cancel to stop, OK to delete.\');
            });

            $("#uninstall").click(function() {
                return confirm(\'Do you really want to uninstall this plugin?\');
            });
            
            $("#rebuild_str").click(function() {
                $("#rebuild_thumbs").trigger("click");
                return false;
            });
            
            $("#rebuild_thumbs").click(function() {
                buttons.attr("disabled", true);
                $("div#rebuild").slideDown();
                rebuild_over = false;
                cur_rebuild_index = 0;
                message = "Post " + rebuild_list[cur_rebuild_index] + " is rebuilding";
                Rebuilding();
                $.get(site_url, {miniature_action:"rebuild_thumbs", post_id:rebuild_list[cur_rebuild_index], rand:Math.random()}, RebuildStep);
            });
            
            $("#rebuild_thumbs").ajaxComplete(function(request, settings) {
                if (rebuild_over) return;
                $.get(site_url, {miniature_action:"rebuild_thumbs", post_id:rebuild_list[cur_rebuild_index], rand:Math.random()}, RebuildStep);
            });
            
            $("#rebuild_thumbs").ajaxError(function(event,request, settings){
                rebuild_over = true;
                buttons.attr("disabled", "");
                $("#rebuild #progress_message").html("Something is wrong,please rebuild later.");
            }); 
           
            function RebuildStep(data) {
                cur_rebuild_index++;
                if(cur_rebuild_index >= rebuild_list.length - 1) {
                    rebuild_over = true;
                    $("#rebuild #meter").css("width", "100%").html("100%");
                    $("#rebuild #progress_message").html("Cache rebuild completely!");
                    buttons.attr("disabled", false);
                    return;
                }
                //cur_rebuild_index已加1
                persent = Math.round(cur_rebuild_index / rebuild_list.length * 100) + "%";
                $("#rebuild #meter").css("width", persent).html(persent);
                message = "Post " + rebuild_list[cur_rebuild_index] + " is rebuilding";
                //$("#rebuild #progress_message").html("Something is wrong,please rebuild later.");
            }
            
            function Rebuilding() {
                if(rebuild_over) return;
                if (dot.length < 4 ) dot = dot + ".";
                if (dot.length > 3 ) dot = "";
                $("#rebuild #progress_message").html(message + dot);
                window.setTimeout(Rebuilding, 250);
            }

             $("#MiniatureOptions").tabs({ fx: { opacity: "toggle" } });
        });

    }) (jQuery);
</script>
<div class="wrap">
    <h2>Miniature Options</h2>
    <p style="display:;">Settings for the Miniature plugin. Visit <a href="http://jeeker.net/projects/miniature/">Jeeker</a> for usage information and project news.</p>
    <div id="MiniatureOptions">
        <ul class="subsubsub"><li><a href="#general">General</a></li>|<li><a href="#thumbs">Thumbs</a></li>|<li><a href="#display">Display</a></li></ul>
        <br style="clear:both;" />
        <div id="general">
            <form id="miniature" name="miniature" method="post">
                  <table class="form-table">
                     <tr valign="top">
                        <th scope="row">Preview</th>
                        <td style="">
                            <div style="margin:0;padding:0 50px 0 0;width:300px;float:left;text-align:center;"><p>Original<br /><em>' . $original_info . '</em><br />' . $original_image . '</p></div>
                            <div style="margin:0;padding:0;float:left;text-align:center;"><p>Output<br /><em>' . $output_info . '</em><br />' . $output_image . '</p></div> 
                            <br style="clear:both;" />This is a preview of your current settings. Save your settings to update the preview. 
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Graphic Format : </th>
                        <td>' . $format_select . '<br />Default : ' . strtoupper($this->DefaultOptions['type']) . '<br /><span style="color:#f00;"><strong>' . $format_error . '</strong></span></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Cache Folder : </th>
                        <td>' . ABSPATH . '<input type="text" name="cache_folder" id="cache_folder" value="' . $this->Options['cache_folder'] . '" size="40" /><br />Default : ' . ABSPATH . $this->DefaultOptions['cache_folder'] . '<br /><span style="color:#f00;"><strong>' . $cache_folder_error . '</strong></span></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Source Image Size Limit : </th>
                        <td><input type="text" name="src_width" id="width" value="' . $this->Options['src_width'] . '" size="2" /> * <input type="text" name="src_height" id="height" value="' . $this->Options['src_height'] . '" size="2" /><br />Width * Height Default : ' . $this->DefaultOptions['src_width'] . ' * ' . $this->DefaultOptions['src_height'] . '</td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Output Size : </th>
                        <td><input type="text" name="width" id="width" value="' . $this->Options['width'] . '" size="2" /> * <input type="text" name="height" id="height" value="' . $this->Options['height'] . '" size="2" /><br />Width * Height Between 15 and 1024, Default : ' . $this->DefaultOptions['width'] . ' * ' . $this->DefaultOptions['height'] . '</td>
                    </tr>            
                </table>
                <p><input class="button" type="submit" name="update_options" id="update_options" value="Update Options" />
                   <input class="button" type="submit" name="reset_options" id="reset_options" value="Reset Options" /></p>
            </form>
        </div>
         
        <div id="thumbs">
            <form method="post" name="thumbs" id="thumbs">
                Please <a href="#" id="rebuild_str">rebuild thumbs</a> manually when you save the new options.
                <table class="form-table">
                    <tr valign="top">
                        <td>
                            <div style="padding-left: 20px">
                                <strong>All Thumbs Size : </strong>' . $this->CalculateThumbSize() . '<br />
                                <strong>Next Thumbs Reuild Time (Once weekly): </strong>' . $next_thumbs_rebuild_time . '<br />
                                <strong>Next Thumbs Info Rebuild Time (Once daily): </strong>' . $next_thumbs_info_rebuild_time . '
                            </div>
                            <div id="rebuild">
                                <em>Cache is being builded, this may take several minutes.</em><br />
                                <div id="meterbox"><div id="meter">0%</div></div>
                                <div id="progress_message">Wait to Rebuild Cache...</div>
                            </div>
                            <iframe id="rebuildloader" src="about:blank" border="0" frameborder="0"></iframe>
                        </td>
                    </tr>
                </table>
                <p>
                    <input class="button" id="rebuild_thumbs" name="rebuild" type="button" value="Rebuild Thumbs" />&nbsp;&nbsp;
                    <input class="button" id="rebuild_thumbs_info" name="rebuild_thumbs_info" type="submit" value="Rebuild Thumbs Info" />&nbsp;&nbsp;
                    <input class="button" id="clear_invalid_thumbs" name="clear_invalid_thumbs" type="submit" value="Clear Invalid Thumbs" />&nbsp;&nbsp;
                    <input class="button delete" id="clear_all_thumbs" name="clear_all_thumbs" type="submit" value="Delete All Thumbs" />
                </p>
            </form>
        </div>

        <div id="display">
            <form id="display_form" method="post">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Display Order : </th>
                        <td>
                            <select id="display_order" name="display_order">
                                <option value="random"' . ($this->Options['display_order'] == 'random' ? ' selected="selected"' : '') . ' >Random</option>
                                <option value="recent"' . ($this->Options['display_order'] == 'recent' ? ' selected="selected"' : '') . ' >Recent</option>
                            </select>
                            <br />Default : Random
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Display Limit : </th>
                        <td>
                            <input name="display_line_wrap" type="radio" value="1"' . ($this->Options['display_line_wrap'] == 1 ? ' checked="checked"' : '') .' /><input type="text" name="display_limit" id="display_limit" value="' . $this->Options['display_limit'] . '" size="4" />
                            <br />Line Wrap Default: 12<br />
                            <input name="display_line_wrap" type="radio" value="0"' . ($this->Options['display_line_wrap'] == 0 ? ' checked="checked"' : '') .' /><input type="text" name="display_row" id="display_row" value="' . $this->Options['display_row'] . '" size="2" /> * <input type="text" name="display_col" id="display_col" value="' . $this->Options['display_col'] . '" size="2" />
                            <br />Row * Column Default: ' . $this->DefaultOptions['display_row'] . ' * ' . $this->DefaultOptions['display_col'] . '
                        </td>
                    </tr>
                   <tr valign="top">
                        <th scope="row">Display Format : </th>
                        <td>
                            <input type="text" name="display_format" id="display_format" style="width: 90%;" value="' . htmlspecialchars($this->Options['display_format']) . '" size="40" />
                            <br />Default : ' . htmlspecialchars($this->DefaultOptions['display_format']) . '
                            <br /><a href="" target="_blank">Find More Markers.</a>
                        </td>
                    </tr>
                   <tr valign="top">
                        <th scope="row">Display Title : </th>
                        <td>
                            <input type="text" name="display_title" id="display_title" style="width: 400px;" value="' . htmlspecialchars($this->Options['display_title']) . '" size="40" />
                            <br />Default : ' . htmlspecialchars($this->DefaultOptions['display_title']) . '
                        </td>
                    </tr>
                   <tr valign="top">
                        <th scope="row">Text to display if no thumbnail found : </th>
                        <td>
                            <input type="text" name="display_blank_text" id="display_blank_text" style="width: 400px;" value="' . htmlspecialchars($this->Options['display_blank_text']) . '" size="40" />
                            <br />Default : ' . htmlspecialchars($this->DefaultOptions['display_blank_text']) . '
                        </td>
                    </tr>                    
                    <tr valign="top">
                        <th scope="row">CSS</th>
                        <td><textarea name="display_css" id="display_css" style="width: 90%;" rows="5" cols="50">' . $this->Options['display_css'] . '</textarea></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Preview</th>
                        <td>' . $this->GetThumbs() . '</td>
                    </tr>                  
                </table>
                <p><input class="button" type="submit" name="update_display" id="update_display" value="Update Options" />
                   <input class="button" type="submit" name="reset_display" id="reset_display" value="Reset Options" /></p>
            </form>
        </div>
        
        <p class="mini_footer">&copy; Copyright 2008 <a href="http://jeeker.net/" title="JinnLynn">JinnLynn</a> | <a href="http://jeeker.net/projects/miniature/">Miniature</a> | Version ' . MINIATURE_VERSION . '</p>
    </div>
    
</div>
        ';
    }
}

/**
 * 初始化Miniature对象
 *
 * @since 0.1
 */
function JMT_Init() {
    if (!class_exists('WP_Http')) {
        $msg = '<div class="updated fade"><p><strong>Miniature can not work with this WordPress version !</strong></p></div>';
        add_action('admin_notices', create_function( '', "echo '$msg';" ) );
        return;
    }
    global $Miniature;
    $Miniature = new Miniature();
}
add_action('plugins_loaded', 'JMT_Init');

/**
 * 对象销毁时执行
 *
 * @since 0.1
 */
function JMT_Deactivate() {
    global $Miniature;
    if (is_a($Miniature, 'Miniature'))
        $Miniature->Deactivate();
}
register_deactivation_hook(__FILE__, 'JMT_Deactivate');

/**
 * 显示缩略图
 *
 * @param string $args
 * @return null
 */    
function JMT_TheThumbs($args = '') {
    global $Miniature;
    if (is_a($Miniature, 'Miniature'))
        return $Miniature->ShowThumbs($args);
}
//}

?>